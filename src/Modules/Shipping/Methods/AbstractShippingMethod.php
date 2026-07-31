<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Methods;

use Moksafowo\Modules\Shipping\Helpers\EvaluateCost;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

abstract class AbstractShippingMethod extends \WC_Shipping_Method {

	protected $cost = '0';

	protected $free_min = '';

	public function __construct( $instance_id = 0 ) {
		$this->instance_id = absint( $instance_id );
		$this->supports    = [ 'shipping-zones', 'instance-settings', 'instance-settings-modal' ];
		$this->init();
	}

	protected function init(): void {
		$this->init_form_fields();
		$this->init_settings();
		$this->title      = $this->get_option( 'title', $this->method_title );
		$this->tax_status = $this->get_option( 'tax_status', 'taxable' );
		$this->cost       = $this->get_option( 'cost', '0' );
		$this->free_min   = $this->get_option( 'free_min', '' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function supported_temperatures(): array {
		return [ ProductTemp::NORMAL => __( 'Ambient', 'moksa-for-woocommerce' ) ];
	}

	public function is_available( $package ): bool {
		// Parent (\WC_Shipping_Method::is_available) 走 enabled / shipping class 過濾
		$available = parent::is_available( $package );
		if ( ! $available ) {
			return false;
		}
		// cart 商品溫層必須都在 method's supported 集合內（沒不支援的）
		$cart_temps = ProductTemp::temps_in_package( $package );
		$supported  = array_map( 'intval', array_keys( $this->supported_temperatures() ) );
		$missing    = array_diff( $cart_temps, $supported );
		// hook 給 user 蓋掉行為（例如商家想保守不過濾） — 預設 missing 非空就 hide
		return apply_filters(
			'moksafowo_shipping_method_is_available',
			empty( $missing ),
			$package,
			$this
		);
	}

	public function init_form_fields(): void {
		$this->instance_form_fields = [
			'title'                         => [
				'title'       => __( 'Shipping method name', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The shipping method name shown at checkout', 'moksa-for-woocommerce' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
			],
			'tax_status'                    => [
				'title'   => __( 'Tax status', 'moksa-for-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'taxable',
				'options' => [
					'taxable' => __( 'Taxable', 'moksa-for-woocommerce' ),
					'none'    => _x( 'None', 'Tax status', 'moksa-for-woocommerce' ),
				],
			],
			'cost'                          => [
				'title'       => __( 'Shipping', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => '0',
				'default'     => '0',
				'description' => self::cost_field_description(),
				'desc_tip'    => false,
			],
			'free_min'                      => [
				'title'       => __( 'Free shipping threshold', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => __( 'Orders at or above this amount ship free. Leave blank to turn free shipping off', 'moksa-for-woocommerce' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'split_shipping_fee'            => [
				'title'       => __( 'Calculate shipping per temperature zone (advanced)', 'moksa-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'On multi-zone orders, each shipment carries the shipping cost of its own temperature zone', 'moksa-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Off by default: the cost formula is evaluated once for the whole cart and the entire shipping cost goes onto the first shipment, so with cash on delivery the first parcel collects all of it. When on, the cost formula is evaluated separately for the items in each temperature zone. The cost the customer sees at checkout is the sum of every zone, so a multi-zone order pays the base rate more than once, and each shipment carries the amount worked out for its own zone. You also need a cost formula such as `[moksafowo_addfee cool_2="X" cool_3="Y"]` for the zones to produce different amounts.', 'moksa-for-woocommerce' ),
				'desc_tip'    => false,
			],
			'breakdown_enabled'             => [
				'title'       => __( 'Show the temperature zone breakdown at checkout (advanced)', 'moksa-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'On multi-zone orders, show the item count and cost of each temperature zone after the method name', 'moksa-for-woocommerce' ),
				'default'     => 'yes',
				'description' => __( 'On by default: the shipping options at checkout append a breakdown such as “🟫 Ambient ×1　🟦 Chilled ×2” after the method name. Ambient-only orders and single-zone methods show nothing.', 'moksa-for-woocommerce' ),
				'desc_tip'    => false,
			],
			'breakdown_marker_normal'       => [
				'title'       => __( 'Ambient marker', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '🟫',
				'description' => __( 'The symbol shown before the breakdown text — an emoji or any character. Leave blank to use “·”', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'breakdown_marker_refrigerated' => [
				'title'       => __( 'Chilled marker', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '🟦',
				'description' => __( 'The symbol shown before the breakdown text. Leave blank to use “·”', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'breakdown_marker_frozen'       => [
				'title'       => __( 'Frozen marker', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '🟪',
				'description' => __( 'The symbol shown before the breakdown text. Leave blank to use “·”', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'breakdown_separator'           => [
				'title'       => __( 'Breakdown separator', 'moksa-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'fullspace',
				'options'     => [
					'fullspace' => __( 'Full-width space (　) — separates visually without wrapping', 'moksa-for-woocommerce' ),
					'pipe'      => __( 'Vertical bar (｜) — a clear divider', 'moksa-for-woocommerce' ),
					'dot'       => __( 'Middle dot (·) — compact', 'moksa-for-woocommerce' ),
					'comma'     => __( 'Ideographic comma (、) — natural in Chinese', 'moksa-for-woocommerce' ),
				],
				'description' => __( 'How the temperature zones are separated. Block checkout turns \\n line breaks into spaces, so full-width characters are the safest choice.', 'moksa-for-woocommerce' ),
				'desc_tip'    => false,
			],
			'breakdown_format'              => [
				'title'       => __( 'Breakdown detail level', 'moksa-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'full',
				'options'     => [
					'full'    => __( 'Full — 🟫 Ambient ×3　🟦 Chilled ×5　🟪 Frozen ×2', 'moksa-for-woocommerce' ),
					'compact' => __( 'Compact — 🟫×3 🟦×5 🟪×2 (the emoji already carry the color meaning, about 30% shorter)', 'moksa-for-woocommerce' ),
					'summary' => __( 'Summary — 🟫🟦🟪 10 items in total (shortest; a multi-zone order shows only the icons)', 'moksa-for-woocommerce' ),
				],
				'description' => __( 'On orders with many items across several zones the full version can be too long and wrap onto two lines at checkout, splitting “Chilled” from “×5”. The compact version drops the labels and relies on the emoji colors, which usually fits on one line. The summary version shows only the zone icons and the total item count, which takes the least space.', 'moksa-for-woocommerce' ),
				'desc_tip'    => false,
			],
		];
	}

	public function breakdown_enabled(): bool {
		return 'yes' === (string) $this->get_option( 'breakdown_enabled', 'yes' );
	}

	public function breakdown_marker( int $temp ): string {
		$defaults = [
			ProductTemp::NORMAL       => '🟫',
			ProductTemp::REFRIGERATED => '🟦',
			ProductTemp::FROZEN       => '🟪',
		];
		$keys     = [
			ProductTemp::NORMAL       => 'breakdown_marker_normal',
			ProductTemp::REFRIGERATED => 'breakdown_marker_refrigerated',
			ProductTemp::FROZEN       => 'breakdown_marker_frozen',
		];
		$key      = $keys[ $temp ] ?? null;
		if ( null === $key ) {
			return '·';
		}
		$value = trim( (string) $this->get_option( $key, $defaults[ $temp ] ?? '·' ) );
		return '' === $value ? '·' : $value;
	}

	public function breakdown_separator(): string {
		$map = [
			'fullspace' => '　',
			'pipe'      => '　｜　',
			'dot'       => ' · ',
			'comma'     => '、',
		];
		$key = (string) $this->get_option( 'breakdown_separator', 'fullspace' );
		return $map[ $key ] ?? '　';
	}

	public function breakdown_format(): string {
		$key = (string) $this->get_option( 'breakdown_format', 'full' );
		return in_array( $key, [ 'full', 'compact', 'summary' ], true ) ? $key : 'full';
	}

	public function split_shipping_fee_enabled(): bool {
		return 'yes' === (string) $this->get_option( 'split_shipping_fee', 'no' );
	}

	public function calculate_shipping( $package = [] ): void {
		$formula = (string) $this->get_option( 'cost', '0' );

		if ( $this->split_shipping_fee_enabled() ) {
			$cost = $this->evaluate_cost_per_temp_from_package( $formula, $package );
		} else {
			$args = apply_filters(
				'moksafowo_shipping_evaluate_cost_args',
				[
					'temps'  => ProductTemp::temps_in_package( $package ),
					'qty'    => self::package_qty( $package ),
					'weight' => self::package_weight( $package ),
				],
				$package,
				$this
			);
			$cost = EvaluateCost::evaluate( $formula, $args );
		}

		$free_min = $this->get_option( 'free_min', '' );
		if ( '' !== $free_min ) {
			$cart_total = (float) ( $package['contents_cost'] ?? 0 );
			if ( $cart_total >= (float) $free_min ) {
				$cost = 0.0;
			}
		}

		$this->add_rate(
			[
				'id'      => $this->get_rate_id(),
				'label'   => $this->title,
				'cost'    => $cost,
				'package' => $package,
			]
		);
	}


	protected function evaluate_cost_per_temp_from_package( string $formula, array $package ): float {
		$contents = $package['contents'] ?? [];
		if ( ! is_array( $contents ) || empty( $contents ) ) {
			return 0.0;
		}
		$groups = [];
		foreach ( $contents as $key => $item ) {
			$product = $item['data'] ?? null;
			$temp    = $product instanceof \WC_Product ? ProductTemp::for_product( $product ) : ProductTemp::NORMAL;
			if ( $temp <= 0 ) {
				$temp = ProductTemp::NORMAL;
			}
			$groups[ $temp ][ $key ] = $item;
		}
		$total = 0.0;
		foreach ( $groups as $temp => $items ) {
			$qty    = 0;
			$weight = 0.0;
			foreach ( $items as $item ) {
				$q       = (int) ( $item['quantity'] ?? 0 );
				$qty    += $q;
				$product = $item['data'] ?? null;
				if ( $product instanceof \WC_Product ) {
					$w = (float) ( $product->get_weight() ?: 0 );
					if ( $w > 0 ) {
						$weight += $w * $q;
					}
				}
			}
			$args   = [
				'temps'  => [ (int) $temp ],
				'qty'    => $qty,
				'weight' => $weight,
			];
			$total += EvaluateCost::evaluate( $formula, $args );
		}
		return $total;
	}


	public function evaluate_cost_for_temp( int $temp, int $qty, float $weight ): float {
		$formula = (string) $this->get_option( 'cost', '0' );
		$args    = [
			'temps'  => [ $temp ],
			'qty'    => $qty,
			'weight' => $weight,
		];
		return EvaluateCost::evaluate( $formula, $args );
	}

	protected static function package_qty( array $package ): int {
		$qty      = 0;
		$contents = $package['contents'] ?? [];
		if ( ! is_array( $contents ) ) {
			return 0;
		}
		foreach ( $contents as $item ) {
			$qty += (int) ( $item['quantity'] ?? 0 );
		}
		return $qty;
	}

	protected static function package_weight( array $package ): float {
		$weight   = 0.0;
		$contents = $package['contents'] ?? [];
		if ( ! is_array( $contents ) ) {
			return 0.0;
		}
		foreach ( $contents as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof \WC_Product ) {
				$qty     = (int) ( $item['quantity'] ?? 0 );
				$pweight = (float) ( $product->get_weight() ?: 0 );
				$weight += $pweight * $qty;
			}
		}
		return $weight;
	}

	protected static function cost_field_description(): string {
		$lines = [
			__( 'Accepts a plain number or a formula containing shortcodes. The result is an amount in New Taiwan dollars.', 'moksa-for-woocommerce' ),
			__( 'Examples:', 'moksa-for-woocommerce' ),
			__( '<code>100</code> — a flat NT$100', 'moksa-for-woocommerce' ),
			__( '<code>100 + [moksafowo_addfee cool_2="50" cool_3="100"]</code> — NT$100 for ambient, plus 50 if the order contains chilled items and plus 100 if it contains frozen items', 'moksa-for-woocommerce' ),
			__( '<code>[moksafowo_addfee temp_1="100" temp_2="150" temp_3="200"]</code> — 100 ambient / 150 chilled / 200 frozen. Tick “Calculate shipping per temperature zone” below and each shipment carries the price of its own zone', 'moksa-for-woocommerce' ),
			__( '<code>50 + [moksafowo_addfee qty="10"]</code> — 50 plus NT$10 per item', 'moksa-for-woocommerce' ),
			__( '<code>[moksafowo_addfee weight="20"]</code> — NT$20 per kilogram', 'moksa-for-woocommerce' ),
			__( 'Attributes: <code>temp_1</code> = ambient base, <code>temp_2</code> = chilled base, <code>temp_3</code> = frozen base, <code>cool</code> = added when anything needs refrigeration, <code>cool_2</code> = extra for chilled, <code>cool_3</code> = extra for frozen, <code>qty</code> = per item, <code>weight</code> = per kilogram.', 'moksa-for-woocommerce' ),
		];
		return wp_kses_post( implode( '<br>', $lines ) );
	}
}
