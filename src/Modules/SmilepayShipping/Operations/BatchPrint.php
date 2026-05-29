<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayShipping\Operations;

use MoksaWeb\Mowc\Modules\SmilepayShipping\Api\Helper;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class BatchPrint {

	public static function cvs( array $order_ids, array $options = [] ): array {
		$cvs_service_type = Helper::cvs_service_type();

		// B2C：可以多單一次列印 → 收集所有 smseid 用逗號分隔
		// C2C：每單一支 form（API 限一單）
		if ( 'B2C' === $cvs_service_type ) {
			$smseids = [];
			foreach ( $order_ids as $oid ) {
				$o = wc_get_order( $oid );
				if ( ! $o instanceof \WC_Order ) {
					continue;
				}
				$smseid = (string) $o->get_meta( Keys::SMILEPAY_SHIPPING_NO );
				if ( '' !== $smseid ) {
					$smseids[] = $smseid;
				}
			}
			if ( empty( $smseids ) ) {
				return [];
			}
			return [
				[
					'api_url'   => PrintProxy::relay_url(),
					'form_data' => PrintProxy::relay_form_data( 'b2c', [
						'smseid' => implode( ',', $smseids ),
					] ),
				],
			];
		}

		// C2C — 每單一支
		$forms = [];
		foreach ( $order_ids as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o instanceof \WC_Order ) {
				continue;
			}
			$smseid = (string) $o->get_meta( Keys::SMILEPAY_SHIPPING_NO );
			if ( '' === $smseid ) {
				continue;
			}
			$is_cod    = 'cod' === $o->get_payment_method();
			$pay_subzg = self::resolve_cvs_pay_subzg( $o );
			$forms[] = [
				'api_url'   => PrintProxy::relay_url(),
				'form_data' => PrintProxy::relay_form_data( $is_cod ? 'c2c' : 'c2cu', [
					'smseid'    => $smseid,
					'Pay_subzg' => $pay_subzg,
					'types'     => 'Web',
				] ),
			];
		}
		return $forms;
	}

	public static function home( array $order_ids, array $options = [] ): array {
		$forms   = [];
		$smseids = [];
		foreach ( $order_ids as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o instanceof \WC_Order ) {
				continue;
			}
			// Unified TCat：records list 內每筆都是獨立物流單，每包獨立列印
			$records = CreateOrder::get_records( $o );
			if ( ! empty( $records ) ) {
				foreach ( $records as $r ) {
					$smseid = (string) ( $r['smseid'] ?? '' );
					if ( '' !== $smseid ) {
						$smseids[] = $smseid;
					}
				}
				continue;
			}
			// 既有單溫層 method（tcat_normal/refrige/freeze）走 single key
			$smseid = (string) $o->get_meta( Keys::SMILEPAY_SHIPPING_NO );
			if ( '' !== $smseid ) {
				$smseids[] = $smseid;
			}
		}
		foreach ( array_unique( $smseids ) as $smseid ) {
			$forms[] = [
				'api_url'   => PrintProxy::relay_url(),
				'form_data' => PrintProxy::relay_form_data( 'tcat', [
					'Smseid'       => $smseid,
					'print_format' => '1',
				] ),
			];
		}
		return $forms;
	}

	public static function record_count( \WC_Order $order ): int {
		$records = CreateOrder::get_records( $order );
		if ( ! empty( $records ) ) {
			return count( $records );
		}
		$no = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_NO );
		return '' !== $no ? 1 : 0;
	}

	private static function resolve_cvs_pay_subzg( \WC_Order $order ): string {
		$method_id = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$method_id = (string) $m->get_method_id();
			break;
		}
		$is_b2c = 'B2C' === Helper::cvs_service_type();
		switch ( $method_id ) {
			case 'mo_smilepay_shipping_cvs_711':
				return $is_b2c ? 'SE2' : '71';
			case 'mo_smilepay_shipping_cvs_fami':
				return $is_b2c ? 'FM2' : '72';
		}
		return '';
	}
}
