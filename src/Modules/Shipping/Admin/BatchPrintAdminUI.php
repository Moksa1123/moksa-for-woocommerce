<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Admin;

use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class BatchPrintAdminUI {

	private const NONCE_ACTION = 'mo_shipping_batch_print';
	private const CAPABILITY   = 'edit_shop_orders';
	private const BULK_PREFIX  = 'mo_batchprint_';

	public static function init(): void {
		// 訂單列表 column（HPOS + classic）— 兩種模式都顯示
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'register_column' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'register_column' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_column_classic' ], 10, 2 );
		// Inline CSS 防止訂單列表新 column 跟 WC 預設 column 互擠（總計 / 來源 斷行）
		add_action( 'admin_head-woocommerce_page_wc-orders', [ __CLASS__, 'admin_columns_css' ] );
		add_action( 'admin_head-edit.php', [ __CLASS__, 'admin_columns_css' ] );

		// 列印輸出頁 — 基本模式 bulk action redirect 的目標（任何請求都註冊）
		add_action( 'wp_ajax_mo_shipping_batch_print_output', [ __CLASS__, 'render_print_output' ] );

		if ( self::is_advanced() ) {
			// 進階：工具列「<provider> 標籤」按鈕 + 彈窗（防漏印）
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
			add_action( 'admin_footer', [ __CLASS__, 'render_modal' ] );
			add_action( 'wp_ajax_mo_shipping_batch_print_list', [ __CLASS__, 'ajax_list' ] );
			add_action( 'wp_ajax_mo_shipping_batch_print_run', [ __CLASS__, 'ajax_run' ] );
		} else {
			// 基本：WooCommerce 內建批次操作下拉（每個 provider 一個動作；選哪個動作就印哪家）
			add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'register_bulk_actions' ] );
			add_filter( 'bulk_actions-edit-shop_order', [ __CLASS__, 'register_bulk_actions' ] );
			add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
			add_filter( 'handle_bulk_actions-edit-shop_order', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
			add_action( 'admin_notices', [ __CLASS__, 'bulk_notices' ] );
		}
	}

	public static function is_advanced(): bool {
		return 'yes' === get_option( 'mo_shipping_bulk_print_mode_advanced', 'no' );
	}

	public static function admin_columns_css(): void {
		?>
		<style>
		.wp-list-table .column-mo_shipping_method{width:8em;white-space:nowrap;}
		.wp-list-table .column-mo_shipping_method .mo-shipping-method{display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;}
		.wp-list-table .column-mo_shipping_label_no{width:7em;white-space:nowrap;}
		.wp-list-table .column-order_total{white-space:nowrap;}
		.wp-list-table .column-wc_actions{white-space:nowrap;}
		</style>
		<?php
	}

	public static function register_column( array $cols ): array {
		// 在 status 後插「運送方式」+「物流編號」兩欄
		$new = [];
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$new['mo_shipping_method']   = __( '運送方式', 'mo-ectools' );
				$new['mo_shipping_label_no'] = __( '物流編號', 'mo-ectools' );
			}
		}
		// fallback: 沒有 order_status 就 append
		if ( ! isset( $new['mo_shipping_label_no'] ) ) {
			$new['mo_shipping_method']   = __( '運送方式', 'mo-ectools' );
			$new['mo_shipping_label_no'] = __( '物流編號', 'mo-ectools' );
		}
		return $new;
	}

	public static function render_column( string $column, $order ): void {
		if ( ! in_array( $column, [ 'mo_shipping_label_no', 'mo_shipping_method' ], true ) ) {
			return;
		}
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof \WC_Order ) {
			echo '—';
			return;
		}
		if ( 'mo_shipping_method' === $column ) {
			$label = self::find_method_label( $order ) ?: '—';
			printf( '<span class="mo-shipping-method" title="%s">%s</span>', esc_attr( $label ), esc_html( $label ) );
			return;
		}
		echo self::format_label_no( self::find_label_no( $order ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function render_column_classic( string $column, int $post_id ): void {
		if ( ! in_array( $column, [ 'mo_shipping_label_no', 'mo_shipping_method' ], true ) ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( ! $order instanceof \WC_Order ) {
			echo '—';
			return;
		}
		if ( 'mo_shipping_method' === $column ) {
			$label = self::find_method_label( $order ) ?: '—';
			printf( '<span class="mo-shipping-method" title="%s">%s</span>', esc_attr( $label ), esc_html( $label ) );
			return;
		}
		echo self::format_label_no( self::find_label_no( $order ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function find_method_label( \WC_Order $order ): string {
		$registry = BatchPrintRegistry::all();
		// 先建 method_id → 中文 title 全表
		$id_to_title = [];
		foreach ( $registry as $entry ) {
			foreach ( $entry['method_titles'] ?? [] as $mid => $title ) {
				$id_to_title[ $mid ] = $title;
			}
		}
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid = (string) $m->get_method_id();
			if ( isset( $id_to_title[ $mid ] ) ) {
				return $id_to_title[ $mid ];
			}
			// fallback: order item title (sometimes admin 自訂)
			$name = (string) $m->get_name();
			if ( '' !== $name && $name !== $mid ) {
				return $name;
			}
		}
		return '';
	}

	private static function format_label_no( string $no ): string {
		if ( '' === $no ) {
			return '—';
		}
		// 過長 ID 縮成「前 8 + … + 後 4」並 hover 顯示全文
		$display = mb_strlen( $no ) > 16 ? mb_substr( $no, 0, 8 ) . '…' . mb_substr( $no, -4 ) : $no;
		return '<span title="' . esc_attr( $no ) . '" style="font-family:monospace;">' . esc_html( $display ) . '</span>';
	}

	private static function find_label_no( \WC_Order $order ): string {
		$ecpay_records = $order->get_meta( Keys::ECPAY_LOGISTIC_RECORDS );
		if ( is_array( $ecpay_records ) && ! empty( $ecpay_records ) ) {
			$latest = end( $ecpay_records );
			if ( ! empty( $latest['id'] ) ) {
				return (string) $latest['id'];
			}
		}
		$id = (string) $order->get_meta( Keys::ECPAY_LOGISTIC_ID );
		if ( '' !== $id ) {
			return $id;
		}
		$payuni_ship_no = (string) $order->get_meta( Keys::PAYUNI_SHIPPING_SNO );
		if ( '' !== $payuni_ship_no ) {
			return $payuni_ship_no;
		}
		$payuni_trade_no = (string) $order->get_meta( Keys::PAYUNI_SHIPPING_TRADE_NO );
		if ( '' !== $payuni_trade_no ) {
			return $payuni_trade_no;
		}
		return '';
	}

	private static function is_orders_screen( string $hook ): bool {
		// HPOS：'woocommerce_page_wc-orders'；舊式：'edit-shop_order' 在 'edit.php' 上
		if ( 'woocommerce_page_wc-orders' === $hook ) {
			return true;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'edit-shop_order' === $screen->id;
	}

	public static function enqueue( string $hook ): void {
		if ( ! self::is_orders_screen( $hook ) ) {
			return;
		}
		$providers = BatchPrintRegistry::all();
		if ( empty( $providers ) ) {
			return;
		}

		$handle  = 'mo-shipping-batch-print';
		$js_path = MOWC_PLUGIN_DIR . 'src/Modules/Shipping/assets/js/batch-print.js';
		$ver     = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOWC_VERSION;
		wp_register_script( $handle, MOWC_PLUGIN_URL . 'src/Modules/Shipping/assets/js/batch-print.js', [ 'jquery' ], $ver, true );
		wp_localize_script( $handle, 'mo_shipping_batch_print', [
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
			'providers' => array_map( static function ( array $p ): array {
				return [
					'key'         => $p['key'],
					'label'       => $p['label'],
					'category'    => $p['category'],
					// 預設 ['1', '2'] (A4+A6 都支援)，provider 可宣告 ['1'] 限 A4
					'paper_modes' => $p['paper_modes'] ?? [ '1', '2' ],
				];
			}, array_values( $providers ) ),
			'i18n'      => [
				'modal_title' => __( '批次列印', 'mo-ectools' ),
				'order_no'    => __( '訂單', 'mo-ectools' ),
				'recipient'   => __( '收件人', 'mo-ectools' ),
				'method'      => __( '運送方式', 'mo-ectools' ),
				'status'      => __( '訂單狀態', 'mo-ectools' ),
				'printable'   => __( '可印', 'mo-ectools' ),
				'no_orders'   => __( '沒有可列印的訂單。', 'mo-ectools' ),
				'select_one'  => __( '請至少選擇一筆。', 'mo-ectools' ),
				/* translators: %d: number of selected orders to print */
				'print'       => __( '列印 (%d)', 'mo-ectools' ),
				'cancel'      => __( '取消', 'mo-ectools' ),
				'loading'     => __( '載入中…', 'mo-ectools' ),
				'error'       => __( '載入失敗。', 'mo-ectools' ),
				'yes'         => __( '✓', 'mo-ectools' ),
				'no'          => __( '—', 'mo-ectools' ),
				'paper_size'  => __( '紙張：', 'mo-ectools' ),
				'a4'          => __( 'A4 標準', 'mo-ectools' ),
				'a6'          => __( 'A6 標籤機', 'mo-ectools' ),
			],
		] );
		wp_enqueue_script( $handle );

		$css_path = MOWC_PLUGIN_DIR . 'src/Modules/Shipping/assets/css/batch-print.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : MOWC_VERSION;
		wp_register_style( $handle, MOWC_PLUGIN_URL . 'src/Modules/Shipping/assets/css/batch-print.css', [], $css_ver );
		wp_enqueue_style( $handle );
	}

	public static function render_modal(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		if ( ! ( 'woocommerce_page_wc-orders' === $screen->id || 'edit-shop_order' === $screen->id ) ) {
			return;
		}
		if ( empty( BatchPrintRegistry::all() ) ) {
			return;
		}
		?>
		<div id="mo-shipping-batch-print-modal" class="mo-batch-modal" style="display:none;">
			<div class="mo-batch-modal__panel">
				<div class="mo-batch-modal__header">
					<h2 class="mo-batch-modal__title"></h2>
					<button type="button" class="mo-batch-modal__close" aria-label="<?php esc_attr_e( '關閉', 'mo-ectools' ); ?>">×</button>
				</div>
				<div class="mo-batch-modal__body"></div>
				<div class="mo-batch-modal__footer">
					<label class="mo-batch-mode" style="margin-right:auto;display:flex;align-items:center;gap:8px;font-size:13px;">
						<span><?php esc_html_e( '紙張：', 'mo-ectools' ); ?></span>
						<select class="mo-batch-mode__select">
							<option value="1"><?php esc_html_e( 'A4 標準', 'mo-ectools' ); ?></option>
							<option value="2"><?php esc_html_e( 'A6 標籤機', 'mo-ectools' ); ?></option>
						</select>
					</label>
					<button type="button" class="button button-primary mo-batch-modal__print" disabled></button>
					<button type="button" class="button mo-batch-modal__cancel"></button>
				</div>
			</div>
		</div>
		<?php
	}

	public static function ajax_list(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ], 403 );
		}
		$key      = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$provider = BatchPrintRegistry::get( $key );
		if ( null === $provider ) {
			wp_send_json_error( [ 'message' => __( '找不到此物流模組。', 'mo-ectools' ) ], 404 );
		}

		// Status 白名單：只列「處理中」+「保留中」— 即「**已建單但未出貨**」的訂單。
		// 「已出貨 / 已抵店 / 已取件」這些是物流推進後的狀態，邏輯上已經印過了，不應該出現
		// 在批次列印 modal 裡。要重印 已出貨 訂單請從訂單編輯頁的「列印物流單」按鈕走。
		// （這個 filter 可被 hook 覆寫）
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		$statuses = apply_filters( 'mo_shipping_batch_print_statuses', [
			'processing',
			'on-hold',
		] );

		$orders = wc_get_orders( [
			'status'  => $statuses,
			'limit'   => 50,
			'order'   => 'DESC',
			'orderby' => 'date',
		] );

		$method_ids       = $provider['method_ids'];
		$method_titles    = $provider['method_titles'] ?? [];
		$provider_modes   = (array) ( $provider['paper_modes'] ?? [ '1', '2' ] );
		$row_modes_fn     = $provider['row_paper_modes'] ?? null;
		$temps_fn         = $provider['record_temps'] ?? null;
		$rows             = [];
		foreach ( $orders as $order ) {
			$method_obj = self::detect_method( $order, $method_ids );
			if ( null === $method_obj ) {
				continue;
			}
			$mid          = (string) $method_obj->get_method_id();
			$item_name    = (string) $method_obj->get_name();
			$record_count = self::record_count( $order, $provider['record_counter'] ?? null );

			// row 紙張支援：若 provider 提供 fn 就動態算（依 ECPay subtype），否則用 provider 預設
			$row_modes = $provider_modes;
			if ( is_callable( $row_modes_fn ) ) {
				$dynamic = (array) call_user_func( $row_modes_fn, $order );
				// 取交集 — provider 沒開的模式不會因 row fn 偷開
				$row_modes = array_values( array_intersect( $provider_modes, $dynamic ) );
				if ( empty( $row_modes ) ) {
					$row_modes = $provider_modes;
				}
			}

			// 多溫層 records 的溫層集合（拆單訂單會 > 1 個 temp）
			$temps = is_callable( $temps_fn )
				? array_values( array_unique( array_map( 'intval', (array) call_user_func( $temps_fn, $order ) ) ) )
				: [];
			sort( $temps );

			$rows[] = [
				'id'          => $order->get_id(),
				'name'        => trim( $order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name() ) ?: trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() ),
				'method'      => self::resolve_method_title( $mid, $item_name, $method_titles ),
				'status'      => wc_get_order_status_name( $order->get_status() ),
				'records'     => $record_count,
				'printable'   => $record_count > 0,
				'paper_modes' => $row_modes,
				'temps'       => $temps,
			];
		}
		wp_send_json_success( [ 'rows' => $rows ] );
	}

	private static function resolve_method_title( string $method_id, string $item_name, array $titles_map ): string {
		if ( isset( $titles_map[ $method_id ] ) && '' !== $titles_map[ $method_id ] ) {
			return $titles_map[ $method_id ];
		}
		return ( '' !== $item_name && $item_name !== $method_id ) ? $item_name : $method_id;
	}

	public static function ajax_run(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ], 403 );
		}
		$key      = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$provider = BatchPrintRegistry::get( $key );
		if ( null === $provider ) {
			wp_send_json_error( [ 'message' => __( '找不到此物流模組。', 'mo-ectools' ) ], 404 );
		}
		$ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ) ) : [];
		$ids = array_values( array_filter( $ids ) );
		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( '請至少選擇一筆訂單。', 'mo-ectools' ) ], 400 );
		}

		$mode  = isset( $_POST['mode'] ) && '2' === sanitize_text_field( wp_unslash( $_POST['mode'] ) ) ? '2' : '1';
		$forms = self::run_provider( $provider, $ids, $mode );
		if ( empty( $forms ) ) {
			wp_send_json_error( [ 'message' => __( '沒有可列印的內容。', 'mo-ectools' ) ], 400 );
		}
		wp_send_json_success( [ 'forms' => $forms ] );
	}

	/**
	 * Invoke a provider's print handler (1-arg or 2-arg signature) and normalise to a forms list.
	 *
	 * @return array<int,array{api_url:string,form_data:array}>
	 */
	private static function run_provider( array $provider, array $ids, string $mode ): array {
		$options = [ 'mode' => '2' === $mode ? '2' : '1' ];
		try {
			$ref   = new \ReflectionFunction( \Closure::fromCallable( $provider['handler'] ) );
			$forms = $ref->getNumberOfParameters() >= 2
				? call_user_func( $provider['handler'], $ids, $options )
				: call_user_func( $provider['handler'], $ids );
		} catch ( \Throwable $e ) {
			$forms = call_user_func( $provider['handler'], $ids );
		}
		return is_array( $forms ) ? array_values( $forms ) : [];
	}

	// ── 基本模式：WooCommerce 內建批次操作下拉 ──────────────────────────────

	public static function register_bulk_actions( array $actions ): array {
		foreach ( BatchPrintRegistry::all() as $key => $provider ) {
			$actions[ self::BULK_PREFIX . $key ] = $provider['label'];
		}
		return $actions;
	}

	public static function handle_bulk_action( $redirect_to, $action, $ids ) {
		if ( ! is_string( $action ) || ! str_starts_with( $action, self::BULK_PREFIX ) ) {
			return $redirect_to;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( '權限不足。', 'mo-ectools' ), 403 );
		}
		$provider = BatchPrintRegistry::get( substr( $action, strlen( self::BULK_PREFIX ) ) );
		if ( null === $provider ) {
			return $redirect_to;
		}

		// provider = 所選動作；把選取訂單中不符此 provider method_id 的自動跳過。
		$ids     = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		$matched = [];
		foreach ( $ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( $order instanceof \WC_Order && null !== self::detect_method( $order, $provider['method_ids'] ) ) {
				$matched[] = $oid;
			}
		}
		$skipped = count( $ids ) - count( $matched );

		if ( empty( $matched ) ) {
			return add_query_arg( [ 'mo_bp_printed' => 0, 'mo_bp_skipped' => $skipped ], $redirect_to );
		}

		$forms = self::run_provider( $provider, $matched, '1' );  // 基本模式預設 A4
		if ( empty( $forms ) ) {
			return add_query_arg( [ 'mo_bp_printed' => 0, 'mo_bp_skipped' => count( $ids ) ], $redirect_to );
		}

		$token = wp_generate_password( 24, false );
		set_transient( 'mo_bp_' . $token, $forms, 5 * MINUTE_IN_SECONDS );

		return add_query_arg(
			[
				'action'   => 'mo_shipping_batch_print_output',
				'token'    => rawurlencode( $token ),
				'skipped'  => $skipped,
				'_wpnonce' => wp_create_nonce( 'mo_bp_print_' . $token ),
			],
			admin_url( 'admin-ajax.php' )
		);
	}

	public static function bulk_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only 提示旗標，無狀態變更；數值經 absint。
		if ( ! isset( $_GET['mo_bp_printed'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only 提示旗標，無狀態變更。
		$printed = absint( wp_unslash( $_GET['mo_bp_printed'] ) );
		if ( 0 === $printed ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( '所選訂單沒有符合此物流的可列印標籤（已全部跳過）。', 'mo-ectools' )
			);
		}
	}

	/**
	 * Standalone print page — 基本模式 bulk action 的 redirect 目標。
	 * 取出 transient 裡的 forms，輸出自動送出的表單（標準獨立列印頁，inline JS 合理）。
	 */
	public static function render_print_output(): void {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( '' === $token || ! wp_verify_nonce( $nonce, 'mo_bp_print_' . $token ) ) {
			wp_die( esc_html__( '列印連結已失效，請重試。', 'mo-ectools' ), 403 );
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( '權限不足。', 'mo-ectools' ), 403 );
		}
		$forms = get_transient( 'mo_bp_' . $token );
		delete_transient( 'mo_bp_' . $token );
		if ( ! is_array( $forms ) || empty( $forms ) ) {
			wp_die( esc_html__( '沒有可列印的內容，或連結已過期。', 'mo-ectools' ) );
		}
		$skipped = isset( $_GET['skipped'] ) ? absint( wp_unslash( $_GET['skipped'] ) ) : 0;
		$count   = count( $forms );
		$single  = ( 1 === $count );

		nocache_headers();
		?><!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( '批次列印物流標籤', 'mo-ectools' ); ?></title>
			<style>body{font-family:-apple-system,"PingFang TC","Microsoft JhengHei",sans-serif;padding:40px;color:#1d2327;}h2{margin:0 0 4px;}p{color:#646970;margin:4px 0 20px;}button{font-size:15px;padding:10px 20px;margin:6px 8px 6px 0;cursor:pointer;border:1px solid #2271b1;background:#2271b1;color:#fff;border-radius:4px;}button:hover{background:#135e96;}form{display:none;}</style>
		</head>
		<body>
			<h2><?php esc_html_e( '批次列印物流標籤', 'mo-ectools' ); ?></h2>
			<?php if ( $skipped > 0 ) : ?>
				<?php /* translators: 1: number of labels produced, 2: number of skipped orders */ ?>
				<p><?php printf( esc_html__( '已產生 %1$d 份標籤；略過 %2$d 筆（非此物流的訂單）。', 'mo-ectools' ), (int) $count, (int) $skipped ); ?></p>
			<?php else : ?>
				<?php /* translators: %d: number of labels produced */ ?>
				<p><?php printf( esc_html__( '已產生 %d 份標籤。', 'mo-ectools' ), (int) $count ); ?></p>
			<?php endif; ?>

			<?php if ( ! $single ) : ?>
				<p><?php esc_html_e( '請點擊下列按鈕開啟各份標籤（避免瀏覽器擋自動彈窗）：', 'mo-ectools' ); ?></p>
				<?php foreach ( $forms as $i => $spec ) : ?>
					<?php /* translators: %d: label sequence number */ ?>
					<button type="button" onclick="document.getElementById('mo-bp-f<?php echo (int) $i; ?>').submit();"><?php printf( esc_html__( '列印第 %d 份', 'mo-ectools' ), (int) $i + 1 ); ?></button>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php foreach ( $forms as $i => $spec ) : ?>
				<form id="mo-bp-f<?php echo (int) $i; ?>" method="post" action="<?php echo esc_url( (string) ( $spec['api_url'] ?? '' ) ); ?>" target="<?php echo $single ? '_self' : '_blank'; ?>">
					<?php foreach ( (array) ( $spec['form_data'] ?? [] ) as $k => $v ) : ?>
						<input type="hidden" name="<?php echo esc_attr( (string) $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
					<?php endforeach; ?>
				</form>
			<?php endforeach; ?>

			<?php if ( $single ) : ?>
				<script>document.getElementById('mo-bp-f0').submit();</script>
			<?php endif; ?>
		</body>
		</html>
		<?php
		exit;
	}

	private static function detect_method( \WC_Order $order, array $method_ids ): ?\WC_Order_Item_Shipping {
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( in_array( $method->get_method_id(), $method_ids, true ) ) {
				return $method;
			}
		}
		return null;
	}

	private static function record_count( \WC_Order $order, ?callable $counter ): int {
		if ( null === $counter ) {
			return 1;  // 沒提供 counter 就當作至少一筆（讓 button 可按）
		}
		return max( 0, (int) call_user_func( $counter, $order ) );
	}
}
