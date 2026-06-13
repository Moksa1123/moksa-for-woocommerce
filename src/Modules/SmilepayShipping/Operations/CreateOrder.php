<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayShipping\Operations;

use MoksaWeb\Mowc\Modules\Shipping\Order\SplitByTemp;
use MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp;
use MoksaWeb\Mowc\Modules\SmilepayShipping\Api\Helper;
use MoksaWeb\Mowc\Modules\SmilepayShipping\Api\ShippingRequest;
use MoksaWeb\Mowc\Modules\SmilepayShipping\Methods\Tcat;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class CreateOrder {

	private const UNIFIED_TCAT_ID = 'moksafowo_smilepay_shipping_tcat';

	
	public static function run( \WC_Order $order ): array {
		// 從訂單裡識別運送方式 → 決定走 CVS / TCAT / unified-TCat 路徑
		$method_id = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$method_id = (string) $m->get_method_id();
			break;
		}
		if ( '' === $method_id ) {
			return [ 'ok' => false, 'message' => __( '訂單無速買配運送方式。', 'mo-ectools' ) ];
		}

		// 寄件人資訊驗證
		$sender = Helper::sender_info();
		if ( '' === $sender['name'] || '' === $sender['phone'] ) {
			return [
				'ok'      => false,
				'message' => __( '請先到「WooCommerce → Moksa → 速買配 物流 → 寄件人資料」填入姓名與手機。', 'mo-ectools' ),
			];
		}

		// 多溫層 unified TCat → 走拆單流程
		if ( self::UNIFIED_TCAT_ID === $method_id ) {
			return self::run_unified_tcat( $order );
		}

		$is_cvs  = str_contains( $method_id, '_cvs_' );
		$is_tcat = str_contains( $method_id, '_tcat_' );
		if ( ! $is_cvs && ! $is_tcat ) {
			return [ 'ok' => false, 'message' => __( '不認得此速買配運送方式。', 'mo-ectools' ) ];
		}

		// Step 1 — 取 smseid（如果之前沒取過）
		$smseid = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_NO );
		if ( '' === $smseid ) {
			$step1 = self::do_step1_request_smseid( $order, $is_cvs, $method_id );
			if ( ! $step1['ok'] ) {
				/* translators: %s: error message */
				$order->add_order_note( sprintf( __( '速買配 取序號失敗：%s', 'mo-ectools' ), $step1['message'] ) );
				$order->save();
				return $step1;
			}
			$smseid = $step1['smseid'] ?? '';
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_NO, $smseid );
			$order->save();
		}
		if ( '' === $smseid ) {
			return [ 'ok' => false, 'message' => __( 'Step 1 沒取到 smseid。', 'mo-ectools' ) ];
		}

		// Step 2 — 確認建單
		if ( $is_cvs ) {
			return self::do_step2_cvs( $order, $smseid, $method_id );
		}
		return self::do_step2_tcat( $order, $smseid, $method_id );
	}

	
	private static function run_unified_tcat( \WC_Order $order ): array {
		$method   = self::resolve_order_shipping_method( $order, self::UNIFIED_TCAT_ID );
		$packages = SplitByTemp::for_order( $order, [ ProductTemp::NORMAL, ProductTemp::REFRIGERATED, ProductTemp::FROZEN ], $method instanceof \MoksaWeb\Mowc\Modules\Shipping\Methods\AbstractShippingMethod ? $method : null );
		if ( empty( $packages ) ) {
			return [ 'ok' => false, 'message' => __( '訂單沒有商品可建立物流單。', 'mo-ectools' ) ];
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
			$args = self::build_smseid_args_for_package( $order, $pkg );
			$step1 = ShippingRequest::request_smseid( $args );
			if ( ! $step1['ok'] ) {
				$errors[] = sprintf(
					/* translators: 1: temp label, 2: msg */
					__( '溫層 %1$s 取序號失敗：%2$s', 'mo-ectools' ),
					ProductTemp::label( $temp ),
					$step1['message']
				);
				continue;
			}
			$smseid = (string) ( $step1['smseid'] ?? '' );
			if ( '' === $smseid ) {
				/* translators: %s: temperature layer label */
				$errors[] = sprintf( __( '溫層 %s 取序號為空。', 'mo-ectools' ), ProductTemp::label( $temp ) );
				continue;
			}

			// Step 2 — 確認 TCat 建單
			$temperature_code = self::temp_to_temperature_code( $temp );
			$step2            = ShippingRequest::confirm_tcat( $smseid, $temperature_code );
			if ( ! $step2['ok'] ) {
				$errors[] = sprintf(
					/* translators: 1: temp label, 2: msg */
					__( '溫層 %1$s 取託運單失敗：%2$s', 'mo-ectools' ),
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
				'pay_zg'     => Tcat::payzg_for_temp( $temp ),
				'rtn_msg'    => 'OK',
				'created_at' => $now,
			];
		}

		if ( empty( $created ) && empty( $existing ) ) {
			$msg = $errors ? implode( ' / ', $errors ) : __( '建單失敗', 'mo-ectools' );
			$order->add_order_note( __( '速買配 物流單全數建立失敗：', 'mo-ectools' ) . $msg );
			$order->save();
			return [ 'ok' => false, 'message' => $msg ];
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
					'%s（Pay_zg=%s）— smseid=%s / 託運單號=%s',
					ProductTemp::label( (int) $r['temp'] ),
					(string) $r['pay_zg'],
					(string) $r['smseid'],
					(string) $r['track_num']
				);
			}
			$order->add_order_note( sprintf(
				/* translators: 1: count, 2: list */
				__( '速買配 黑貓建單成功（多溫層拆 %1$d 包）：%2$s', 'mo-ectools' ),
				count( $created ),
				"\n" . implode( "\n", $lines )
			) );
		} elseif ( ! empty( $created ) ) {
			$r = $created[0];
			$order->add_order_note( sprintf(
				/* translators: 1: temp 2: smseid 3: track_num */
				__( '速買配 黑貓建單成功 — %1$s（smseid=%2$s 託運單號=%3$s）', 'mo-ectools' ),
				ProductTemp::label( (int) $r['temp'] ),
				(string) $r['smseid'],
				(string) $r['track_num']
			) );
		}

		if ( ! empty( $errors ) ) {
			$order->add_order_note( __( '部分溫層建單失敗：', 'mo-ectools' ) . implode( ' / ', $errors ) );
		}

		$order->save();

		$last = end( $records );
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
			'Pay_zg'           => Tcat::payzg_for_temp( $temp ),
			'Pay_subzg'        => '',
			'Pur_name'         => mb_substr( $pur_name, 0, 5 ),
			'Tel_number'       => $phone,
			'Mobile_number'    => $phone,
			'Address'          => $address,
			'Email'            => $order->get_billing_email(),
			'Data_id'          => (string) $order->get_id() . 'T' . $temp,
			'od_sob'           => mb_substr( (string) $pkg['goods_name'], 0, 60 ),
			'Amount'           => max( 1, (int) $pkg['amount'] ),
			'Logistics_store'  => '',
			'Roturl'           => home_url( '/wc-api/smilepay_shipping_status' ),
			'Logistics_Roturl' => home_url( '/wc-api/smilepay_shipping_status' ),
			'Roturl_status'    => 'mowp1.0',
			'Remark'           => $order->get_customer_note(),
		];
	}

	
	private static function do_step1_request_smseid( \WC_Order $order, bool $is_cvs, string $method_id ): array {
		// SmilePay 對應 Pay_zg：78=黑貓常溫 / 79=冷藏 / 80=冷凍 / CVS 是 91 + Pay_subzg
		// 71/72/73/74 = 7-11/全家/萊爾富/OK C2C 純取貨；FM2/SE2 等是 B2C
		// 簡化：CVS 用 91，Pay_subzg 由 method_id 決定；TCAT 用 78/79/80
		$store_id   = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_ID );
		$store_name = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_NAME );
		$store_addr = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_ADDR );

		if ( $is_cvs && '' === $store_id ) {
			return [
				'ok'      => false,
				'message' => __( '此 CVS 訂單尚未選店。請顧客在結帳頁選店或商家手動指定。', 'mo-ectools' ),
			];
		}

		// 識別 Pay_zg + Pay_subzg
		[ $pay_zg, $pay_subzg ] = self::resolve_pay_zg( $method_id );

		$products = self::build_product_list( $order );
		$amount   = (int) ceil( (float) $order->get_total() );
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
			'Roturl'           => home_url( '/wc-api/smilepay_shipping_status' ),
			'Logistics_Roturl' => home_url( '/wc-api/smilepay_shipping_status' ),
			'Roturl_status'    => 'mowp1.0',
			'Remark'           => $order->get_customer_note(),
		];

		return ShippingRequest::request_smseid( $args );
	}

	
	private static function do_step2_cvs( \WC_Order $order, string $smseid, string $method_id ): array {
		$cvs_service_type = Helper::cvs_service_type();
		[ , $pay_subzg ]  = self::resolve_pay_zg( $method_id );
		$is_cod           = 'cod' === $order->get_payment_method();

		$result = ShippingRequest::confirm_cvs( $smseid, $pay_subzg, $cvs_service_type, $is_cod );
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			$order->add_order_note( sprintf( __( '速買配 CVS 確認建單失敗：%s', 'mo-ectools' ), $result['message'] ) );
			$order->save();
			return $result;
		}
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_LGS_TYPE, $cvs_service_type );
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_TYPE, str_starts_with( $method_id, 'moksafowo_smilepay_shipping_cvs_711' ) ? '711' . $cvs_service_type : 'FAMI' . $cvs_service_type );
		if ( ! empty( $result['payment_no'] ) ) {
			$order->update_meta_data( Keys::SMILEPAY_SHIPPING_PAY_NO, $result['payment_no'] );
		}
		$order->add_order_note( sprintf(
			/* translators: 1: smseid 2: payment_no */
			__( '速買配 CVS 建單成功（smseid=%1$s 取貨碼=%2$s）', 'mo-ectools' ),
			$smseid,
			$result['payment_no'] ?? $result['eshop_order_no'] ?? '-'
		) );
		$order->save();
		return [
			'ok'         => true,
			'message'    => 'OK',
			'smseid'     => $smseid,
			'payment_no' => $result['payment_no'] ?? '',
		];
	}

	
	private static function do_step2_tcat( \WC_Order $order, string $smseid, string $method_id ): array {
		$temp_map = [
			'moksafowo_smilepay_shipping_tcat_normal'  => '0001',
			'moksafowo_smilepay_shipping_tcat_refrige' => '0002',
			'moksafowo_smilepay_shipping_tcat_freeze'  => '0003',
		];
		$temperature = $temp_map[ $method_id ] ?? '0001';

		$result = ShippingRequest::confirm_tcat( $smseid, $temperature );
		if ( ! $result['ok'] ) {
			/* translators: %s: error message */
			$order->add_order_note( sprintf( __( '速買配 黑貓 取託運單失敗：%s', 'mo-ectools' ), $result['message'] ) );
			$order->save();
			return $result;
		}
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_TYPE, 'TCAT' );
		$order->update_meta_data( Keys::SMILEPAY_SHIPPING_TRACK_NO, $result['track_num'] ?? '' );
		$order->add_order_note( sprintf(
			/* translators: 1: smseid 2: track_num */
			__( '速買配 黑貓建單成功（smseid=%1$s 託運單號=%2$s）', 'mo-ectools' ),
			$smseid,
			$result['track_num'] ?? '-'
		) );
		$order->save();
		return [
			'ok'        => true,
			'message'   => 'OK',
			'smseid'    => $smseid,
			'track_num' => $result['track_num'] ?? '',
		];
	}

	
	private static function resolve_order_shipping_method( \WC_Order $order, string $method_id ): ?\WC_Shipping_Method {
		$map = \MoksaWeb\Mowc\Modules\SmilepayShipping\Module::method_map();
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

	private static function resolve_pay_zg( string $method_id ): array {
		// CVS 是 Pay_zg=91，Pay_subzg 區分超商：71=7-11 C2C, 72=全家 C2C, FM2=全家 B2C 大宗
		// TCAT: 78=常溫 / 79=冷藏 / 80=冷凍, Pay_subzg 空
		switch ( $method_id ) {
			case 'moksafowo_smilepay_shipping_cvs_711':
				return [ '91', 'B2C' === Helper::cvs_service_type() ? 'SE2' : '71' ];
			case 'moksafowo_smilepay_shipping_cvs_fami':
				return [ '91', 'B2C' === Helper::cvs_service_type() ? 'FM2' : '72' ];
			case 'moksafowo_smilepay_shipping_tcat_normal':
				return [ '78', '' ];
			case 'moksafowo_smilepay_shipping_tcat_refrige':
				return [ '79', '' ];
			case 'moksafowo_smilepay_shipping_tcat_freeze':
				return [ '80', '' ];
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
