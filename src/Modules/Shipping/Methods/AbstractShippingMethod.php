<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Methods;

use MoksaWeb\Mowc\Modules\Shipping\Helpers\EvaluateCost;
use MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp;

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
		$this->title       = $this->get_option( 'title', $this->method_title );
		$this->tax_status  = $this->get_option( 'tax_status', 'taxable' );
		$this->cost        = $this->get_option( 'cost', '0' );
		$this->free_min    = $this->get_option( 'free_min', '' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function supported_temperatures(): array {
		return [ ProductTemp::NORMAL => __( '常溫', 'mo-ectools' ) ];
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
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		return apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
			'moksafowo_shipping_method_is_available',
			empty( $missing ),
			$package,
			$this
		);
	}

	public function init_form_fields(): void {
		$this->instance_form_fields = [
			'title' => [
				'title'       => __( '物流名稱', 'mo-ectools' ),
				'type'        => 'text',
				'description' => __( '結帳頁顯示的物流名稱', 'mo-ectools' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
			],
			'tax_status' => [
				'title'   => __( '稅率', 'mo-ectools' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'taxable',
				'options' => [
					'taxable' => __( '應稅', 'mo-ectools' ),
					'none'    => _x( '不收稅', 'Tax status', 'mo-ectools' ),
				],
			],
			'cost' => [
				'title'       => __( '運費', 'mo-ectools' ),
				'type'        => 'text',
				'placeholder' => '0',
				'default'     => '0',
				'description' => self::cost_field_description(),
				'desc_tip'    => false,
			],
			'free_min' => [
				'title'       => __( '免運門檻', 'mo-ectools' ),
				'type'        => 'text',
				'placeholder' => __( '訂單金額 ≥ 此值免運。空白 = 不啟用免運', 'mo-ectools' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'split_shipping_fee' => [
				'title'       => __( '依溫層分別計算運費（進階）', 'mo-ectools' ),
				'type'        => 'checkbox',
				'label'       => __( '多溫層訂單，每個溫層的物流單帶該溫層自己的運費', 'mo-ectools' ),
				'default'     => 'no',
				'description' => __( '預設關閉：cost formula 依整車一次性算出單一運費，整單運費全數塞給第一張物流單（COD 第一張代收所有運費）。開啟後：cost formula 會對每個溫層的商品「分別」評估一次，顧客結帳顯示的運費 = 各溫層運費總和（多溫層 = 多份基本費），每張物流單的 GoodsAmount / COD 代收金額會帶該溫層自己評估出來的運費。需搭配 `[moksafowo_addfee cool_2="X" cool_3="Y"]` 的 cost formula 才能讓不同溫層算出不同運費。', 'mo-ectools' ),
				'desc_tip'    => false,
			],
			'breakdown_enabled' => [
				'title'       => __( '結帳顯示溫層拆解（進階）', 'mo-ectools' ),
				'type'        => 'checkbox',
				'label'       => __( '多溫層訂單，物流名稱後面顯示各溫層件數與運費', 'mo-ectools' ),
				'default'     => 'yes',
				'description' => __( '預設開啟：結帳頁的物流選項會在物流名稱後面附上「🟫 常溫 ×1　🟦 冷藏 ×2」之類的拆解。純常溫訂單、或單溫層 method 不會顯示。', 'mo-ectools' ),
				'desc_tip'    => false,
			],
			'breakdown_marker_normal' => [
				'title'       => __( '常溫 marker', 'mo-ectools' ),
				'type'        => 'text',
				'default'     => '🟫',
				'description' => __( '拆解文字前的視覺符號（emoji 或任意字元）。留空 = 用「·」', 'mo-ectools' ),
				'desc_tip'    => true,
			],
			'breakdown_marker_refrigerated' => [
				'title'       => __( '冷藏 marker', 'mo-ectools' ),
				'type'        => 'text',
				'default'     => '🟦',
				'description' => __( '拆解文字前的視覺符號。留空 = 用「·」', 'mo-ectools' ),
				'desc_tip'    => true,
			],
			'breakdown_marker_frozen' => [
				'title'       => __( '冷凍 marker', 'mo-ectools' ),
				'type'        => 'text',
				'default'     => '🟪',
				'description' => __( '拆解文字前的視覺符號。留空 = 用「·」', 'mo-ectools' ),
				'desc_tip'    => true,
			],
			'breakdown_separator' => [
				'title'       => __( '拆解分隔符', 'mo-ectools' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'fullspace',
				'options'     => [
					'fullspace' => __( '全形空格（　）— 視覺隔開但不換行', 'mo-ectools' ),
					'pipe'      => __( '直線（｜）— 明確分隔', 'mo-ectools' ),
					'dot'       => __( '點（·）— 簡潔', 'mo-ectools' ),
					'comma'     => __( '頓號（、）— 中文閱讀習慣', 'mo-ectools' ),
				],
				'description' => __( '多溫層之間如何分隔。Block 結帳會把 \n 換行 normalize 成 space，所以走全形字元最穩。', 'mo-ectools' ),
				'desc_tip'    => false,
			],
			'breakdown_format' => [
				'title'       => __( '拆解顯示密度', 'mo-ectools' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'full',
				'options'     => [
					'full'    => __( '完整 — 🟫 常溫 ×3　🟦 冷藏 ×5　🟪 冷凍 ×2', 'mo-ectools' ),
					'compact' => __( '精簡 — 🟫×3 🟦×5 🟪×2（emoji 已有顏色語意，省 30%）', 'mo-ectools' ),
					'summary' => __( '摘要 — 🟫🟦🟪 共 10 件（最短，多溫層只看到圖示組合）', 'mo-ectools' ),
				],
				'description' => __( '多 qty + 多溫層訂單，完整版可能在結帳頁因為太長 wrap 被斷成兩行（「冷藏」跟「×5」可能拆到不同行）。精簡版去掉中文 label 用 emoji 顏色辨識，多半能一行顯示。摘要版只顯示溫層 emoji 組合 + 總件數，最節省空間。', 'mo-ectools' ),
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
		$keys = [
			ProductTemp::NORMAL       => 'breakdown_marker_normal',
			ProductTemp::REFRIGERATED => 'breakdown_marker_refrigerated',
			ProductTemp::FROZEN       => 'breakdown_marker_frozen',
		];
		$key   = $keys[ $temp ] ?? null;
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
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
			$args = apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
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
			$args  = [ 'temps' => [ (int) $temp ], 'qty' => $qty, 'weight' => $weight ];
			$total += EvaluateCost::evaluate( $formula, $args );
		}
		return $total;
	}

	
	public function evaluate_cost_for_temp( int $temp, int $qty, float $weight ): float {
		$formula = (string) $this->get_option( 'cost', '0' );
		$args    = [ 'temps' => [ $temp ], 'qty' => $qty, 'weight' => $weight ];
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
			__( '支援純數字或含 shortcode 的算式，計算結果為新台幣金額。', 'mo-ectools' ),
			__( '範例：', 'mo-ectools' ),
			__( '<code>100</code> — 固定 100 元', 'mo-ectools' ),
			__( '<code>100 + [moksafowo_addfee cool_2="50" cool_3="100"]</code> — 常溫 100、含冷藏訂單 +50、含冷凍訂單 +100', 'mo-ectools' ),
			__( '<code>[moksafowo_addfee temp_1="100" temp_2="150" temp_3="200"]</code> — 常溫 100 / 冷藏 150 / 冷凍 200（搭配下方「依溫層分別計算運費」勾起來，每張物流單就帶該溫層的價格）', 'mo-ectools' ),
			__( '<code>50 + [moksafowo_addfee qty="10"]</code> — 50 + 每件 10 元', 'mo-ectools' ),
			__( '<code>[moksafowo_addfee weight="20"]</code> — 每公斤 20 元', 'mo-ectools' ),
			__( '屬性：<code>temp_1</code>=常溫 base、<code>temp_2</code>=冷藏 base、<code>temp_3</code>=冷凍 base、<code>cool</code>=任一冷的就加、<code>cool_2</code>=冷藏額外加、<code>cool_3</code>=冷凍額外加、<code>qty</code>=每件加、<code>weight</code>=每公斤加。', 'mo-ectools' ),
		];
		return wp_kses_post( implode( '<br>', $lines ) );
	}
}
