<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Statuses;

defined( 'ABSPATH' ) || exit;

final class Registrar {

	public const STATUSES = [
		'mo-shipped' => [
			'label'    => '已出貨',
			'badge'    => '已出貨',
			'wc_label' => '已出貨 <span class="count">(%s)</span>',
			'color'    => '#2271b1',
			'public'   => true,
		],
		'mo-cvs-arrived' => [
			'label'    => '已到店待取',
			'badge'    => '到店待取',
			'wc_label' => '已到店待取 <span class="count">(%s)</span>',
			'color'    => '#dba617',
			'public'   => true,
		],
		'mo-store-closed' => [
			'label'    => '門市關轉',
			'badge'    => '門市關轉',
			'wc_label' => '門市關轉 <span class="count">(%s)</span>',
			'color'    => '#996800',
			'public'   => true,
		],
	];

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_post_statuses' ] );
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_to_wc_order_statuses' ] );
		add_filter( 'woocommerce_register_shop_order_post_statuses', [ __CLASS__, 'add_to_shop_order_post_statuses' ] );
		add_filter( 'woocommerce_order_is_paid_statuses', [ __CLASS__, 'mark_post_payment_statuses_as_paid' ] );
		add_filter( 'wc_order_is_editable', [ __CLASS__, 'lock_order_editing_after_shipping' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'inject_admin_badge_css' ] );

		// WC 不自動將自訂狀態加入 dropdown，需兩個 filter 同時掛（legacy + HPOS DataViews）。
		add_filter( 'bulk_actions-edit-shop_order', [ __CLASS__, 'add_bulk_actions' ] );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'add_bulk_actions' ] );

		// Custom field type for compact color picker grid + manual save
		add_action( 'woocommerce_admin_field_mowp_status_color_grid', [ __CLASS__, 'render_color_grid_field' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_color_grid_assets' ] );
		add_action( 'woocommerce_update_options_' . \MoksaWeb\Mowc\Settings\SettingsTab::TAB_ID, [ __CLASS__, 'save_color_grid' ] );
	}

	public static function color_status_labels(): array {
		return [
			'processing'      => __( '處理中', 'mo-ectools' ),
			'mo-shipped'      => __( '已出貨', 'mo-ectools' ),
			'mo-cvs-arrived'  => __( '已到店待取', 'mo-ectools' ),
			'mo-store-closed' => __( '門市關轉', 'mo-ectools' ),
			'completed'       => __( '完成', 'mo-ectools' ),
			'cancelled'       => __( '已取消 / 失敗', 'mo-ectools' ),
			'refunded'        => __( '退款', 'mo-ectools' ),
			'on-hold'         => __( '保留 / 待付款', 'mo-ectools' ),
		];
	}

	public static function color_status_defaults(): array {
		return [
			'processing'      => [ '#dbeafe', '#1e40af' ],
			'mo-shipped'      => [ '#1d4ed8', '#ffffff' ],
			'mo-cvs-arrived'  => [ '#d97706', '#ffffff' ],
			'mo-store-closed' => [ '#b45309', '#ffffff' ],
			'completed'       => [ '#d1fae5', '#065f46' ],
			'cancelled'       => [ '#fee2e2', '#991b1b' ],
			'refunded'        => [ '#e2e8f0', '#475569' ],
			'on-hold'         => [ '#fef3c7', '#92400e' ],
		];
	}

	public static function enqueue_color_grid_assets( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only 畫面判斷，無狀態變更。
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( \MoksaWeb\Mowc\Settings\SettingsTab::TAB_ID !== $tab ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		$css = <<<'CSS'
.mo-status-color-grid{border-collapse:collapse;width:auto;}
.mo-status-color-grid th{text-align:left;padding:6px 24px 6px 0;font-weight:600;color:#1d2327;font-size:12px;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #dcdcde;}
.mo-status-color-grid td{padding:8px 24px 8px 0;border-bottom:1px solid #f0f0f1;vertical-align:middle;}
.mo-status-color-grid th:last-child,
.mo-status-color-grid td:last-child{padding-right:0;}
.mo-status-color-grid tr:last-child td{border-bottom:0;}
.mo-status-color-grid .mo-status-name{font-weight:500;color:#1d2327;white-space:nowrap;}
.mo-status-color-grid .wp-picker-container{vertical-align:middle;}
.mo-status-color-grid .wp-color-result{margin:0;}
.mo-status-color-grid .mo-preview{display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:500;line-height:1;white-space:nowrap;min-width:80px;text-align:center;border:1px solid rgba(0,0,0,.04);}
CSS;
		wp_register_style( 'mo-status-color-grid', false, [ 'wp-color-picker' ], MOWC_VERSION );
		wp_enqueue_style( 'mo-status-color-grid' );
		wp_add_inline_style( 'mo-status-color-grid', $css );

		$js = <<<'JS'
jQuery(function($){
	function updatePreview($input){
		var $row = $input.closest('tr');
		var bg = $row.find('input[data-target="bg"]').val() || '#dbeafe';
		var fg = $row.find('input[data-target="fg"]').val() || '#1e40af';
		$row.find('.mo-preview').css({background: bg, color: fg});
	}
	$('.mo-status-color-input').each(function(){
		var $input = $(this);
		$input.wpColorPicker({
			change: function(e, ui){ setTimeout(function(){ updatePreview($(e.target)); }, 0); },
			clear: function(){ setTimeout(function(){ updatePreview($input); }, 0); }
		});
	});
});
JS;
		wp_register_script( 'mo-status-color-grid', false, [ 'jquery', 'wp-color-picker' ], MOWC_VERSION, true );
		wp_enqueue_script( 'mo-status-color-grid' );
		wp_add_inline_script( 'mo-status-color-grid', $js );
	}

	public static function render_color_grid_field( array $field ): void {
		$labels   = self::color_status_labels();
		$defaults = self::color_status_defaults();
		$desc     = isset( $field['desc'] ) ? (string) $field['desc'] : '';

		echo '<tr valign="top"><td colspan="2">';
		if ( '' !== $desc ) {
			echo '<p style="margin:0 0 12px;color:#646970;">' . esc_html( $desc ) . '</p>';
		}
		?>
		<table class="mo-status-color-grid">
			<thead><tr>
				<th><?php esc_html_e( '訂單狀態', 'mo-ectools' ); ?></th>
				<th><?php esc_html_e( '背景', 'mo-ectools' ); ?></th>
				<th><?php esc_html_e( '文字', 'mo-ectools' ); ?></th>
				<th><?php esc_html_e( '預覽', 'mo-ectools' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $labels as $slug => $label ) :
					$key       = 'mo_status_color_' . str_replace( '-', '_', $slug );
					[ $def_bg, $def_fg ] = $defaults[ $slug ];
					$bg        = (string) get_option( $key . '_bg', $def_bg );
					$fg        = (string) get_option( $key . '_fg', $def_fg );
				?>
					<tr data-status="<?php echo esc_attr( $slug ); ?>">
						<td class="mo-status-name"><?php echo esc_html( $label ); ?></td>
						<td><input type="text" class="mo-status-color-input" data-target="bg" name="<?php echo esc_attr( $key . '_bg' ); ?>" value="<?php echo esc_attr( $bg ); ?>" data-default-color="<?php echo esc_attr( $def_bg ); ?>"></td>
						<td><input type="text" class="mo-status-color-input" data-target="fg" name="<?php echo esc_attr( $key . '_fg' ); ?>" value="<?php echo esc_attr( $fg ); ?>" data-default-color="<?php echo esc_attr( $def_fg ); ?>"></td>
						<td><span class="mo-preview" style="background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $fg ); ?>;"><?php echo esc_html( $label ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		echo '</td></tr>';
	}

	public static function save_color_grid(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC settings nonce 已由 WC_Admin_Settings::save() 驗
		foreach ( array_keys( self::color_status_labels() ) as $slug ) {
			$key = 'mo_status_color_' . str_replace( '-', '_', $slug );
			foreach ( [ '_bg', '_fg' ] as $suffix ) {
				$post_key = $key . $suffix;
				if ( ! isset( $_POST[ $post_key ] ) ) {
					continue;
				}
				$val = sanitize_hex_color( wp_unslash( $_POST[ $post_key ] ) );
				if ( $val ) {
					update_option( $post_key, $val );
				}
			}
		}
		// phpcs:enable
	}

	public static function is_status_enabled( string $slug ): bool {
		return 'yes' === get_option( 'mo_shipping_status_' . str_replace( '-', '_', $slug ) . '_enabled', 'yes' );
	}

	/**
	 * Translated status label as a literal string so the i18n parser can extract it.
	 */
	private static function status_label( string $slug ): string {
		switch ( $slug ) {
			case 'mo-shipped':
				return __( '已出貨', 'mo-ectools' );
			case 'mo-cvs-arrived':
				return __( '已到店待取', 'mo-ectools' );
			case 'mo-store-closed':
				return __( '門市關轉', 'mo-ectools' );
		}
		return $slug;
	}

	/**
	 * Translated label_count noop with literal msgids so the i18n parser can extract them.
	 *
	 * @return array{0:string,1:string,singular:string,plural:string,context:?string,domain:?string}
	 */
	private static function status_label_count( string $slug ): array {
		switch ( $slug ) {
			case 'mo-cvs-arrived':
				/* translators: %s: number of orders in this status */
				return _n_noop( '已到店待取 <span class="count">(%s)</span>', '已到店待取 <span class="count">(%s)</span>', 'mo-ectools' );
			case 'mo-store-closed':
				/* translators: %s: number of orders in this status */
				return _n_noop( '門市關轉 <span class="count">(%s)</span>', '門市關轉 <span class="count">(%s)</span>', 'mo-ectools' );
			case 'mo-shipped':
			default:
				/* translators: %s: number of orders in this status */
				return _n_noop( '已出貨 <span class="count">(%s)</span>', '已出貨 <span class="count">(%s)</span>', 'mo-ectools' );
		}
	}

	public static function register_post_statuses(): void {
		foreach ( array_keys( self::STATUSES ) as $slug ) {
			register_post_status(
				'wc-' . $slug,
				[
					'label'                     => self::status_label( $slug ),
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => self::status_label_count( $slug ),
				]
			);
		}
	}

	public static function add_to_wc_order_statuses( array $statuses ): array {
		$out = [];
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
				if ( 'wc-completed' === $key ) {
				foreach ( array_keys( self::STATUSES ) as $slug ) {
					if ( ! self::is_status_enabled( $slug ) ) {
						continue;
					}
					$out[ 'wc-' . $slug ] = self::status_label( $slug );
				}
			}
		}
		foreach ( array_keys( self::STATUSES ) as $slug ) {
			if ( ! self::is_status_enabled( $slug ) ) {
				continue;
			}
			$key = 'wc-' . $slug;
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = self::status_label( $slug );
			}
		}
		return $out;
	}

	public static function add_to_shop_order_post_statuses( array $statuses ): array {
		foreach ( array_keys( self::STATUSES ) as $slug ) {
			if ( ! self::is_status_enabled( $slug ) ) {
				continue;
			}
			$statuses[ 'wc-' . $slug ] = [
				'label'                     => self::status_label( $slug ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => self::status_label_count( $slug ),
			];
		}
		return $statuses;
	}

	public static function mark_post_payment_statuses_as_paid( array $paid ): array {
		$paid[] = 'mo-shipped';
		$paid[] = 'mo-cvs-arrived';
		$paid[] = 'mo-store-closed';
		return $paid;
	}

	public static function lock_order_editing_after_shipping( bool $editable, $order ): bool {
		if ( ! $order ) {
			return $editable;
		}
		$status = method_exists( $order, 'get_status' ) ? $order->get_status() : '';
		if ( in_array( $status, [ 'mo-shipped', 'mo-cvs-arrived', 'mo-store-closed' ], true ) ) {
			return false;
		}
		return $editable;
	}

	public static function add_bulk_actions( array $actions ): array {
		foreach ( array_keys( self::STATUSES ) as $slug ) {
			if ( ! self::is_status_enabled( $slug ) ) {
				continue;
			}
			$actions[ 'mark_' . $slug ] = sprintf(
				/* translators: %s: order status label */
				__( '變更狀態為：%s', 'mo-ectools' ),
				self::status_label( $slug )
			);
		}
		return $actions;
	}

	private static ?array $palette_cache = null;

	public static function inject_admin_badge_css(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$relevant = in_array( $screen->id, [ 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' ], true );
		if ( ! $relevant ) {
			return;
		}

		$palette = self::status_palette();

		$css = '';
		foreach ( $palette as $slug => $colors ) {
			[ $bg, $fg ] = $colors;
			// 只 target 真的 badge（有 .order-status class），不波及只帶 .status-X 的 <tr>。
			$css .= sprintf(
				'.order-status.status-%s, mark.order-status.status-%s { background:%s !important; color:%s !important; border-color:%s !important; }',
				esc_attr( $slug ),
				esc_attr( $slug ),
				esc_attr( $bg ),
				esc_attr( $fg ),
				esc_attr( $bg )
			);
		}
		wp_register_style( 'mo-status-badges', false, [], MOWC_VERSION );
		wp_enqueue_style( 'mo-status-badges' );
		wp_add_inline_style( 'mo-status-badges', $css );
	}

	private static function sanitize_color( string $color ): string {
		$color = trim( $color );
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ) {
			return $color;
		}
		return '';
	}

	
	private static function status_palette(): array {
		if ( null !== self::$palette_cache ) {
			return self::$palette_cache;
		}

		// failed 共用 cancelled、pending 共用 on-hold 的 option key。
		$defaults = [
			'processing'      => [ '#dbeafe', '#1e40af' ],
			'completed'       => [ '#d1fae5', '#065f46' ],
			'cancelled'       => [ '#fee2e2', '#991b1b' ],
			'refunded'        => [ '#e2e8f0', '#475569' ],
			'failed'          => [ '#fee2e2', '#991b1b' ],
			'on-hold'         => [ '#fef3c7', '#92400e' ],
			'pending'         => [ '#fef3c7', '#92400e' ],
			'mo-shipped'      => [ '#1d4ed8', '#ffffff' ],
			'mo-cvs-arrived'  => [ '#d97706', '#ffffff' ],
			'mo-store-closed' => [ '#b45309', '#ffffff' ],
		];
		$option_keys = [
			'processing'      => 'mo_status_color_processing',
			'completed'       => 'mo_status_color_completed',
			'cancelled'       => 'mo_status_color_cancelled',
			'failed'          => 'mo_status_color_cancelled',
			'refunded'        => 'mo_status_color_refunded',
			'on-hold'         => 'mo_status_color_on_hold',
			'pending'         => 'mo_status_color_on_hold',
			'mo-shipped'      => 'mo_status_color_mo_shipped',
			'mo-cvs-arrived'  => 'mo_status_color_mo_cvs_arrived',
			'mo-store-closed' => 'mo_status_color_mo_store_closed',
		];

		$palette = [];
		foreach ( $defaults as $slug => $defaultColors ) {
			[ $default_bg, $default_fg ] = $defaultColors;
			$key                         = $option_keys[ $slug ];
			$bg                          = self::sanitize_color( (string) get_option( $key . '_bg', $default_bg ) );
			$fg                          = self::sanitize_color( (string) get_option( $key . '_fg', $default_fg ) );
			$palette[ $slug ]            = [
				'' !== $bg ? $bg : $default_bg,
				'' !== $fg ? $fg : $default_fg,
			];
		}

		self::$palette_cache = $palette;
		return $palette;
	}
}
