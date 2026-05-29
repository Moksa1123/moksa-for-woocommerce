<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Operations;

defined( 'ABSPATH' ) || exit;

final class BatchPrint {

	public static function render( array $order_ids, array $options = [] ): array {
		$mode = isset( $options['mode'] ) && '2' === (string) $options['mode'] ? '2' : '1';

		// 依 SubType 分桶，每桶累加 LogisticsID
		$buckets = [];  // subtype => string[]
		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( CreateOrder::get_records( $order ) as $r ) {
				$id      = (string) ( $r['id'] ?? '' );
				$subtype = (string) ( $r['subtype'] ?? '' );
				if ( '' === $id || '' === $subtype ) {
					continue;
				}
				$buckets[ $subtype ][] = $id;
			}
		}

		$forms = [];
		foreach ( $buckets as $subtype => $ids ) {
			$ids = array_values( array_unique( $ids ) );
			$res = PrintLabel::build_for_ids( $ids, $subtype, null, $mode );
			if ( ! empty( $res['ok'] ) && ! empty( $res['api_url'] ) ) {
				$forms[] = [
					'api_url'   => (string) $res['api_url'],
					'form_data' => (array) $res['form_data'],
				];
			}
		}
		return $forms;
	}
}
