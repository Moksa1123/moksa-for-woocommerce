<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Emails;

use Moksafowo\Modules\Shipping\Temp\ProductTemp;
use Moksafowo\Modules\Shipping\Tracking\TrackingLink;
use Moksafowo\Modules\SmilepayShipping\Module;
use Moksafowo\Modules\SmilepayShipping\Operations\CreateOrder;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class EmailTrackingProvider {

	public static function init(): void {
		add_filter( 'moksafowo_shipping_tracking_entries', [ __CLASS__, 'maybe_provide' ], 10, 3 );
	}

	public static function maybe_provide( array $entries, \WC_Order $order, string $method_id ): array {
		if ( ! isset( Module::method_map()[ $method_id ] ) ) {
			return $entries;
		}
		$records = CreateOrder::get_records( $order );
		if ( empty( $records ) ) {
			// 超商與單溫層黑貓只寫單鍵；LGS_TYPE 存的是 C2C／B2C，認不出是哪家，要從物流類型推。
			$type    = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_TYPE );
			$records = [
				[
					'subtype'   => str_starts_with( $type, '711' ) ? 'UNIMART' : ( str_starts_with( $type, 'FAMI' ) ? 'FAMI' : ( 'TCAT' === $type ? 'TCAT' : '' ) ),
					'lgs_type'  => (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_LGS_TYPE ),
					'pay_no'    => (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_PAY_NO ),
					'track_num' => (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_TRACK_NO ),
					'temp'      => '0',
				],
			];
		}
		return array_merge( $entries, self::map_records( $records ) );
	}

	private static function map_records( array $records ): array {
		$out = [];
		foreach ( $records as $r ) {
			$info = TrackingLink::for_smilepay_record( $r );
			if ( null === $info ) {
				continue;
			}
			$temp  = (int) ( $r['temp'] ?? 0 );
			$out[] = [
				'carrier'     => (string) $info['carrier'],
				'tracking_no' => (string) $info['tracking_no'],
				'url'         => (string) $info['url'],
				'mode'        => (string) $info['mode'],
				'temp_label'  => $temp > 0 ? ProductTemp::label( $temp ) : '',
			];
		}
		return $out;
	}
}
