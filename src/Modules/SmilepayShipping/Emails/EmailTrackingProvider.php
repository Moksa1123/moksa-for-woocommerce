<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayShipping\Emails;

use MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp;
use MoksaWeb\Mowc\Modules\Shipping\Tracking\TrackingLink;
use MoksaWeb\Mowc\Modules\SmilepayShipping\Module;
use MoksaWeb\Mowc\Modules\SmilepayShipping\Operations\CreateOrder;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class EmailTrackingProvider {

	public static function init(): void {
		add_filter( 'mo_shipping_tracking_entries', [ __CLASS__, 'maybe_provide' ], 10, 3 );
	}

	public static function maybe_provide( array $entries, \WC_Order $order, string $method_id ): array {
		if ( ! isset( Module::method_map()[ $method_id ] ) ) {
			return $entries;
		}
		$records = CreateOrder::get_records( $order );
		if ( empty( $records ) ) {
			$records = [
				[
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
			$temp = (int) ( $r['temp'] ?? 0 );
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
