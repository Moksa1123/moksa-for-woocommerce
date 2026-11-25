<?php
/**
 * 統一物流的建單方式：幕前（付款頁一起建）或幕後（伺服器呼叫建單 API）。
 *
 * @package Moksafowo
 */

declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping;

use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * PAYUNi 2026-09-09 移除了單獨建物流單的 API，官方外掛只做「付款頁一起建單」。
 * 已在用的店家（升級前就啟用、設定過或建過單）一律自動設成幕後，行為不變；
 * 新安裝預設幕前。
 */
final class CreateMode {

	public const OPTION   = 'moksafowo_payuni_shipping_create_mode';
	public const FRONTEND = 'frontend';
	public const BACKEND  = 'backend';

	/** 第一次啟動時決定一次，之後照店家設定。 */
	public static function ensure(): void {
		$current = get_option( self::OPTION, null );
		if ( self::FRONTEND === $current || self::BACKEND === $current ) {
			return;
		}
		update_option( self::OPTION, self::already_in_use() ? self::BACKEND : self::FRONTEND, false );
	}

	public static function mode(): string {
		return self::FRONTEND === get_option( self::OPTION, self::FRONTEND ) ? self::FRONTEND : self::BACKEND;
	}

	public static function is_frontend(): bool {
		return self::FRONTEND === self::mode();
	}

	/** 升級前就在用：存過寄件人或其他統一物流設定、運送區域加過統一物流、或已有統一物流單。 */
	private static function already_in_use(): bool {
		foreach ( array(
			'moksafowo_payuni_shipping_sender_name',
			'moksafowo_payuni_shipping_sender_phone',
			'moksafowo_payuni_shipping_cvs_selector_layout',
			'moksafowo_payuni_shipping_order_status_pickuped',
		) as $option ) {
			if ( false !== get_option( $option, false ) ) {
				return true;
			}
		}

		// 直接讀運送區域的方式清單（只有 ID 字串）：這時統一物流的運送方式類別還沒註冊，
		// 用 WC_Shipping_Zone::get_shipping_methods() 會把它們濾掉而誤判成沒在用。
		if ( class_exists( '\WC_Data_Store' ) && class_exists( '\WC_Shipping_Zones' ) ) {
			try {
				$store = \WC_Data_Store::load( 'shipping-zone' );
				$ids   = array_map( static fn ( $zone ): int => (int) $zone['zone_id'], \WC_Shipping_Zones::get_zones() );
				$ids[] = 0;
				foreach ( $ids as $zone_id ) {
					foreach ( (array) $store->get_methods( $zone_id, false ) as $row ) {
						if ( Utils\MethodIdPredicates::is_payuni_shipping( (string) ( $row->method_id ?? '' ) ) ) {
							return true;
						}
					}
				}
			} catch ( \Exception $e ) {
				return true; // 判斷不了就當作在用：保持舊行為比較安全。
			}
		}

		if ( class_exists( '\Moksafowo\Order\Lookup' ) ) {
			$ids = \Moksafowo\Order\Lookup::orders(
				array(
					'type'                             => 'shop_order',
					'status'                           => 'all',
					'limit'                            => 1,
					'return'                           => 'ids',
					\Moksafowo\Order\Lookup::QUERY_VAR => array(
						'key'     => OrderMeta::ShipTradeNo,
						'compare' => 'EXISTS',
					),
				)
			);
			if ( ! empty( $ids ) ) {
				return true;
			}
		}
		return false;
	}
}
