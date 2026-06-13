<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Emails;

use MoksaWeb\Mowc\Modules\EcpayShipping\Module;
use MoksaWeb\Mowc\Modules\EcpayShipping\Operations\CreateOrder;
use MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp;
use MoksaWeb\Mowc\Modules\Shipping\Tracking\TrackingLink;

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
		return array_merge( $entries, self::map_records( $records ) );
	}

	private static function map_records( array $records ): array {
		$out = [];
		foreach ( $records as $r ) {
			$info = TrackingLink::for_ecpay_record( $r );
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
