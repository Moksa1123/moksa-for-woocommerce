<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Frontend;

use Moksafowo\Modules\Shipping\Temp\ProductTemp;

defined( 'ABSPATH' ) || exit;

final class CartTempLabel {

	// rate 試算每輪都 fire get_shipping_method，30+ rate × N pkg 不 cache 重複 DB hit
	private static array $zone_method_cache = [];

	public static function init(): void {
		// Classic 讀 get_label()，Block Store API 序列化 $rate->name = get_label()，同源
		add_filter( 'woocommerce_package_rates', [ __CLASS__, 'filter_unsupported_temp_methods' ], 100, 2 );
		add_filter( 'woocommerce_package_rates', [ __CLASS__, 'append_breakdown_to_rates' ], 110, 2 );
	}

	public static function filter_unsupported_temp_methods( array $rates, array $package ): array {
		$cart_temps = ProductTemp::temps_in_package( $package );
		if ( count( $cart_temps ) === 1 && (int) $cart_temps[0] === ProductTemp::NORMAL ) {
			return $rates;
		}

		$registered     = WC()->shipping() ? WC()->shipping()->get_shipping_methods() : [];
		$kept           = [];
		$moksafowo_kept = false;

		foreach ( $rates as $rate_id => $rate ) {
			$method_id = (string) $rate->get_method_id();
			$is_ours   = str_starts_with( $method_id, 'moksafowo_' );
			if ( ! $is_ours ) {
				$kept[ $rate_id ] = $rate;
				continue;
			}
			$method = $registered[ $method_id ] ?? null;
			if ( $method && method_exists( $method, 'supported_temperatures' ) ) {
				$supported = array_map( 'intval', array_keys( $method->supported_temperatures() ) );
			} else {
				// legacy method（如 PAYUNi ShippingBase）不繼承本外掛 Abstract，從 id 推溫層
				$supported = self::infer_supported_from_id( $method_id );
			}
			$missing = array_diff( $cart_temps, $supported );
			if ( empty( $missing ) ) {
				$kept[ $rate_id ] = $rate;
				$moksafowo_kept   = true;
			}
		}

		// 全部過濾後無 rate → 還原，避免 checkout 鎖死
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
		$temps = ProductTemp::temps_in_package( $package );
		if ( empty( $temps ) || ( count( $temps ) === 1 && (int) $temps[0] === ProductTemp::NORMAL ) ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate ) {
				continue;
			}
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

		// instance_id 還原 active instance（含 zone 設定值；get_shipping_methods() 不夠用）
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
			return null;
		}

		if ( method_exists( $method, 'breakdown_enabled' ) && ! $method->breakdown_enabled() ) {
			return null;
		}

		$temps = ProductTemp::temps_in_package( $package );
		if ( empty( $temps ) || ( count( $temps ) === 1 && (int) $temps[0] === ProductTemp::NORMAL ) ) {
			return null;
		}

		$counts   = [];
		$weights  = [];
		$contents = $package['contents'] ?? [];
		if ( is_array( $contents ) ) {
			foreach ( $contents as $item ) {
				$product = $item['data'] ?? null;
				if ( $product instanceof \WC_Product ) {
					$t            = ProductTemp::for_product( $product );
					$qty          = (int) ( $item['quantity'] ?? 0 );
					$counts[ $t ] = ( $counts[ $t ] ?? 0 ) + $qty;
					$w            = (float) ( $product->get_weight() ?: 0 );
					if ( $w > 0 ) {
						$weights[ $t ] = ( $weights[ $t ] ?? 0.0 ) + ( $w * $qty );
					}
				}
			}
		}
		ksort( $counts );

		$split_mode = method_exists( $method, 'split_shipping_fee_enabled' ) && $method->split_shipping_fee_enabled();

		$currency  = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'NT$';
		$separator = method_exists( $method, 'breakdown_separator' ) ? $method->breakdown_separator() : '　';
		$format    = method_exists( $method, 'breakdown_format' ) ? $method->breakdown_format() : 'full';

		$nbsp = "\u{00A0}"; // U+00A0 防 wrap 把「冷藏 ×5」斷行

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

		$parts = [];
		foreach ( $counts as $temp => $qty ) {
			$temp_int = (int) $temp;
			$marker   = method_exists( $method, 'breakdown_marker' ) ? $method->breakdown_marker( $temp_int ) : '·';
			if ( 'compact' === $format ) {
				$line = $marker . '×' . $qty; // `🟫×3`
			} else {
				$line = $marker . $nbsp . ProductTemp::label( $temp_int ) . $nbsp . '×' . $qty; // `🟫 常溫 ×3`
			}
			if ( $split_mode && method_exists( $method, 'evaluate_cost_for_temp' ) ) {
				$fee = (int) round( $method->evaluate_cost_for_temp( $temp_int, $qty, $weights[ $temp ] ?? 0.0 ) );
				if ( $fee > 0 ) {
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
