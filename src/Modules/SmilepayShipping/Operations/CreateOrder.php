<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Operations;

use Moksafowo\Modules\Shipping\Order\SplitByTemp;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;
use Moksafowo\Modules\SmilepayShipping\Api\Helper;
use Moksafowo\Modules\SmilepayShipping\Api\ShippingRequest;
use Moksafowo\Modules\SmilepayShipping\Methods\Tcat;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class CreateOrder {

	private const UNIFIED_TCAT_ID = 'moksafowo_smilepay_shipping_tcat';


	public static function run( \WC_Order $order ): array {
		// 之後送出的回呼網址都帶每單 key；通知進來時據此驗證（這之前建的單沒有 key）。
		if ( 'yes' !== $order->get_meta( Keys::SMILEPAY_SHIPPING_KEYED ) ) {
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_KEYED, 'yes' );
			$order->save();
		}
		// 從訂單裡識別運送方式 → 決定走 CVS / TCAT / unified-TCat 路徑
		$method_id = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$method_id = (string) $m->get_method_id();
			break;
		}
		if ( '' === $method_id ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no SmilePay shipping method.', 'moksa-for-woocommerce' ),
			];
		}

		// 寄件人資訊驗證
		$sender = Helper::sender_info();
		if ( '' === $sender['name'] || '' === $sender['phone'] ) {
			return [
				'ok'      => false,
				'message' => __( 'First fill in a name and mobile number under WooCommerce → Moksa → SmilePay shipping → Sender details.', 'moksa-for-woocommerce' ),
			];
		}

		// 多溫層 unified TCat → 走拆單流程
		if ( self::UNIFIED_TCAT_ID === $method_id ) {
			return self::run_unified_tcat( $order );
		}

		$is_cvs  = str_contains( $method_id, '_cvs_' );
		$is_tcat = str_contains( $method_id, '_tcat_' );
		if ( ! $is_cvs && ! $is_tcat ) {
			return [
				'ok'      => false,
				'message' => __( 'That SmilePay shipping method is not recognized.', 'moksa-for-woocommerce' ),
			];
		}

		// Step 1 — 取 smseid（如果之前沒取過）
		$smseid = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_NO );
		if ( '' === $smseid ) {
			$step1 = self::do_step1_request_smseid( $order, $is_cvs, $method_id );
			if ( ! $step1['ok'] ) {
				/* translators: %s: error message */
				$order->add_order_note( sprintf( __( 'The SmilePay shipment could not be created: %s', 'moksa-for-woocommerce' ), $step1['message'] ) );
				$order->save();
				return $step1;
			}
			$smseid = $step1['smseid'] ?? '';
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_NO, $smseid );
			$order->save();
		}
		if ( '' === $smseid ) {
			return [
				'ok'      => false,
				'message' => __( 'The SmilePay shipment could not be created: no shipping serial number was returned.', 'moksa-for-woocommerce' ),
			];
		}

		// Step 2 — 確認建單
		if ( $is_cvs ) {
			return self::do_step2_cvs( $order, $smseid, $method_id );
		}
		return self::do_step2_tcat( $order, $smseid, $method_id );
	}


	private static function run_unified_tcat( \WC_Order $order ): array {
		$method   = self::resolve_order_shipping_method( $order, self::UNIFIED_TCAT_ID );
		$packages = SplitByTemp::for_order( $order, [ ProductTemp::NORMAL, ProductTemp::REFRIGERATED, ProductTemp::FROZEN ], $method instanceof \Moksafowo\Modules\Shipping\Methods\AbstractShippingMethod ? $method : null );
		if ( empty( $packages ) ) {
			return [
				'ok'      => false,
				'message' => __( 'The order has no items to create a shipment from.', 'moksa-for-woocommerce' ),
			];
		}

		$is_cod   = 'cod' === (string) $order->get_payment_method();
		$multi    = count( $packages ) > 1;
		$created  = [];
		$errors   = [];
		$existing = self::get_records( $order );
		// 預先算一次時戳 — loop 內 N 次重跑 timezone 換算沒意義。
		$now = current_time( 'mysql' );

		// 已建立過的 temp 不重建（避免重複呼 API）
		$existing_temps = [];
		foreach ( $existing as $r ) {
			$t = (int) ( $r['temp'] ?? 0 );
			if ( $t > 0 ) {
				$existing_temps[ $t ] = true;
			}
		}

		foreach ( $packages as $pkg ) {
			$temp = (int) $pkg['temp'];
			if ( isset( $existing_temps[ $temp ] ) ) {
				continue;  // 此溫層 record 已存在，跳過
			}

			// Step 1 — 取 smseid（每包獨立）
			$args  = self::build_smseid_args_for_package( $order, $pkg );
			$step1 = ShippingRequest::request_smseid( $args );
			if ( ! $step1['ok'] ) {
				$errors[] = sprintf(
					/* translators: 1: temp label, 2: msg */
					__( 'No serial number was returned for the %1$s zone: %2$s', 'moksa-for-woocommerce' ),
					ProductTemp::label( $temp ),
					$step1['message']
				);
				continue;
			}
			$smseid = (string) ( $step1['smseid'] ?? '' );
			if ( '' === $smseid ) {
				/* translators: %s: temperature layer label */
				$errors[] = sprintf( __( 'The %s shipment could not be created: no shipping serial number was returned.', 'moksa-for-woocommerce' ), ProductTemp::label( $temp ) );
				continue;
			}

			// Step 2 — 確認 TCat 建單
			$temperature_code = self::temp_to_temperature_code( $temp );
			$step2            = ShippingRequest::confirm_tcat( $smseid, $temperature_code );
			if ( ! $step2['ok'] ) {
				$errors[] = sprintf(
					/* translators: 1: temp label, 2: msg */
					__( 'The waybill for the %1$s zone could not be fetched: %2$s', 'moksa-for-woocommerce' ),
					ProductTemp::label( $temp ),
					$step2['message']
				);
				continue;
			}

			$created[] = [
				'smseid'     => $smseid,
				'track_num'  => (string) ( $step2['track_num'] ?? '' ),
				'type'       => 'TCAT',
				'subtype'    => 'TCAT',
				'temp'       => (string) $temp,
				'amount'     => (string) (int) $pkg['amount'],
				'goods_name' => (string) $pkg['goods_name'],
				'pay_zg'     => Tcat::payzg_for_temp( $temp, $is_cod && (int) $pkg['amount'] > 0 ),
				'rtn_msg'    => 'OK',
				'created_at' => $now,
			];
		}

		if ( empty( $created ) && empty( $existing ) ) {
			$msg = $errors ? implode( ' / ', $errors ) : __( 'Could not create the shipment', 'moksa-for-woocommerce' );
			$order->add_order_note( sprintf( /* translators: %s: error message */ __( 'The SmilePay shipment could not be created: %s', 'moksa-for-woocommerce' ), $msg ) );
			$order->save();
			return [
				'ok'      => false,
				'message' => $msg,
			];
		}

		// Append 新 records
		$records = $existing;
		foreach ( $created as $r ) {
			$records[] = $r;
		}
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_RECORDS, $records );

		// Mirror 最新一筆到 single keys（既有 UI 向下相容）
		if ( ! empty( $records ) ) {
			$last = end( $records );
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_NO, (string) $last['smseid'] );
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_TYPE, 'TCAT' );
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_TRACK_NO, (string) $last['track_num'] );
		}

		// Order note
		if ( count( $created ) > 1 ) {
			$lines = [];
			foreach ( $created as $r ) {
				$lines[] = sprintf(
					'%s — 物流序號 %s 託運單號 %s',
					ProductTemp::label( (int) $r['temp'] ),
					(string) $r['smseid'],
					(string) $r['track_num']
				);
			}
			$order->add_order_note(
				sprintf(
				/* translators: 1: count, 2: list */
					__( 'SmilePay created the T-Cat shipments, split into %1$d parcels by temperature zone: %2$s', 'moksa-for-woocommerce' ),
					count( $created ),
					"\n" . implode( "\n", $lines )
				)
			);
		} elseif ( ! empty( $created ) ) {
			$r = $created[0];
			$order->add_order_note(
				sprintf(
				/* translators: 1: temp label, 2: smilepay order no, 3: tracking no */
					__( 'SmilePay created the T-Cat home delivery shipment — %1$s (serial number %2$s, waybill number %3$s)', 'moksa-for-woocommerce' ),
					ProductTemp::label( (int) $r['temp'] ),
					(string) $r['smseid'],
					(string) $r['track_num']
				)
			);
		}

		if ( ! empty( $errors ) ) {
			$order->add_order_note( sprintf( /* translators: %s: error messages */ __( 'Some temperature zones could not be created: %s', 'moksa-for-woocommerce' ), implode( ' / ', $errors ) ) );
		}

		$order->save();

		$last   = end( $records );
		$result = [
			'ok'              => true,
			'message'         => 'OK',
			'smseid'          => (string) ( $last['smseid'] ?? '' ),
			'track_num'       => (string) ( $last['track_num'] ?? '' ),
			'records_created' => count( $created ),
		];
		if ( ! empty( $errors ) ) {
			$result['warning'] = implode( ' / ', $errors );
		}
		return $result;
	}

	public static function get_records( \WC_Order $order ): array {
		$raw = $order->get_meta( Keys::SMILEPAY_SHIPPING_RECORDS );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			return array_values( $raw );
		}
		return [];
	}

	/**
	 * 刪掉一筆記錄（只刪站上的記錄，不會通知速買配作廢）。
	 * 刪完要重新鏡射最新一筆到單鍵，否則 UI 會顯示已經不存在的物流單。
	 */
	public static function delete_record( \WC_Order $order, string $smseid ): bool {
		$records = self::get_records( $order );
		$kept    = array_values(
			array_filter( $records, static fn( array $r ): bool => (string) ( $r['smseid'] ?? '' ) !== $smseid )
		);
		if ( count( $kept ) === count( $records ) ) {
			return false;
		}

		if ( empty( $kept ) ) {
			$order->delete_meta_data( Keys::SMILEPAY_SHIPPING_RECORDS );
			foreach ( [
				Keys::SMILEPAY_SHIPPING_NO,
				Keys::SMILEPAY_SHIPPING_TYPE,
				Keys::SMILEPAY_SHIPPING_TRACK_NO,
			] as $k ) {
				$order->delete_meta_data( $k );
			}
		} else {
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_RECORDS, $kept );
			$latest = end( $kept );
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_NO, (string) ( $latest['smseid'] ?? '' ) );
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_TRACK_NO, (string) ( $latest['track_num'] ?? '' ) );
		}
		$order->add_order_note(
			sprintf(
			/* translators: %s: SmilePay shipment number */
				__( 'Shipment record #%s was deleted from this site only. SmilePay is not notified.', 'moksa-for-woocommerce' ),
				$smseid
			)
		);

		$order->save();
		return true;
	}

	private static function temp_to_temperature_code( int $temp ): string {
		return match ( $temp ) {
			ProductTemp::REFRIGERATED => '0002',
			ProductTemp::FROZEN       => '0003',
			default                   => '0001',
		};
	}


	private static function build_smseid_args_for_package( \WC_Order $order, array $pkg ): array {
		$temp     = (int) $pkg['temp'];
		$pur_name = trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() );
		if ( '' === $pur_name ) {
			$pur_name = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		}
		$phone = $order->get_billing_phone();
		// 黑貓宅配 — 收件地址走 shipping address
		$address = $order->get_shipping_address_1();
		if ( '' === $address ) {
			$address = $order->get_billing_address_1();
		}

		return [
			// 0 元的包裹（贈品、全額折抵）沒有錢要收，不能代收（Amount 最少 1 只是報值）。
			'Pay_zg'           => Tcat::payzg_for_temp( $temp, 'cod' === (string) $order->get_payment_method() && (int) $pkg['amount'] > 0 ),
			'Pay_subzg'        => 'TCAT',
			'Pur_name'         => mb_substr( $pur_name, 0, 5 ),
			'Tel_number'       => $phone,
			'Mobile_number'    => $phone,
			'Address'          => $address,
			'Email'            => $order->get_billing_email(),
			'Data_id'          => (string) $order->get_id() . 'T' . $temp,
			// 按顯示寬度切（全形算 2），與物流業者計算長度的方式一致
			'od_sob'           => mb_strimwidth( (string) $pkg['goods_name'], 0, 60, '', 'UTF-8' ),
			'Amount'           => max( 1, (int) $pkg['amount'] ),
			'Logistics_store'  => '',
			'Roturl'           => Helper::keyed_roturl( $order ),
			'Logistics_Roturl' => Helper::keyed_roturl( $order ),
			'Roturl_status'    => 'mowp1.0',
			'Remark'           => $order->get_customer_note(),
		];
	}


	private static function do_step1_request_smseid( \WC_Order $order, bool $is_cvs, string $method_id ): array {
		$store_id   = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_ID );
		$store_name = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_NAME );
		$store_addr = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_ADDR );

		if ( $is_cvs && '' === $store_id ) {
			return [
				'ok'      => false,
				'message' => __( 'This store pickup order has no store yet. Ask the customer to choose one at checkout, or set it yourself.', 'moksa-for-woocommerce' ),
			];
		}

		[ $pay_zg, $pay_subzg ] = self::resolve_pay_zg( $method_id, 'cod' === (string) $order->get_payment_method() );
		if ( '' === $pay_zg ) {
			return [
				'ok'      => false,
				'message' => __( 'SmilePay does not offer FamilyMart bulk (B2C) shipping. Switch the store pickup type to store-to-store (C2C).', 'moksa-for-woocommerce' ),
			];
		}

		$products = self::build_product_list( $order );
		// 代收金額要扣掉已退款。
		$amount   = max( 1, (int) ceil( (float) $order->get_total() - (float) $order->get_total_refunded() ) );
		$pur_name = trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() );
		$phone    = $order->get_billing_phone();

		$args = [
			'Pay_zg'           => $pay_zg,
			'Pay_subzg'        => $pay_subzg,
			'Pur_name'         => mb_substr( $pur_name, 0, 5 ),
			'Tel_number'       => $phone,
			'Mobile_number'    => $phone,
			'Address'          => $is_cvs ? '' : $order->get_billing_address_1(),
			'Email'            => $order->get_billing_email(),
			'Data_id'          => (string) $order->get_id(),
			'od_sob'           => $products,
			'Amount'           => $amount,
			'Logistics_store'  => $is_cvs ? "{$store_id}/{$store_name}/{$store_addr}" : '',
			'Roturl'           => Helper::keyed_roturl( $order ),
			'Logistics_Roturl' => Helper::keyed_roturl( $order ),
			'Roturl_status'    => 'mowp1.0',
			'Remark'           => $order->get_customer_note(),
		];

		return ShippingRequest::request_smseid( $args );
	}


	private static function do_step2_cvs( \WC_Order $order, string $smseid, string $method_id ): array {
		$cvs_service_type = Helper::cvs_service_type();
		[ , $pay_subzg ]  = self::resolve_pay_zg( $method_id, 'cod' === (string) $order->get_payment_method() );
		$is_cod           = 'cod' === $order->get_payment_method();

		$result = ShippingRequest::confirm_cvs( $smseid, $pay_subzg, $cvs_service_type, $is_cod );
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			$order->add_order_note( sprintf( __( 'The SmilePay store pickup shipment could not be created: %s', 'moksa-for-woocommerce' ), $result['message'] ) );
			$order->save();
			return $result;
		}
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_LGS_TYPE, $cvs_service_type );
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_TYPE, str_starts_with( $method_id, 'moksafowo_smilepay_shipping_cvs_711' ) ? '711' . $cvs_service_type : 'FAMI' . $cvs_service_type );
		if ( ! empty( $result['payment_no'] ) ) {
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_PAY_NO, $result['payment_no'] );
		}
		$order->add_order_note(
			sprintf(
			/* translators: 1: smilepay order no, 2: pickup code */
				__( 'SmilePay store pickup shipment created (serial number %1$s, pickup code %2$s)', 'moksa-for-woocommerce' ),
				$smseid,
				$result['payment_no'] ?? $result['eshop_order_no'] ?? '-'
			)
		);
		$order->save();
		return [
			'ok'         => true,
			'message'    => 'OK',
			'smseid'     => $smseid,
			'payment_no' => $result['payment_no'] ?? '',
		];
	}


	private static function do_step2_tcat( \WC_Order $order, string $smseid, string $method_id ): array {
		$temp_map    = [
			'moksafowo_smilepay_shipping_tcat_normal'  => '0001',
			'moksafowo_smilepay_shipping_tcat_refrige' => '0002',
			'moksafowo_smilepay_shipping_tcat_freeze'  => '0003',
		];
		$temperature = $temp_map[ $method_id ] ?? '0001';

		$result = ShippingRequest::confirm_tcat( $smseid, $temperature );
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			$order->add_order_note( sprintf( __( 'The SmilePay T-Cat home delivery shipment could not be created: %s', 'moksa-for-woocommerce' ), $result['message'] ) );
			$order->save();
			return $result;
		}
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_TYPE, 'TCAT' );
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_TRACK_NO, $result['track_num'] ?? '' );
		$order->add_order_note(
			sprintf(
			/* translators: 1: smilepay order no, 2: tracking no */
				__( 'SmilePay T-Cat home delivery shipment created (serial number %1$s, waybill number %2$s)', 'moksa-for-woocommerce' ),
				$smseid,
				$result['track_num'] ?? '-'
			)
		);
		$order->save();
		return [
			'ok'        => true,
			'message'   => 'OK',
			'smseid'    => $smseid,
			'track_num' => $result['track_num'] ?? '',
		];
	}


	private static function resolve_order_shipping_method( \WC_Order $order, string $method_id ): ?\WC_Shipping_Method {
		$map = \Moksafowo\Modules\SmilepayShipping\Module::method_map();
		if ( ! isset( $map[ $method_id ] ) ) {
			return null;
		}
		foreach ( $order->get_shipping_methods() as $line ) {
			if ( (string) $line->get_method_id() !== $method_id ) {
				continue;
			}
			$class    = $map[ $method_id ];
			$instance = new $class( (int) $line->get_instance_id() );
			$instance->init_form_fields();
			$instance->init_settings();
			return $instance;
		}
		return null;
	}

	/**
	 * 速買配 SPPayment 的物流編碼（規格「Pay_zg / Pay_subzg 編碼矩陣」，官方外掛同）：
	 * 超商 C2C 取貨付款 51／純取貨 52，7-11 B2C 55／56；黑貓 81／82。
	 * 副分類 7-11 用 7NET（C2C、B2C 都是）、全家用 FAMI、黑貓用 TCAT。全家沒有 B2C。
	 * 代收與否在這一步就定了，所以一定要依付款方式送。
	 */
	private static function resolve_pay_zg( string $method_id, bool $is_cod ): array {
		$is_b2c = 'B2C' === Helper::cvs_service_type();
		switch ( $method_id ) {
			case 'moksafowo_smilepay_shipping_cvs_711':
				if ( $is_b2c ) {
					return [ $is_cod ? '55' : '56', '7NET' ];
				}
				return [ $is_cod ? '51' : '52', '7NET' ];
			case 'moksafowo_smilepay_shipping_cvs_fami':
				return $is_b2c ? [ '', '' ] : [ $is_cod ? '51' : '52', 'FAMI' ];
			case 'moksafowo_smilepay_shipping_tcat_normal':
			case 'moksafowo_smilepay_shipping_tcat_refrige':
			case 'moksafowo_smilepay_shipping_tcat_freeze':
				return [ $is_cod ? '81' : '82', 'TCAT' ];
		}
		return [ '', '' ];
	}

	private static function build_product_list( \WC_Order $order ): string {
		$names = [];
		foreach ( $order->get_items() as $item ) {
			$names[] = $item->get_name() . '*' . (int) $item->get_quantity();
		}
		return mb_substr( implode( ',', $names ), 0, 60 );
	}
}
