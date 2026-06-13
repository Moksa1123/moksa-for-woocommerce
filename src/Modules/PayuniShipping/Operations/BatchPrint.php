<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Operations;

use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\GoodsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\LgsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;

defined( 'ABSPATH' ) || exit;

final class BatchPrint {

	public static function cvs( array $order_ids, array $options = [] ): array {
		return self::build( $order_ids, ShipType::SEVEN, $options );
	}

	public static function home( array $order_ids, array $options = [] ): array {
		return self::build( $order_ids, ShipType::TCAT, $options );
	}

	private static function build( array $order_ids, string $service, array $options = [] ): array {
		$orders = wc_get_orders( [ 'include' => $order_ids, 'limit' => -1 ] );
		if ( empty( $orders ) ) {
			return [];
		}

		$ship_trade_nos = [];
		foreach ( $orders as $order ) {
			// Unified records list 優先（多溫層拆單訂單會有多筆）
			$records = CreateOrderUnified::get_records( $order );
			if ( ! empty( $records ) ) {
				foreach ( $records as $r ) {
					$no = (string) ( $r['ship_trade_no'] ?? '' );
					if ( '' !== $no ) {
						$ship_trade_nos[] = $no;
					}
				}
				continue;
			}
			// Legacy 單溫層 method 走 single key meta
			$no = (string) $order->get_meta( OrderMeta::ShipTradeNo );
			if ( '' !== $no ) {
				$ship_trade_nos[] = $no;
			}
		}
		$ship_trade_nos = array_values( array_unique( $ship_trade_nos ) );
		if ( empty( $ship_trade_nos ) ) {
			return [];
		}

		$reference = $orders[0];

		// PAYUNi API expects Asia/Taipei wallclock dates; use wp_date() instead of mutating PHP's default tz.

		if ( ShipType::SEVEN === $service ) {
			$api_url    = PayuniShipping::$api_url . '/logistics/print_label';
			$lgs_type   = (string) $reference->get_meta( OrderMeta::LgsType );
			$ship_date  = ( $lgs_type === LgsType::B2C )
				? gmdate( 'Ymd', strtotime( '+1 day' ) )
				: gmdate( 'Ymd' );
			// LabelMode 優先序：modal 傳的 mode > 設定頁 default > '1' (A4)。
			// PAYUNi 規範：1=A4 / 2=直立式 (僅 B2C 適用)。modal row 級反灰已防止 C2C 訂單選 mode=2。
			$modal_mode = isset( $options['mode'] ) && '2' === (string) $options['mode'] ? '2' : '';
			$label_mode = '' !== $modal_mode ? $modal_mode : (string) get_option( 'moksafowo_payuni_shipping_cvs_label_mode', '1' );
			$args = [
				'MerID'       => PayuniShipping::get_mer_id(),
				'Timestamp'   => time(),
				'ShipTradeNo' => implode( ',', $ship_trade_nos ),
				'GoodsType'   => (string) $reference->get_meta( OrderMeta::GoodsType ),
				'LgsType'     => $lgs_type,
				'ShipType'    => ShipType::SEVEN,
				'ShipDate'    => $ship_date,
				'LabelMode'   => $label_mode,
			];
		} else {
			// TCAT — 多帶 PostType / PrintType / ShipDate / DeliveryDate / Spec
			$api_url       = PayuniShipping::$api_url . '/home_delivery/get_obt_number_pdf';
			$shipping_date = (int) get_option( 'moksafowo_payuni_shipping_tcat_estimate_shipping_date', '1' );
			// Spec 必填（PAYUNi HOME01077 否則拒收）：訂單 meta > 設定預設 > 1 (60cm)
			$package_spec  = (string) $reference->get_meta( OrderMeta::PackageSpec );
			if ( '' === $package_spec ) {
				$package_spec = (string) get_option( 'moksafowo_payuni_shipping_tcat_default_spec', '1' );
			}
			if ( ! in_array( $package_spec, [ '1', '2', '3', '4' ], true ) ) {
				$package_spec = '1';
			}

			$est_ship   = self::next_business_day( new \DateTime(), $shipping_date );
			$est_deliver = self::next_business_day( $est_ship, 1 );

			$args = [
				'MerID'        => PayuniShipping::get_mer_id(),
				'Timestamp'    => time(),
				'PostType'     => '1',
				'PrintType'    => '1',
				'ShipTradeNo'  => implode( ',', $ship_trade_nos ),
				'GoodsType'    => (string) $reference->get_meta( OrderMeta::GoodsType ),
				'LgsType'      => LgsType::HOME,
				'ShipType'     => ShipType::TCAT,
				'ShipDate'     => $est_ship->format( 'Y-m-d' ),
				'DeliveryDate' => $est_deliver->format( 'Y-m-d' ),
				'Spec'         => $package_spec,
			];
		}

		$encrypted = PayuniShipping::encrypt( $args );
		$form_data = [
			'MerID'       => PayuniShipping::get_mer_id(),
			'Version'     => '1.0',
			'EncryptInfo' => $encrypted,
			'HashInfo'    => PayuniShipping::hash_info( $encrypted ),
		];

		return [
			[
				'api_url'   => $api_url,
				'form_data' => $form_data,
			],
		];
	}

	private static function next_business_day( \DateTimeInterface $from, int $days ): \DateTime {
		$d = ( $from instanceof \DateTime ) ? clone $from : new \DateTime( $from->format( 'Y-m-d H:i:s' ) );
		while ( $days > 0 ) {
			$d->modify( '+1 day' );
			if ( '0' !== $d->format( 'w' ) ) {  // 跳過週日
				$days--;
			}
		}
		return $d;
	}
}
