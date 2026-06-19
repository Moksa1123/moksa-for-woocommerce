<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Address;

defined( 'ABSPATH' ) || exit;

final class FieldManager {

	private const OPTION_LAYOUT = 'moksafowo_tw_address_field_layout';
	private const OPTION_TOGGLE = 'moksafowo_tw_address_reorder_fields';

	private const DEFAULT_LAYOUT = [
		[
			'key'      => 'last_name',
			'width'    => 50,
			'enabled'  => true,
			'required' => true,
		],
		[
			'key'      => 'first_name',
			'width'    => 50,
			'enabled'  => true,
			'required' => true,
		],
		[
			'key'      => 'company',
			'width'    => 100,
			'enabled'  => false,
			'required' => false,
		],
		[
			'key'      => 'country',
			'width'    => 100,
			'enabled'  => true,
			'required' => true,
		],
		[
			'key'      => 'address_1',
			'width'    => 100,
			'enabled'  => true,
			'required' => true,
		],
		[
			'key'      => 'address_2',
			'width'    => 100,
			'enabled'  => true,
			'required' => false,
		],
		[
			'key'      => 'state',
			'width'    => 50,
			'enabled'  => true,
			'required' => true,
		],
		[
			'key'      => 'city',
			'width'    => 50,
			'enabled'  => true,
			'required' => true,
		],
		[
			'key'      => 'postcode',
			'width'    => 100,
			'enabled'  => true,
			'required' => true,
		],
		[
			'key'      => 'phone',
			'width'    => 100,
			'enabled'  => true,
			'required' => true,
		],
	];

	private const FIELD_LABELS = [
		'first_name' => '名字',
		'last_name'  => '姓氏',
		'company'    => '公司',
		'country'    => '國家 / 地區',
		'address_1'  => '街道地址',
		'address_2'  => '公寓 / 套房',
		'state'      => '縣 / 市',
		'city'       => '鄉 / 鎮 / 區',
		'postcode'   => '郵遞區號',
		'phone'      => '電話',
	];

	private const FIELD_REPOPULATE = [
		'company'   => [
			'label'        => '公司名稱',
			'class'        => [ 'form-row-wide' ],
			'autocomplete' => 'organization',
			'required'     => false,
		],
		'address_2' => [
			'label'        => '公寓 / 套房 / 單位等（選填）',
			'class'        => [ 'form-row-wide' ],
			'autocomplete' => 'address-line2',
			'required'     => false,
		],
		'phone'     => [
			'label'        => '電話',
			'type'         => 'tel',
			'class'        => [ 'form-row-wide' ],
			'autocomplete' => 'tel',
			'validate'     => [ 'phone' ],
			'required'     => false,
		],
	];

	public static function init(): void {
		add_action( 'woocommerce_admin_field_mowp_field_manager', [ __CLASS__, 'render_field' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );

		if ( 'yes' === get_option( self::OPTION_TOGGLE, 'no' ) ) {
			add_filter( 'woocommerce_default_address_fields', [ __CLASS__, 'apply_to_default_fields' ], 20 );
			add_filter( 'woocommerce_billing_fields', [ __CLASS__, 'apply_to_billing_fields' ], 20 );
			add_filter( 'woocommerce_shipping_fields', [ __CLASS__, 'apply_to_shipping_fields' ], 20 );

			// Block 不讀 fields filter，要 override WC core 的 visibility option 才同步。
			add_filter( 'option_woocommerce_checkout_company_field', [ __CLASS__, 'override_wc_company_visibility' ] );
			add_filter( 'option_woocommerce_checkout_address_2_field', [ __CLASS__, 'override_wc_address_2_visibility' ] );
			add_filter( 'option_woocommerce_checkout_phone_field', [ __CLASS__, 'override_wc_phone_visibility' ] );
		}
	}

	public static function override_wc_company_visibility( $value ): string {
		return self::wc_visibility_for( 'company', (string) $value );
	}

	public static function override_wc_address_2_visibility( $value ): string {
		return self::wc_visibility_for( 'address_2', (string) $value );
	}

	public static function override_wc_phone_visibility( $value ): string {
		return self::wc_visibility_for( 'phone', (string) $value );
	}

	private static function wc_visibility_for( string $key, string $original ): string {
		foreach ( self::get_layout() as $item ) {
			if ( $item['key'] !== $key ) {
				continue;
			}
			if ( empty( $item['enabled'] ) ) {
				return 'hidden';
			}
			return ! empty( $item['required'] ) ? 'required' : 'optional';
		}
		return $original;
	}

	public static function get_layout(): array {
		$raw = get_option( self::OPTION_LAYOUT, '' );
		if ( empty( $raw ) ) {
			return self::DEFAULT_LAYOUT;
		}
		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return self::DEFAULT_LAYOUT;
		}
		$default_required = [];
		foreach ( self::DEFAULT_LAYOUT as $d ) {
			$default_required[ $d['key'] ] = $d['required'];
		}
		$clean = [];
		$seen  = [];
		foreach ( $decoded as $item ) {
			$key = isset( $item['key'] ) ? sanitize_key( (string) $item['key'] ) : '';
			if ( ! isset( self::FIELD_LABELS[ $key ] ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$width        = isset( $item['width'] ) ? (int) $item['width'] : 100;
			$enabled      = ! isset( $item['enabled'] ) || (bool) $item['enabled'];
			$required     = isset( $item['required'] ) ? (bool) $item['required'] : ( $default_required[ $key ] ?? true );
			$clean[]      = [
				'key'      => $key,
				'width'    => 50 === $width ? 50 : 100,
				'enabled'  => $enabled,
				'required' => $required,
			];
		}
		foreach ( self::DEFAULT_LAYOUT as $default_item ) {
			if ( ! isset( $seen[ $default_item['key'] ] ) ) {
				$clean[] = $default_item;
			}
		}
		return empty( $clean ) ? self::DEFAULT_LAYOUT : $clean;
	}

	public static function render_field( array $value ): void {
		$layout = self::get_layout();
		$json   = wp_json_encode( $layout );

		$tooltip = ! empty( $value['desc_tip'] )
			? wc_help_tip( (string) $value['desc_tip'] )
			: '';

		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $value['title'] ?? '' ); ?> <?php echo $tooltip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
			</th>
			<td class="forminp">
				<input type="hidden" name="<?php echo esc_attr( self::OPTION_LAYOUT ); ?>" value="<?php echo esc_attr( (string) $json ); ?>" id="moksafowo-tw-field-layout-input" />
				<ul class="moksafowo-tw-field-list" id="moksafowo-tw-field-list">
					<?php
					foreach ( $layout as $item ) :
						$key      = $item['key'];
						$width    = $item['width'];
						$enabled  = ! empty( $item['enabled'] );
						$required = ! empty( $item['required'] );
						$label    = self::FIELD_LABELS[ $key ] ?? $key;
						?>
						<li data-key="<?php echo esc_attr( $key ); ?>" class="<?php echo $enabled ? '' : 'is-disabled'; ?>">
							<span class="moksafowo-tw-drag" aria-hidden="true">&#x2630;</span>
							<label class="moksafowo-tw-enable">
								<input type="checkbox" class="moksafowo-tw-enable-checkbox" <?php checked( $enabled ); ?> />
								<span>啟用</span>
							</label>
							<label class="moksafowo-tw-required">
								<input type="checkbox" class="moksafowo-tw-required-checkbox" <?php checked( $required ); ?> />
								<span>必填</span>
							</label>
							<span class="moksafowo-tw-label"><?php echo esc_html( $label ); ?></span>
							<span class="moksafowo-tw-width-options">
								<label>
									<input type="radio" name="moksafowo-tw-w-<?php echo esc_attr( $key ); ?>" value="50" <?php checked( $width, 50 ); ?> /> 50%
								</label>
								<label>
									<input type="radio" name="moksafowo-tw-w-<?php echo esc_attr( $key ); ?>" value="100" <?php checked( $width, 100 ); ?> /> 100%
								</label>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( ! empty( $value['desc'] ) ) : ?>
					<p class="description"><?php echo wp_kses_post( $value['desc'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public static function enqueue_admin_assets( string $hook ): void {
		if ( ! isset( $_GET['page'] ) || 'wc-settings' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! isset( $_GET['tab'] ) || 'mo-ectools' !== $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! isset( $_GET['section'] ) || 'advanced' !== $_GET['section'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_script(
			'moksafowo-tw-field-manager',
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/Address/assets/admin/js/moksafowo-field-manager.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			MOKSAFOWO_VERSION,
			true
		);
		wp_enqueue_style(
			'moksafowo-tw-field-manager',
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/Address/assets/admin/css/moksafowo-field-manager.css',
			[],
			MOKSAFOWO_VERSION
		);
	}

	public static function apply_to_default_fields( array $fields ): array {
		return self::apply_layout( $fields, '' );
	}

	public static function apply_to_billing_fields( array $fields ): array {
		return self::apply_layout( $fields, 'billing_' );
	}

	public static function apply_to_shipping_fields( array $fields ): array {
		return self::apply_layout( $fields, 'shipping_' );
	}

	private static function apply_layout( array $fields, string $prefix ): array {
		$layout  = self::get_layout();
		$enabled = array_values( array_filter( $layout, static fn ( array $i ): bool => ! empty( $i['enabled'] ) ) );

		foreach ( $layout as $item ) {
			if ( empty( $item['enabled'] ) ) {
				$key = $prefix . $item['key'];
				unset( $fields[ $key ] );
			}
		}

		// re-add fields user enabled but WC removed (e.g. company=hidden in WC settings)
		foreach ( $enabled as $item ) {
			$key = $prefix . $item['key'];
			if ( ! isset( $fields[ $key ] ) && isset( self::FIELD_REPOPULATE[ $item['key'] ] ) ) {
				$fields[ $key ] = self::FIELD_REPOPULATE[ $item['key'] ];
			}
		}

		// 半寬配對只在「相鄰兩個 enabled + 50%」成立；落單 50% fallback wide。
		$classes_by_idx = [];
		$total          = count( $enabled );
		$i              = 0;
		while ( $i < $total ) {
			$cur_w  = $enabled[ $i ]['width'];
			$next_w = $enabled[ $i + 1 ]['width'] ?? null;
			if ( 50 === $cur_w && 50 === $next_w ) {
				$classes_by_idx[ $i ]     = 'form-row-first';
				$classes_by_idx[ $i + 1 ] = 'form-row-last';
				$i                       += 2;
				continue;
			}
			$classes_by_idx[ $i ] = 'form-row-wide';
			++$i;
		}

		foreach ( $enabled as $idx => $item ) {
			$key = $prefix . $item['key'];
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}
			$fields[ $key ]['priority'] = ( $idx + 1 ) * 10;
			$fields[ $key ]['required'] = ! empty( $item['required'] );

			$existing = isset( $fields[ $key ]['class'] ) && is_array( $fields[ $key ]['class'] )
				? $fields[ $key ]['class']
				: [];
			$existing = array_diff( $existing, [ 'form-row-first', 'form-row-last', 'form-row-wide' ] );
			if ( isset( $classes_by_idx[ $idx ] ) ) {
				$existing[] = $classes_by_idx[ $idx ];
			}
			$fields[ $key ]['class'] = array_values( $existing );
		}

		return $fields;
	}
}
