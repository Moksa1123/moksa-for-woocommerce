<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Admin;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class BatchPrintAdminUI {

	private const NONCE_ACTION = 'moksafowo_shipping_batch_print';
	private const CAPABILITY   = 'edit_shop_orders';
	private const BULK_ACTION  = 'moksafowo_batchprint_labels';

	public static function init(): void {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'register_column' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'register_column' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_column_classic' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_columns_css' ] );
		add_action( 'wp_ajax_moksafowo_shipping_batch_print_output', [ __CLASS__, 'render_print_output' ] );

		if ( self::is_advanced() ) {
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
			add_action( 'admin_footer', [ __CLASS__, 'render_modal' ] );
			add_action( 'wp_ajax_moksafowo_shipping_batch_print_list', [ __CLASS__, 'ajax_list' ] );
			add_action( 'wp_ajax_moksafowo_shipping_batch_print_run', [ __CLASS__, 'ajax_run' ] );
		} else {
			add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'register_bulk_actions' ] );
			add_filter( 'bulk_actions-edit-shop_order', [ __CLASS__, 'register_bulk_actions' ] );
			add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
			add_filter( 'handle_bulk_actions-edit-shop_order', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
			add_action( 'admin_notices', [ __CLASS__, 'bulk_notices' ] );
		}
	}

	public static function is_advanced(): bool {
		return 'yes' === get_option( 'moksafowo_shipping_bulk_print_mode_advanced', 'no' );
	}

	public static function admin_columns_css( string $hook = '' ): void {
		if ( ! self::is_orders_screen( $hook ) ) {
			return;
		}
		$css = '.wp-list-table .column-moksafowo_shipping_method{width:8em;white-space:nowrap;}'
			. '.wp-list-table .column-moksafowo_shipping_method .moksafowo-shipping-method{display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle;}'
			. '.wp-list-table .column-moksafowo_shipping_label_no{width:7em;white-space:nowrap;}'
			. '.wp-list-table .column-order_total{white-space:nowrap;}'
			. '.wp-list-table .column-wc_actions{white-space:nowrap;}';
		wp_register_style( 'moksafowo-shipping-cols', false, [], MOKSAFOWO_VERSION );
		wp_enqueue_style( 'moksafowo-shipping-cols' );
		wp_add_inline_style( 'moksafowo-shipping-cols', $css );
	}

	public static function register_column( array $cols ): array {
		$new = [];
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$new['moksafowo_shipping_method']   = __( '運送方式', 'mo-ectools' );
				$new['moksafowo_shipping_label_no'] = __( '物流編號', 'mo-ectools' );
			}
		}
		if ( ! isset( $new['moksafowo_shipping_label_no'] ) ) {
			$new['moksafowo_shipping_method']   = __( '運送方式', 'mo-ectools' );
			$new['moksafowo_shipping_label_no'] = __( '物流編號', 'mo-ectools' );
		}
		return $new;
	}

	public static function render_column( string $column, $order ): void {
		if ( ! in_array( $column, [ 'moksafowo_shipping_label_no', 'moksafowo_shipping_method' ], true ) ) {
			return;
		}
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof \WC_Order ) {
			echo '—';
			return;
		}
		if ( 'moksafowo_shipping_method' === $column ) {
			$label = self::find_method_label( $order ) ?: '—';
			printf( '<span class="moksafowo-shipping-method" title="%s">%s</span>', esc_attr( $label ), esc_html( $label ) );
			return;
		}
		echo wp_kses_post( self::format_label_no( self::find_label_no( $order ) ) );
	}

	public static function render_column_classic( string $column, int $post_id ): void {
		if ( ! in_array( $column, [ 'moksafowo_shipping_label_no', 'moksafowo_shipping_method' ], true ) ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( ! $order instanceof \WC_Order ) {
			echo '—';
			return;
		}
		if ( 'moksafowo_shipping_method' === $column ) {
			$label = self::find_method_label( $order ) ?: '—';
			printf( '<span class="moksafowo-shipping-method" title="%s">%s</span>', esc_attr( $label ), esc_html( $label ) );
			return;
		}
		echo wp_kses_post( self::format_label_no( self::find_label_no( $order ) ) );
	}

	private static function find_method_label( \WC_Order $order ): string {
		$registry    = BatchPrintRegistry::all();
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

		$handle  = 'moksafowo-shipping-batch-print';
		$js_path = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Shipping/assets/js/batch-print.js';
		$ver     = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOKSAFOWO_VERSION;
		wp_register_script( $handle, MOKSAFOWO_PLUGIN_URL . 'src/Modules/Shipping/assets/js/batch-print.js', [ 'jquery' ], $ver, true );
		wp_localize_script(
			$handle,
			'moksafowo_shipping_batch_print',
			[
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'providers' => array_map(
					static function ( array $p ): array {
						return [
							'key'         => $p['key'],
							'label'       => $p['label'],
							'category'    => $p['category'],
							'paper_modes' => $p['paper_modes'] ?? [ '1', '2' ],
						];
					},
					array_values( $providers )
				),
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
			]
		);
		wp_enqueue_script( $handle );

		$css_path = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Shipping/assets/css/batch-print.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : MOKSAFOWO_VERSION;
		wp_register_style( $handle, MOKSAFOWO_PLUGIN_URL . 'src/Modules/Shipping/assets/css/batch-print.css', [], $css_ver );
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
		<div id="moksafowo-shipping-batch-print-modal" class="moksafowo-batch-modal" style="display:none;">
			<div class="moksafowo-batch-modal__panel">
				<div class="moksafowo-batch-modal__header">
					<h2 class="moksafowo-batch-modal__title"></h2>
					<button type="button" class="moksafowo-batch-modal__close" aria-label="<?php esc_attr_e( '關閉', 'mo-ectools' ); ?>">×</button>
				</div>
				<div class="moksafowo-batch-modal__body"></div>
				<div class="moksafowo-batch-modal__footer">
					<label class="moksafowo-batch-mode" style="margin-right:auto;display:flex;align-items:center;gap:8px;font-size:13px;">
						<span><?php esc_html_e( '紙張：', 'mo-ectools' ); ?></span>
						<select class="moksafowo-batch-mode__select">
							<option value="1"><?php esc_html_e( 'A4 標準', 'mo-ectools' ); ?></option>
							<option value="2"><?php esc_html_e( 'A6 標籤機', 'mo-ectools' ); ?></option>
						</select>
					</label>
					<button type="button" class="button button-primary moksafowo-batch-modal__print" disabled></button>
					<button type="button" class="button moksafowo-batch-modal__cancel"></button>
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

		// 只列「處理中/保留中」— 已出貨/抵店/取件的訂單應已印過，不納入（filter 可覆寫）
		$statuses = apply_filters(
			'moksafowo_shipping_batch_print_statuses',
			[
				'processing',
				'on-hold',
			]
		);

		$orders = wc_get_orders(
			[
				'status'  => $statuses,
				'limit'   => 50,
				'order'   => 'DESC',
				'orderby' => 'date',
			]
		);

		$method_ids     = $provider['method_ids'];
		$method_titles  = $provider['method_titles'] ?? [];
		$provider_modes = (array) ( $provider['paper_modes'] ?? [ '1', '2' ] );
		$row_modes_fn   = $provider['row_paper_modes'] ?? null;
		$temps_fn       = $provider['record_temps'] ?? null;
		$rows           = [];
		foreach ( $orders as $order ) {
			$method_obj = self::detect_method( $order, $method_ids );
			if ( null === $method_obj ) {
				continue;
			}
			$mid          = (string) $method_obj->get_method_id();
			$item_name    = (string) $method_obj->get_name();
			$record_count = self::record_count( $order, $provider['record_counter'] ?? null );

			$row_modes = $provider_modes;
			if ( is_callable( $row_modes_fn ) ) {
				$dynamic = (array) call_user_func( $row_modes_fn, $order );
				// 取交集 — provider 未開的模式不會因 row fn 偷開
				$row_modes = array_values( array_intersect( $provider_modes, $dynamic ) );
				if ( empty( $row_modes ) ) {
					$row_modes = $provider_modes;
				}
			}

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

	/**
	 * 給程式化呼叫（如 AI ability）：傳訂單 ID，自動分組各物流商、建列印表單、存一次性
	 * transient token，回傳列印輸出頁 URL（同 bulk action 的目標）。不直接列印，回 URL 供開啟。
	 *
	 * @param int[]  $order_ids 訂單 ID。
	 * @param string $mode      紙張 '1'=A4 / '2'=A6。
	 * @return array{ok:bool, url?:string, count?:int, skipped?:int, message?:string}
	 */
	public static function build_print_url( array $order_ids, string $mode = '1' ): array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return [
				'ok'      => false,
				'message' => __( '權限不足。', 'mo-ectools' ),
			];
		}
		$ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );
		if ( empty( $ids ) ) {
			return [
				'ok'      => false,
				'message' => __( '沒有指定訂單。', 'mo-ectools' ),
			];
		}
		$mode     = '2' === $mode ? '2' : '1';
		$registry = BatchPrintRegistry::all();

		$by_provider = [];
		foreach ( $ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( $registry as $key => $provider ) {
				if ( null !== self::detect_method( $order, $provider['method_ids'] ) ) {
					$by_provider[ $key ][] = $oid;
					break;
				}
			}
		}

		$matched = [];
		$forms   = [];
		foreach ( $by_provider as $key => $oids ) {
			$oids    = array_values( array_unique( $oids ) );
			$matched = array_merge( $matched, $oids );
			$forms   = array_merge( $forms, self::run_provider( $registry[ $key ], $oids, $mode ) );
		}
		$skipped = count( $ids ) - count( array_unique( $matched ) );

		if ( empty( $forms ) ) {
			return [
				'ok'      => false,
				'skipped' => $skipped,
				'message' => __( '所選訂單沒有可列印的物流標籤（可能尚未建立託運單）。', 'mo-ectools' ),
			];
		}

		$token = wp_generate_password( 24, false );
		set_transient( 'moksafowo_bp_' . $token, $forms, 5 * MINUTE_IN_SECONDS );

		$url = add_query_arg(
			[
				'action'   => 'moksafowo_shipping_batch_print_output',
				'token'    => rawurlencode( $token ),
				'skipped'  => $skipped,
				'_wpnonce' => wp_create_nonce( 'moksafowo_bp_print_' . $token ),
			],
			admin_url( 'admin-ajax.php' )
		);

		return [
			'ok'      => true,
			'url'     => $url,
			'count'   => count( $forms ),
			'skipped' => $skipped,
		];
	}

	// ── 基本模式：WooCommerce 內建批次操作下拉 ──────────────────────────────

	public static function register_bulk_actions( array $actions ): array {
		if ( ! empty( BatchPrintRegistry::all() ) ) {
			$actions[ self::BULK_ACTION ] = __( '列印物流單（自動判斷物流商）', 'mo-ectools' );
		}
		return $actions;
	}

	public static function handle_bulk_action( $redirect_to, $action, $ids ) {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect_to;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( '權限不足。', 'mo-ectools' ), 403 );
		}

		$ids      = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		$registry = BatchPrintRegistry::all();

		$by_provider = [];
		foreach ( $ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( $registry as $key => $provider ) {
				if ( null !== self::detect_method( $order, $provider['method_ids'] ) ) {
					$by_provider[ $key ][] = $oid;
					break;
				}
			}
		}

		$matched = [];
		$forms   = [];
		foreach ( $by_provider as $key => $oids ) {
			$oids    = array_values( array_unique( $oids ) );
			$matched = array_merge( $matched, $oids );
			$forms   = array_merge( $forms, self::run_provider( $registry[ $key ], $oids, '1' ) ); // 基本模式固定 A4
		}
		$skipped = count( $ids ) - count( array_unique( $matched ) );

		if ( empty( $forms ) ) {
			// transient 而非 URL 參數，避免重整訂單列表時一直跳
			set_transient( 'moksafowo_bp_skipped_' . get_current_user_id(), max( 1, $skipped ), MINUTE_IN_SECONDS );
			return $redirect_to;
		}

		$token = wp_generate_password( 24, false );
		set_transient( 'moksafowo_bp_' . $token, $forms, 5 * MINUTE_IN_SECONDS );

		return add_query_arg(
			[
				'action'   => 'moksafowo_shipping_batch_print_output',
				'token'    => rawurlencode( $token ),
				'skipped'  => $skipped,
				'_wpnonce' => wp_create_nonce( 'moksafowo_bp_print_' . $token ),
			],
			admin_url( 'admin-ajax.php' )
		);
	}

	public static function bulk_notices(): void {
		// 只在訂單列表顯示，且讀完即刪（避免重整時重複跳通知）
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
			return;
		}
		$key = 'moksafowo_bp_skipped_' . get_current_user_id();
		if ( false === get_transient( $key ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html__( '所選訂單沒有符合此物流的可列印標籤（已全部跳過）。', 'mo-ectools' )
		);
	}

	public static function render_print_output(): void {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		if ( '' === $token ) {
			wp_die( esc_html__( '列印連結已失效，請重試。', 'mo-ectools' ), 403 );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'moksafowo_bp_print_' . $token ) ) {
			wp_die( esc_html__( '列印連結已失效，請重試。', 'mo-ectools' ), 403 );
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( '權限不足。', 'mo-ectools' ), 403 );
		}
		$forms = get_transient( 'moksafowo_bp_' . $token );
		delete_transient( 'moksafowo_bp_' . $token );
		if ( ! is_array( $forms ) || empty( $forms ) ) {
			wp_die( esc_html__( '沒有可列印的內容，或連結已過期。', 'mo-ectools' ) );
		}
		$skipped = isset( $_GET['skipped'] ) ? absint( wp_unslash( $_GET['skipped'] ) ) : 0;
		$count   = count( $forms );
		$single  = ( 1 === $count );

		wp_register_style( 'moksafowo-bp-print', false, array(), MOKSAFOWO_VERSION );
		wp_enqueue_style( 'moksafowo-bp-print' );
		wp_add_inline_style( 'moksafowo-bp-print', 'body{font-family:-apple-system,"PingFang TC","Microsoft JhengHei",sans-serif;padding:40px;color:#1d2327;}h2{margin:0 0 4px;}p{color:#646970;margin:4px 0 20px;}button{font-size:15px;padding:10px 20px;margin:6px 8px 6px 0;cursor:pointer;border:1px solid #2271b1;background:#2271b1;color:#fff;border-radius:4px;}button:hover{background:#135e96;}form{display:none;}' );

		nocache_headers();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( '批次列印物流標籤', 'mo-ectools' ); ?></title>
			<?php wp_print_styles( 'moksafowo-bp-print' ); ?>
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
					<button type="button" data-bp-target="moksafowo-bp-f<?php echo (int) $i; ?>"><?php printf( esc_html__( '列印第 %d 份', 'mo-ectools' ), (int) $i + 1 ); ?></button>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php foreach ( $forms as $i => $spec ) : ?>
				<form id="moksafowo-bp-f<?php echo (int) $i; ?>" method="post" action="<?php echo esc_url( (string) ( $spec['api_url'] ?? '' ) ); ?>" target="<?php echo $single ? '_self' : '_blank'; ?>">
					<?php foreach ( (array) ( $spec['form_data'] ?? [] ) as $k => $v ) : ?>
						<input type="hidden" name="<?php echo esc_attr( (string) $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
					<?php endforeach; ?>
				</form>
			<?php endforeach; ?>

			<?php if ( $single ) : ?>
				<?php wp_print_inline_script_tag( 'document.getElementById("moksafowo-bp-f0").submit();' ); ?>
			<?php else : ?>
				<?php wp_print_inline_script_tag( 'document.querySelectorAll("[data-bp-target]").forEach(function(b){b.addEventListener("click",function(){var f=document.getElementById(b.getAttribute("data-bp-target"));if(f){f.submit();}});});' ); ?>
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
			return 1;
		}
		return max( 0, (int) call_user_func( $counter, $order ) );
	}
}
