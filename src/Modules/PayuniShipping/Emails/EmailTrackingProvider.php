<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Emails;

use Moksafowo\Modules\PayuniShipping\Operations\CreateOrderUnified;
use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;
use Moksafowo\Modules\Shipping\Tracking\TrackingLink;

defined( 'ABSPATH' ) || exit;

final class EmailTrackingProvider {

	public static function init(): void {
		add_filter( 'moksafowo_shipping_tracking_entries', [ __CLASS__, 'maybe_provide' ], 10, 3 );
	}

	public static function maybe_provide( array $entries, \WC_Order $order, string $method_id ): array {
		if ( ! PayuniShipping::is_payuni_shipping( $method_id ) ) {
			return $entries;
		}
		$records = CreateOrderUnified::get_records( $order );
		if ( empty( $records ) ) {
			$records = [
				[
					'ship_type' => (string) $order->get_meta( OrderMeta::ShipType ),
					'odno'      => (string) $order->get_meta( OrderMeta::Odno ),
					'temp'      => '0',
				],
			];
		}
		return array_merge( $entries, self::map_records( $records ) );
	}

	private static function map_records( array $records ): array {
		$out = [];
		foreach ( $records as $r ) {
			$info = TrackingLink::for_payuni_record( $r );
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
