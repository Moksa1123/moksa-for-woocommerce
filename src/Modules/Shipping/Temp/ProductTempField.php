<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Temp;

use MoksaWeb\Mowc\Product\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class ProductTempField {

	public static function init(): void {
		// Simple / Variable parent — 顯示在 Shipping tab 既有欄位下方
		add_action( 'woocommerce_product_options_shipping', [ __CLASS__, 'render_simple_field' ] );
		add_action( 'woocommerce_admin_process_product_object', [ __CLASS__, 'save_simple_field' ] );

		// Variation — variation 欄位區塊
		add_action( 'woocommerce_variation_options', [ __CLASS__, 'render_variation_field' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ __CLASS__, 'save_variation_field' ], 10, 2 );

		// 商品列表多一格「溫層」column
		add_filter( 'manage_edit-product_columns', [ __CLASS__, 'add_list_column' ], 20 );
		add_action( 'manage_product_posts_custom_column', [ __CLASS__, 'render_list_column' ], 10, 2 );
		// column 寬度 / nowrap CSS（避免 column header 直立 wrap 跟相鄰 column 重疊）
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'list_column_styles' ] );

		// 商品列表上方 filter 下拉（依溫層篩選）
		add_action( 'restrict_manage_posts', [ __CLASS__, 'render_list_filter' ] );
		add_filter( 'parse_query', [ __CLASS__, 'apply_list_filter' ] );

		// Quick Edit / Bulk Edit 行內溫層下拉 — 走 WC 自己的 hook（位置會在 WC「商品資料」
		// section 末尾，跟 SKU / 價格 / 重量 等 WC field 並排），比 WP core 的
		// quick_edit_custom_box 對齊（WP core hook 在 WC product 會跑到 column-right 末尾，
		// 跟 layout 衝突）。
		add_action( 'woocommerce_product_quick_edit_end', [ __CLASS__, 'render_wc_quick_edit_field' ] );
		add_action( 'woocommerce_product_bulk_edit_end', [ __CLASS__, 'render_wc_bulk_edit_field' ] );
		add_action( 'woocommerce_product_quick_edit_save', [ __CLASS__, 'save_wc_quick_edit' ] );
		add_action( 'woocommerce_product_bulk_edit_save', [ __CLASS__, 'save_wc_bulk_edit' ] );
		// 把 row 上的當前 temp 灌進 quick edit form 的 JS（WC core 不自動 prefill custom field）
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'inline_quick_edit_js' ] );

		// 注意：CSV import / export hooks 由 init_csv_hooks() 在 Module::boot() 直接呼叫
		// （在 is_admin() guard 外）— WPCLI / cron / REST 不會走進 ProductTempField::init()
	}

	
	public static function init_csv_hooks(): void {
		add_filter( 'woocommerce_product_export_column_names', [ __CLASS__, 'csv_add_column' ] );
		add_filter( 'woocommerce_product_export_product_default_columns', [ __CLASS__, 'csv_add_column' ] );
		add_filter( 'woocommerce_product_export_product_column_moksafowo_product_temp', [ __CLASS__, 'csv_export_value' ], 10, 2 );
		add_filter( 'woocommerce_csv_product_import_mapping_options', [ __CLASS__, 'csv_import_mapping_options' ] );
		add_filter( 'woocommerce_csv_product_import_mapping_default_columns', [ __CLASS__, 'csv_import_mapping_defaults' ] );
		add_filter( 'woocommerce_product_importer_parsed_data', [ __CLASS__, 'csv_import_parse_value' ], 10, 2 );
		add_action( 'woocommerce_product_import_inserted_product_object', [ __CLASS__, 'csv_import_save_value' ], 10, 2 );
	}

	public static function list_column_styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		$css = '.wp-list-table .column-moksafowo_product_temp{width:64px;text-align:center;white-space:nowrap;}'
			. '.wp-list-table .column-moksafowo_product_temp span{display:inline-block;}';
		wp_register_style( 'moksafowo-product-temp-col', false, [], MOKSAFOWO_VERSION );
		wp_enqueue_style( 'moksafowo-product-temp-col' );
		wp_add_inline_style( 'moksafowo-product-temp-col', $css );
	}

	public static function add_list_column( array $cols ): array {
		$insert_after = isset( $cols['sku'] ) ? 'sku' : 'name';
		$new_cols     = [];
		foreach ( $cols as $k => $v ) {
			$new_cols[ $k ] = $v;
			if ( $k === $insert_after ) {
				$new_cols['moksafowo_product_temp'] = __( '溫層', 'mo-ectools' );
			}
		}
		// 如果上面 insert_after 沒命中（rare edge case），fallback append
		if ( ! isset( $new_cols['moksafowo_product_temp'] ) ) {
			$new_cols['moksafowo_product_temp'] = __( '溫層', 'mo-ectools' );
		}
		return $new_cols;
	}

	public static function render_list_column( string $column, int $product_id ): void {
		if ( 'moksafowo_product_temp' !== $column ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			echo '—';
			return;
		}
		// 視覺差表達 explicit vs 預設（fallback 常溫）：
		//   explicit  = 實心 pill（常溫灰 / 冷藏藍 / 冷凍紫）
		//   unset     = dashed outline pill 灰字，hover 提示「未明確設定 — 預設為常溫」
		$raw      = (int) $product->get_meta( Keys::PRODUCT_TEMP, true );
		$explicit = in_array( $raw, [ ProductTemp::NORMAL, ProductTemp::REFRIGERATED, ProductTemp::FROZEN ], true );
		$temp     = $explicit ? $raw : ProductTemp::NORMAL;
		$label    = ProductTemp::label( $temp );
		if ( $explicit ) {
			[ $bg, $fg ] = match ( $temp ) {
				ProductTemp::REFRIGERATED => [ '#dbeafe', '#1e40af' ],
				ProductTemp::FROZEN       => [ '#ede9fe', '#6d28d9' ],
				default                   => [ '#e5e7eb', '#374151' ],
			};
			$style = sprintf( 'background:%s;color:%s;border:1px solid transparent;', esc_attr( $bg ), esc_attr( $fg ) );
			$title = '';
		} else {
			$style = 'background:transparent;color:#8c8f94;border:1px dashed #c3c4c7;';
			$title = esc_attr__( '未明確設定 — cart 階段預設為常溫', 'mo-ectools' );
		}
		printf(
			'<span class="moksafowo-product-temp-pill%s" data-temp="%d" title="%s" style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;line-height:1.5;white-space:nowrap;%s">%s</span>',
			$explicit ? '' : ' is-default',
			(int) $temp,
			esc_attr( $title ),
			esc_attr( $style ),
			esc_html( $label )
		);
	}

	public static function render_list_filter(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		$current = isset( $_GET['moksafowo_product_temp_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['moksafowo_product_temp_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin filter
		echo '<select name="moksafowo_product_temp_filter" id="moksafowo_product_temp_filter">';
		echo '<option value="">' . esc_html__( '所有溫層', 'mo-ectools' ) . '</option>';
		foreach ( ProductTemp::options( false ) as $value => $label ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $value,
				selected( (string) $current, (string) $value, false ),
				esc_html( $label )
			);
		}
		echo '<option value="unset"' . selected( $current, 'unset', false ) . '>' . esc_html__( '未明確設定（預設常溫）', 'mo-ectools' ) . '</option>';
		echo '</select>';
	}

	public static function apply_list_filter( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		$raw = isset( $_GET['moksafowo_product_temp_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['moksafowo_product_temp_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin filter
		if ( '' === $raw ) {
			return;
		}
		$meta_query = (array) ( $query->get( 'meta_query' ) ?: [] );
		if ( 'unset' === $raw ) {
			$meta_query[] = [
				'relation' => 'OR',
				[ 'key' => Keys::PRODUCT_TEMP, 'compare' => 'NOT EXISTS' ],
				[ 'key' => Keys::PRODUCT_TEMP, 'value' => '', 'compare' => '=' ],
				[ 'key' => Keys::PRODUCT_TEMP, 'value' => '0', 'compare' => '=' ],
			];
		} elseif ( in_array( (int) $raw, [ ProductTemp::NORMAL, ProductTemp::REFRIGERATED, ProductTemp::FROZEN ], true ) ) {
			$meta_query[] = [ 'key' => Keys::PRODUCT_TEMP, 'value' => (string) (int) $raw, 'compare' => '=' ];
		}
		$query->set( 'meta_query', $meta_query );
	}

	public static function render_wc_quick_edit_field(): void {
		?>
		<label class="alignleft moksafowo-product-temp-field-quick">
			<span class="title"><?php esc_html_e( '物流溫層', 'mo-ectools' ); ?></span>
			<span class="input-text-wrap">
				<select name="moksafowo_product_temp" class="moksafowo-product-temp-select">
					<?php foreach ( ProductTemp::options( false ) as $val => $label ) : ?>
						<option value="<?php echo esc_attr( (string) (int) $val ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</span>
		</label>
		<br class="clear" />
		<?php
	}

	public static function render_wc_bulk_edit_field(): void {
		?>
		<label class="alignleft moksafowo-product-temp-field-bulk">
			<span class="title"><?php esc_html_e( '物流溫層', 'mo-ectools' ); ?></span>
			<span class="input-text-wrap">
				<select name="moksafowo_product_temp_bulk">
					<option value=""><?php esc_html_e( '— 不變更 —', 'mo-ectools' ); ?></option>
					<?php foreach ( ProductTemp::options( false ) as $val => $label ) : ?>
						<option value="<?php echo esc_attr( (string) (int) $val ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</span>
		</label>
		<br class="clear" />
		<?php
	}

	public static function save_wc_quick_edit( \WC_Product $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Bound to save_post / quick_edit / bulk_edit; WP core handles nonce + capability check.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC core 已驗 quick edit nonce
		$raw = isset( $_REQUEST['moksafowo_product_temp'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['moksafowo_product_temp'] ) ) : null;
		self::apply_temp_to_product( $product, $raw );
	}

	public static function save_wc_bulk_edit( \WC_Product $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Bound to save_post / quick_edit / bulk_edit; WP core handles nonce + capability check.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC core 已驗 bulk edit nonce
		$raw = isset( $_REQUEST['moksafowo_product_temp_bulk'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['moksafowo_product_temp_bulk'] ) ) : null;
		self::apply_temp_to_product( $product, $raw );
	}

	private static function apply_temp_to_product( \WC_Product $product, $raw ): void {
		if ( null === $raw || '' === $raw ) {
			return; // 「— 不變更 —」
		}
		$value = (int) wp_unslash( (string) $raw );
		if ( ! in_array( $value, [ ProductTemp::NORMAL, ProductTemp::REFRIGERATED, ProductTemp::FROZEN ], true ) ) {
			return;
		}
		$product->update_meta_data( Keys::PRODUCT_TEMP, (string) $value );
		// WC core 流程在「呼叫此 action 之前」就先 $product->save() 了，必須這裡再 save 一次
		// 才會把 meta 寫入 DB（之前以為外層會再 save 是錯的）。
		$product->save();
	}

	public static function inline_quick_edit_js(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}
		$css = '#woocommerce-fields .moksafowo-product-temp-field-quick { display:block; clear:both; }'
			. '#woocommerce-fields .moksafowo-product-temp-field-quick .title { width:5em; }';
		wp_register_style( 'moksafowo-product-temp-quick', false, [], MOKSAFOWO_VERSION );
		wp_enqueue_style( 'moksafowo-product-temp-quick' );
		wp_add_inline_style( 'moksafowo-product-temp-quick', $css );

		$js = "(function(){"
			. " if (typeof inlineEditPost === 'undefined') { return; }"
			. " var orig = inlineEditPost.edit;"
			. " inlineEditPost.edit = function(id){"
			. " orig.apply(this, arguments);"
			. " var post_id = (typeof id === 'object') ? this.getId(id) : id;"
			. " if (!post_id) { return; }"
			. " var pill = jQuery('#post-' + post_id).find('.moksafowo-product-temp-pill').first();"
			. " var temp = pill.length ? pill.data('temp') : '';"
			. " jQuery('#edit-' + post_id).find('select[name=\"moksafowo_product_temp\"]').val(temp ? String(temp) : '');"
			. " };"
			. " })();";
		wp_register_script( 'moksafowo-product-temp-quick', false, [ 'jquery', 'inline-edit-post' ], MOKSAFOWO_VERSION, true );
		wp_enqueue_script( 'moksafowo-product-temp-quick' );
		wp_add_inline_script( 'moksafowo-product-temp-quick', $js );
	}

	/* ============== CSV import / export ============== */

	public static function csv_add_column( array $columns ): array {
		$columns['moksafowo_product_temp'] = __( '物流溫層', 'mo-ectools' );
		return $columns;
	}

	
	public static function csv_export_value( $value, $product ): string {
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}
		$raw = (int) $product->get_meta( Keys::PRODUCT_TEMP, true );
		if ( ! in_array( $raw, [ ProductTemp::NORMAL, ProductTemp::REFRIGERATED, ProductTemp::FROZEN ], true ) ) {
			return ''; // 未明確設定 = 留空
		}
		return (string) $raw;
	}

	public static function csv_import_mapping_options( array $options ): array {
		$options['moksafowo_product_temp'] = __( '物流溫層', 'mo-ectools' );
		return $options;
	}

	public static function csv_import_mapping_defaults( array $columns ): array {
		// 給 WC importer 一些常見 header 自動對應到我們的 key — case-insensitive
		// 枚舉常見大小寫變體（WC core auto-mapper 走 exact string match）
		$bases = [ 'moksafowo_product_temp', 'moksafowo product temp' ];
		foreach ( $bases as $base ) {
			$variants = [ $base, strtolower( $base ), strtoupper( $base ), ucwords( $base, ' _-' ) ];
			foreach ( array_unique( $variants ) as $v ) {
				$columns[ $v ] = 'moksafowo_product_temp';
			}
		}
		// 中文 header — 沒大小寫，直接加
		$columns['物流溫層'] = 'moksafowo_product_temp';
		$columns['溫層']     = 'moksafowo_product_temp';
		return $columns;
	}

	public static function csv_import_parse_value( array $data, $importer ): array {
		if ( ! array_key_exists( 'moksafowo_product_temp', $data ) ) {
			return $data;
		}
		$raw = trim( (string) $data['moksafowo_product_temp'] );
		$data['moksafowo_product_temp'] = self::normalize_csv_temp( $raw );
		return $data;
	}

	public static function csv_import_save_value( $product, array $data ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		if ( ! array_key_exists( 'moksafowo_product_temp', $data ) ) {
			return;
		}
		$value = $data['moksafowo_product_temp'];
		if ( '' === $value || null === $value ) {
			// 空 = 不動（不刪也不寫，避免 import 一個沒這 column 的 CSV 就清掉所有設定）
			return;
		}
		// explicit clear marker — `-` / `unset` / `(none)` / `預設` 等 → 刪 meta
		if ( '__clear__' === $value ) {
			$product->delete_meta_data( Keys::PRODUCT_TEMP );
			$product->save();
			return;
		}
		if ( in_array( (int) $value, [ ProductTemp::NORMAL, ProductTemp::REFRIGERATED, ProductTemp::FROZEN ], true ) ) {
			$product->update_meta_data( Keys::PRODUCT_TEMP, (string) (int) $value );
			$product->save();
		}
	}

	private static function normalize_csv_temp( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		// 數字直接接（'1' / '2' / '3'）
		if ( ctype_digit( $raw ) ) {
			$v = (int) $raw;
			return in_array( $v, [ ProductTemp::NORMAL, ProductTemp::REFRIGERATED, ProductTemp::FROZEN ], true ) ? (string) $v : '';
		}
		// 顯式 clear marker — 商家想用 CSV 清掉某商品的設定，用這些字串
		$clear_markers = [ '-', '–', 'unset', 'clear', '(none)', 'none', '預設', 'default' ];
		$key           = mb_strtolower( trim( $raw ) );
		if ( in_array( $key, $clear_markers, true ) || in_array( $raw, $clear_markers, true ) ) {
			return '__clear__';
		}
		// 中文標籤對應
		$lookup = [
			'常溫'        => (string) ProductTemp::NORMAL,
			'冷藏'        => (string) ProductTemp::REFRIGERATED,
			'冷凍'        => (string) ProductTemp::FROZEN,
			'normal'      => (string) ProductTemp::NORMAL,
			'refrigerated' => (string) ProductTemp::REFRIGERATED,
			'frozen'      => (string) ProductTemp::FROZEN,
		];
		if ( isset( $lookup[ $raw ] ) ) {
			return $lookup[ $raw ];
		}
		if ( isset( $lookup[ $key ] ) ) {
			return $lookup[ $key ];
		}
		return '';
	}

	public static function render_simple_field(): void {
		woocommerce_wp_select(
			[
				'id'            => Keys::PRODUCT_TEMP,
				'label'         => __( '物流溫層', 'mo-ectools' ),
				'description'   => __( '此商品的物流溫層分艙。冷藏 / 冷凍商品在後台建立物流單時會獨立成單，供需要分艙運送的物流業者使用。', 'mo-ectools' ),
				'desc_tip'      => true,
				'wrapper_class' => 'moksafowo-product-temp-field',
				'options'       => self::stringify_options( ProductTemp::options( false ) ),
			]
		);
	}

	public static function save_simple_field( \WC_Product $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Bound to save_post / quick_edit / bulk_edit; WP core handles nonce + capability check.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing — WC core 已驗 nonce
		$value = isset( $_POST[ Keys::PRODUCT_TEMP ] ) ? absint( wp_unslash( $_POST[ Keys::PRODUCT_TEMP ] ) ) : 0;
		if ( in_array( $value, [ ProductTemp::NORMAL, ProductTemp::REFRIGERATED, ProductTemp::FROZEN ], true ) ) {
			$product->update_meta_data( Keys::PRODUCT_TEMP, (string) $value );
		} else {
			// 0 / 空 / 異常 → 等同常溫，刪除 meta 讓 cart 階段走預設
			$product->delete_meta_data( Keys::PRODUCT_TEMP );
		}
	}

	public static function render_variation_field( int $loop, array $variation_data, \WP_Post $variation ): void {
		$current = (string) get_post_meta( (int) $variation->ID, Keys::PRODUCT_TEMP, true );

		woocommerce_wp_select(
			[
				'id'            => 'moksafowo_product_temp_' . $loop,
				'name'          => 'moksafowo_product_temp[' . $loop . ']',
				'value'         => $current,
				'label'         => __( '物流溫層', 'mo-ectools' ),
				'description'   => __( '空 / 繼承 = 跟父商品設定走。', 'mo-ectools' ),
				'desc_tip'      => true,
				'wrapper_class' => 'form-row form-row-full moksafowo-product-temp-field',
				'options'       => self::stringify_options( ProductTemp::options( true ) ),
			]
		);
	}

	public static function save_variation_field( int $variation_id, int $loop ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Bound to save_post / quick_edit / bulk_edit; WP core handles nonce + capability check.
		$raw = ( isset( $_POST['moksafowo_product_temp'][ $loop ] ) && is_scalar( $_POST['moksafowo_product_temp'][ $loop ] ) )
			? sanitize_text_field( wp_unslash( (string) $_POST['moksafowo_product_temp'][ $loop ] ) )
			: '';

		if ( '' === $raw ) {
			delete_post_meta( $variation_id, Keys::PRODUCT_TEMP );
			return;
		}
		$value = (int) $raw;
		if ( in_array( $value, [ ProductTemp::NORMAL, ProductTemp::REFRIGERATED, ProductTemp::FROZEN ], true ) ) {
			update_post_meta( $variation_id, Keys::PRODUCT_TEMP, (string) $value );
		} else {
			delete_post_meta( $variation_id, Keys::PRODUCT_TEMP );
		}
	}

	private static function stringify_options( array $options ): array {
		$out = [];
		foreach ( $options as $k => $v ) {
			$out[ (string) $k ] = $v;
		}
		return $out;
	}
}
