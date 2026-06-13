<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Frontend;

use MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class CartTempLabel {

	// instance_id → WC_Shipping_Method（cart/checkout 試算每 rate 都會 fire
	// WC_Shipping_Zones::get_shipping_method，30+ rate × N package 不 cache 會
	// 重複 DB hit + method instantiation）
	private static array $zone_method_cache = [];

	public static function init(): void {
		// 過濾掉不支援 cart 商品溫層的 mowp method + 對留下的 rate mutate label 加 breakdown
		// （Classic checkout 用 wc_cart_totals_shipping_method_label() 讀 $rate->get_label()，
		// Block checkout 走 Store API 序列化 $rate->name = $rate->get_label() — 都吃同源）
		add_filter( 'woocommerce_package_rates', [ __CLASS__, 'filter_unsupported_temp_methods' ], 100, 2 );
		add_filter( 'woocommerce_package_rates', [ __CLASS__, 'append_breakdown_to_rates' ], 110, 2 );
	}

	public static function filter_unsupported_temp_methods( array $rates, array $package ): array {
		$cart_temps = ProductTemp::temps_in_package( $package );
		// 純常溫 → 所有 method 都接得了 → 不過濾
		if ( count( $cart_temps ) === 1 && (int) $cart_temps[0] === ProductTemp::NORMAL ) {
			return $rates;
		}

		$registered = WC()->shipping() ? WC()->shipping()->get_shipping_methods() : [];
		$kept       = [];
		$mowp_kept  = false;

		foreach ( $rates as $rate_id => $rate ) {
			$method_id = (string) $rate->get_method_id();
			$is_mowp   = str_starts_with( $method_id, 'moksafowo_' );
			if ( ! $is_mowp ) {
				$kept[ $rate_id ] = $rate;
				continue;
			}
			$method = $registered[ $method_id ] ?? null;
			if ( $method && method_exists( $method, 'supported_temperatures' ) ) {
				$supported = array_map( 'intval', array_keys( $method->supported_temperatures() ) );
			} else {
				// Fallback：legacy method（如 PAYUNi ShippingBase 不繼承 mowp Abstract）
				// 從 method id 名稱推溫層
				$supported = self::infer_supported_from_id( $method_id );
			}
			$missing = array_diff( $cart_temps, $supported );
			if ( empty( $missing ) ) {
				$kept[ $rate_id ] = $rate;
				$mowp_kept        = true;
			}
		}

		// Safety：全部 mowp method 都被過濾 + 沒任何 rate 留下 → 還原 rates 避免 checkout 鎖死
		if ( empty( $kept ) ) {
			return $rates;
		}
		return $kept;
	}

	private static function infer_supported_from_id( string $method_id ): array {
		if ( str_ends_with( $method_id, '_freeze' ) || str_ends_with( $method_id, '_frozen' ) ) {
			return [ ProductTemp::FROZEN ];
		}
		if ( str_ends_with( $method_id, '_refrige' ) || str_ends_with( $method_id, '_refrigerated' ) ) {
			return [ ProductTemp::REFRIGERATED ];
		}
		return [ ProductTemp::NORMAL ];
	}

	public static function append_breakdown_to_rates( array $rates, array $package ): array {
		// Fast path：cart 純常溫 → 連 build_label_with_breakdown 都不用 fire
		// （否則 30+ rate 每個 都 fire 一次 zone method instance + 推 supported temp，純浪費）
		$temps = ProductTemp::temps_in_package( $package );
		if ( empty( $temps ) || ( count( $temps ) === 1 && (int) $temps[0] === ProductTemp::NORMAL ) ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate ) {
				continue;
			}
			// 非 mowp method 不 mutate label — early-skip 連 build_label_with_breakdown 都不用
			if ( ! str_starts_with( (string) $rate->get_method_id(), 'moksafowo_' ) ) {
				continue;
			}
			$new = self::build_label_with_breakdown( $rate, $package );
			if ( null !== $new ) {
				$rate->set_label( $new );
			}
		}
		return $rates;
	}

	private static function build_label_with_breakdown( \WC_Shipping_Rate $rate, array $package ): ?string {
		$label     = (string) $rate->get_label();
		$method_id = (string) $rate->get_method_id();
		if ( ! str_starts_with( $method_id, 'moksafowo_' ) ) {
			return null;
		}

		// 從 rate.instance_id 還原 active method instance（含 zone 設定值，比 get_shipping_methods()
		// 拿到的 method 定義更完整 — split_shipping_fee_enabled() 才讀得到）
		$instance_id = (int) $rate->get_instance_id();
		if ( $instance_id <= 0 ) {
			return null;
		}
		if ( ! array_key_exists( $instance_id, self::$zone_method_cache ) ) {
			self::$zone_method_cache[ $instance_id ] = \WC_Shipping_Zones::get_shipping_method( $instance_id );
		}
		$method = self::$zone_method_cache[ $instance_id ];
		if ( ! $method || ! method_exists( $method, 'supported_temperatures' ) ) {
			return null;
		}
		$supported = array_keys( $method->supported_temperatures() );
		if ( count( $supported ) <= 1 ) {
			// 單溫層 method（如 中華郵政、7-11 C2C 常溫）不顯示
			return null;
		}

		// 商家可在物流 method 進階設定關掉拆解
		if ( method_exists( $method, 'breakdown_enabled' ) && ! $method->breakdown_enabled() ) {
			return null;
		}

		$temps = ProductTemp::temps_in_package( $package );
		if ( empty( $temps ) || ( count( $temps ) === 1 && (int) $temps[0] === ProductTemp::NORMAL ) ) {
			// 純常溫 cart 不顯示拆解
			return null;
		}

		// 計算每溫層件數 + 重量（split mode 時 evaluate_cost_for_temp 用得到）
		$counts  = [];
		$weights = [];
		$contents = $package['contents'] ?? [];
		if ( is_array( $contents ) ) {
			foreach ( $contents as $item ) {
				$product = $item['data'] ?? null;
				if ( $product instanceof \WC_Product ) {
					$t   = ProductTemp::for_product( $product );
					$qty = (int) ( $item['quantity'] ?? 0 );
					$counts[ $t ] = ( $counts[ $t ] ?? 0 ) + $qty;
					$w            = (float) ( $product->get_weight() ?: 0 );
					if ( $w > 0 ) {
						$weights[ $t ] = ( $weights[ $t ] ?? 0.0 ) + ( $w * $qty );
					}
				}
			}
		}
		ksort( $counts );

		// 若 method 開啟「依溫層分別計算運費」，breakdown 加上各溫層運費金額
		$split_mode = method_exists( $method, 'split_shipping_fee_enabled' ) && $method->split_shipping_fee_enabled();

		$currency  = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'NT$';
		$separator = method_exists( $method, 'breakdown_separator' ) ? $method->breakdown_separator() : '　';
		$format    = method_exists( $method, 'breakdown_format' ) ? $method->breakdown_format() : 'full';

		// NBSP (U+00A0) — 防 wrap 點落在 token 內部把「冷藏 ×5」斷成兩行
		$nbsp = "\u{00A0}";

		// summary 模式：emoji 圖示組合 + 總件數 — 最短，wrap 風險最低
		if ( 'summary' === $format ) {
			$markers_str = '';
			$total_qty   = 0;
			$total_fee   = 0;
			foreach ( $counts as $temp => $qty ) {
				$temp_int     = (int) $temp;
				$markers_str .= method_exists( $method, 'breakdown_marker' ) ? $method->breakdown_marker( $temp_int ) : '·';
				$total_qty   += $qty;
				if ( $split_mode && method_exists( $method, 'evaluate_cost_for_temp' ) ) {
					$total_fee += (int) round( $method->evaluate_cost_for_temp( $temp_int, $qty, $weights[ $temp ] ?? 0.0 ) );
				}
			}
			$tail = sprintf(
				/* translators: 1: NBSP separator (non-breaking space), 2: total item count */
				__( '共%1$s%2$d 件', 'mo-ectools' ),
				$nbsp,
				$total_qty
			);
			if ( $split_mode && $total_fee > 0 ) {
				$tail .= $nbsp . $currency . number_format( $total_fee );
			}
			return $label . '　｜　' . $markers_str . ' ' . $tail;
		}

		// full / compact 模式：per-temp token list
		$parts = [];
		foreach ( $counts as $temp => $qty ) {
			$temp_int = (int) $temp;
			$marker   = method_exists( $method, 'breakdown_marker' ) ? $method->breakdown_marker( $temp_int ) : '·';
			if ( 'compact' === $format ) {
				// `🟫×3` — emoji 跟件數直接連，省去中文溫層 label，更不會 wrap
				$line = $marker . '×' . $qty;
			} else {
				// full：`🟫 常溫 ×3`，token 內用 NBSP 防斷
				$line = $marker . $nbsp . ProductTemp::label( $temp_int ) . $nbsp . '×' . $qty;
			}
			if ( $split_mode && method_exists( $method, 'evaluate_cost_for_temp' ) ) {
				$fee = (int) round( $method->evaluate_cost_for_temp( $temp_int, $qty, $weights[ $temp ] ?? 0.0 ) );
				if ( $fee > 0 ) {
					// NBSP 把 fee 黏在 ×qty 後面，wrap 一定整 token 一起搬
					$line .= $nbsp . $currency . number_format( $fee );
				}
			}
			$parts[] = $line;
		}
		if ( empty( $parts ) ) {
			return null;
		}

		return $label . '　｜　' . implode( $separator, $parts );
	}
}
