<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Operations;

use Moksafowo\Modules\EcpayShipping\Api\Helper;
use Moksafowo\Modules\EcpayShipping\Module;
use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;
use Moksafowo\Modules\Shipping\Methods\AbstractHomeShippingMethod;
use Moksafowo\Modules\Shipping\Order\SplitByTemp;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class CreateOrder {


	public static function run( \WC_Order $order ): array {
		$method_id = self::detect_method_id( $order );
		if ( '' === $method_id ) {
			return [
				'ok'      => false,
				'message' => __( '此訂單不是綠界物流。', 'moksa-for-woocommerce' ),
			];
		}

		$class  = Module::method_map()[ $method_id ];
		$method = new $class();

		$is_cvs  = $method instanceof AbstractCvsShippingMethod;
		$is_home = $method instanceof AbstractHomeShippingMethod;
		if ( ! $is_cvs && ! $is_home ) {
			return [
				'ok'      => false,
				'message' => __( '不支援的物流型別。', 'moksa-for-woocommerce' ),
			];
		}

		$base_subtype    = (string) $method->logistics_sub_type();
		$supported_temps = array_map( 'intval', array_keys( $method->supported_temperatures() ) );
		if ( empty( $supported_temps ) ) {
			$supported_temps = [ ProductTemp::NORMAL ];
		}

		$method_in_order = self::resolve_order_shipping_method( $order, $method_id );
		$packages        = SplitByTemp::for_order( $order, $supported_temps, $method_in_order instanceof \Moksafowo\Modules\Shipping\Methods\AbstractShippingMethod ? $method_in_order : null );
		if ( empty( $packages ) ) {
			return [
				'ok'      => false,
				'message' => __( '訂單沒有商品可建立物流單。', 'moksa-for-woocommerce' ),
			];
		}

		if ( $is_cvs ) {
			$store_id = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );
			if ( '' === $store_id ) {
				return [
					'ok'      => false,
					'message' => __( '尚未選擇取貨門市。', 'moksa-for-woocommerce' ),
				];
			}
		}

		$is_cod = 'cod' === (string) $order->get_payment_method();
		$multi  = count( $packages ) > 1;
		$now    = current_time( 'mysql' );
		// 冪等：已建溫層不重建（刪除記錄後可重建）
		$existing_temps = [];
		foreach ( self::get_records( $order ) as $er ) {
			if ( isset( $er['temp'] ) && '' !== (string) $er['temp'] ) {
				$existing_temps[ (int) $er['temp'] ] = true;
			}
		}

		$created = [];
		$errors  = [];
		$skipped = [];

		foreach ( $packages as $package ) {
			$temp = (int) $package['temp'];

			if ( isset( $existing_temps[ $temp ] ) ) {
				$skipped[] = ProductTemp::label( $temp );
				continue;
			}

			$subtype = self::resolve_subtype_for_temp( $base_subtype, $temp );

			$group = Helper::group_for_subtype( $subtype );
			if ( ! Helper::has_credentials_for( $group ) ) {
				$errors[] = sprintf(
					/* translators: 1: temp label, 2: c2c|b2c */
					__( '溫層 %1$s（%2$s 商號）尚未設定憑證。', 'moksa-for-woocommerce' ),
					ProductTemp::label( $temp ),
					strtoupper( $group )
				);
				continue;
			}

			$mtn = Helper::generate_merchant_trade_no( $order->get_id() );
			if ( $multi ) {
				$mtn = mb_substr( $mtn, 0, 18 ) . 'T' . $temp;
			}

			$payload = self::build_payload(
				$order,
				$package,
				$mtn,
				$subtype,
				$is_cvs,
				$is_cod
			);

			$response = wp_safe_remote_post(
				Helper::create_endpoint(),
				[
					'timeout' => 30,
					'body'    => $payload,
				]
			);

			if ( is_wp_error( $response ) ) {
				Helper::log(
					'Express/Create wp_error',
					[
						'temp' => $temp,
						'msg'  => $response->get_error_message(),
					]
				);
				$errors[] = sprintf(
					/* translators: 1: temp label, 2: error message */
					__( '溫層 %1$s 建立失敗：%2$s', 'moksa-for-woocommerce' ),
					ProductTemp::label( $temp ),
					$response->get_error_message()
				);
				continue;
			}

			$body = (string) wp_remote_retrieve_body( $response );
			Helper::log(
				'Express/Create response',
				[
					'temp' => $temp,
					'body' => $body,
				]
			);

			if ( ! str_starts_with( $body, '1|' ) ) {
				[ $code, $msg ] = array_pad( explode( '|', $body, 2 ), 2, '' );
				$errors[]       = sprintf(
					/* translators: 1: temp label, 2: msg, 3: code */
					__( '溫層 %1$s 建立失敗：%2$s（狀態代碼 %3$s）', 'moksa-for-woocommerce' ),
					ProductTemp::label( $temp ),
					$msg,
					$code
				);
				continue;
			}

			$payload_str = substr( $body, 2 );
			parse_str( $payload_str, $parsed );

			$rtn_code     = (string) ( $parsed['RtnCode'] ?? '' );
			$rtn_msg      = (string) ( $parsed['RtnMsg'] ?? '' );
			$logistics_id = (string) ( $parsed['AllPayLogisticsID'] ?? '' );

			if ( '300' !== $rtn_code && '2001' !== $rtn_code ) {
				$errors[] = sprintf(
					/* translators: 1: temp label, 2: msg, 3: code */
					__( '溫層 %1$s 建立失敗：%2$s（狀態代碼 %3$s）', 'moksa-for-woocommerce' ),
					ProductTemp::label( $temp ),
					$rtn_msg,
					$rtn_code
				);
				continue;
			}

			$created[] = [
				'id'                => $logistics_id,
				'mtn'               => $mtn,
				'type'              => (string) $payload['LogisticsType'],
				'subtype'           => (string) $payload['LogisticsSubType'],
				'temp'              => (string) $temp,
				'amount'            => (string) (int) $package['amount'],
				'cvs_payment_no'    => isset( $parsed['CVSPaymentNo'] ) ? (string) $parsed['CVSPaymentNo'] : '',
				'cvs_validation_no' => isset( $parsed['CVSValidationNo'] ) ? (string) $parsed['CVSValidationNo'] : '',
				'booking_note'      => isset( $parsed['BookingNote'] ) ? (string) $parsed['BookingNote'] : '',
				'rtn_code'          => $rtn_code,
				'rtn_msg'           => $rtn_msg,
				'created_at'        => $now,
			];
		}

		if ( empty( $created ) && ! empty( $skipped ) && empty( $errors ) ) {
			return [
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: 溫層列表 */
					__( '此訂單物流單已建立（%s），未重複下單。如需整批重建，請先刪除既有記錄後再建立。', 'moksa-for-woocommerce' ),
					implode( '、', $skipped )
				),
			];
		}

		if ( empty( $created ) ) {
			$msg = $errors ? implode( ' / ', $errors ) : __( '建單失敗', 'moksa-for-woocommerce' );
			$order->add_order_note( __( '綠界物流單全數建立失敗：', 'moksa-for-woocommerce' ) . $msg );
			$order->save();
			return [
				'ok'      => false,
				'message' => $msg,
			];
		}

		// ECPay 在 CreateOrder 期間同步 fire IPN（獨立 PHP 進程），force-reload 防 WC INSERT 重複 meta
		$order->read_meta_data( true );
		$raw_records = $order->get_meta( Keys::ECPAY_LOGISTIC_RECORDS );
		$records     = is_array( $raw_records ) ? array_values( $raw_records ) : [];
		foreach ( $created as $r ) {
			$records[] = $r;
		}
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_RECORDS, $records );

		// 向下相容：mirror 最後一筆到 single keys
		$last = $created[ count( $created ) - 1 ];
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_ID, $last['id'] );
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_MERCHANT_TRADE_NO, $last['mtn'] );
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_TYPE, $last['type'] );
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_SUBTYPE, $last['subtype'] );
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_RTN_CODE, $last['rtn_code'] );
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_RTN_MSG, $last['rtn_msg'] );
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_CREATED_AT, $last['created_at'] );
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_CVS_PAYMENT_NO, $last['cvs_payment_no'] );
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_CVS_VALIDATION_NO, $last['cvs_validation_no'] );
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_BOOKING_NOTE, $last['booking_note'] );

		if ( count( $created ) > 1 ) {
			$lines = [];
			foreach ( $created as $r ) {
				$lines[] = sprintf(
					'%s（%s）— %s',
					ProductTemp::label( (int) $r['temp'] ),
					(string) $r['subtype'],
					(string) $r['id']
				);
			}
			$order->add_order_note(
				sprintf(
				/* translators: 1: count, 2: list of records */
					__( '綠界物流單建立成功（多溫層拆 %1$d 包）：%2$s', 'moksa-for-woocommerce' ),
					count( $created ),
					"\n" . implode( "\n", $lines )
				)
			);
		} else {
			$r = $created[0];
			$order->add_order_note(
				sprintf(
				/* translators: 1: logistics id, 2: rtn_msg */
					__( '綠界物流單建立成功 — 物流編號 %1$s（%2$s）', 'moksa-for-woocommerce' ),
					(string) $r['id'],
					(string) $r['rtn_msg']
				)
			);
		}

		if ( ! empty( $errors ) ) {
			$order->add_order_note( __( '部分溫層建單失敗：', 'moksa-for-woocommerce' ) . implode( ' / ', $errors ) );
		}

		if ( ! empty( $skipped ) ) {
			$order->add_order_note(
				sprintf(
				/* translators: %s: 溫層列表 */
					__( '已略過既有溫層避免重複下單：%s', 'moksa-for-woocommerce' ),
					implode( '、', $skipped )
				)
			);
		}

		$order->save();

		$result = [
			'ok'              => true,
			'message'         => (string) $last['rtn_msg'],
			'rtn_code'        => (string) $last['rtn_code'],
			'logistics_id'    => (string) $last['id'],
			'records_created' => count( $created ),
		];
		if ( ! empty( $errors ) ) {
			$result['warning'] = implode( ' / ', $errors );
		}
		return $result;
	}

	private static function resolve_subtype_for_temp( string $base_subtype, int $temp ): string {
		if ( 'UNIMART' === $base_subtype && ProductTemp::FROZEN === $temp ) {
			return 'UNIMARTFREEZE';
		}
		return $base_subtype;
	}


	private static function build_payload(
		\WC_Order $order,
		array $package,
		string $mtn,
		string $subtype,
		bool $is_cvs,
		bool $is_cod
	): array {
		$temp   = (int) $package['temp'];
		$amount = max( 1, (int) $package['amount'] );

		$payload = [
			'MerchantID'           => Helper::merchant_id( $subtype ),
			'MerchantTradeNo'      => $mtn,
			'MerchantTradeDate'    => date_i18n( 'Y/m/d H:i:s' ),
			'LogisticsType'        => $is_cvs ? 'CVS' : 'HOME',
			'LogisticsSubType'     => $subtype,
			'GoodsAmount'          => $amount,
			'IsCollection'         => $is_cod ? 'Y' : 'N',
			'CollectionAmount'     => $is_cod ? $amount : 0,
			'GoodsName'            => self::sanitize_goods_name( (string) $package['goods_name'] ),
			'SenderName'           => self::sender_name(),
			'SenderPhone'          => self::sender_phone(),
			'SenderCellPhone'      => self::sender_cellphone(),
			'ReceiverName'         => self::receiver_name( $order ),
			'ReceiverPhone'        => '',
			'ReceiverCellPhone'    => self::receiver_cellphone( $order ),
			'ReceiverEmail'        => $order->get_billing_email(),
			'TradeDesc'            => '',
			'ServerReplyURL'       => add_query_arg( 'wc-api', 'moksafowo_ecpay_shipping_status', home_url( '/' ) ),
			'ClientReplyURL'       => '',
			'LogisticsC2CReplyURL' => '',
			'Remark'               => '',
			'PlatformID'           => '',
		];

		if ( $is_cvs ) {
			$store_id                   = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );
			$payload['ReceiverStoreID'] = $store_id;
			$payload['ReturnStoreID']   = $store_id;
		} else {
			$payload['SenderZipCode']         = self::sender_zip();
			$payload['SenderAddress']         = self::sender_address();
			$payload['ReceiverZipCode']       = $order->get_shipping_postcode();
			$payload['ReceiverAddress']       = self::full_shipping_address( $order );
				$payload['Temperature']       = '000' . $temp;
			$payload['Distance']              = '00';
			$payload['Specification']         = '0001';
			$payload['ScheduledPickupTime']   = '4';
			$payload['ScheduledDeliveryTime'] = '4';
			$pkg_weight                       = (float) $package['weight'];
			$payload['GoodsWeight']           = (string) ( $pkg_weight > 0 ? min( 20, max( 1, (int) ceil( $pkg_weight ) ) ) : 1 );
		}

		$payload['CheckMacValue'] = Helper::generate_check_mac_value( $payload, $subtype );
		return $payload;
	}

	public static function get_records( \WC_Order $order ): array {
		$raw = $order->get_meta( Keys::ECPAY_LOGISTIC_RECORDS );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			return array_values( $raw );
		}
		$legacy_id = (string) $order->get_meta( Keys::ECPAY_LOGISTIC_ID );
		if ( '' === $legacy_id ) {
			return [];
		}
		return [
			[
				'id'                => $legacy_id,
				'mtn'               => (string) $order->get_meta( Keys::ECPAY_LOGISTIC_MERCHANT_TRADE_NO ),
				'type'              => (string) $order->get_meta( Keys::ECPAY_LOGISTIC_TYPE ),
				'subtype'           => (string) $order->get_meta( Keys::ECPAY_LOGISTIC_SUBTYPE ),
				'cvs_payment_no'    => (string) $order->get_meta( Keys::ECPAY_LOGISTIC_CVS_PAYMENT_NO ),
				'cvs_validation_no' => (string) $order->get_meta( Keys::ECPAY_LOGISTIC_CVS_VALIDATION_NO ),
				'booking_note'      => (string) $order->get_meta( Keys::ECPAY_LOGISTIC_BOOKING_NOTE ),
				'rtn_code'          => (string) $order->get_meta( Keys::ECPAY_LOGISTIC_RTN_CODE ),
				'rtn_msg'           => (string) $order->get_meta( Keys::ECPAY_LOGISTIC_RTN_MSG ),
				'created_at'        => (string) $order->get_meta( Keys::ECPAY_LOGISTIC_CREATED_AT ),
			],
		];
	}

	public static function update_record_status( \WC_Order $order, string $logistics_id, string $rtn_code, string $rtn_msg ): bool {
		if ( '' === $logistics_id ) {
			return false;
		}
		// legacy 單記錄訂單也須看到 rtn_* 更新
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_RTN_CODE, $rtn_code );
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_RTN_MSG, $rtn_msg );

		$raw = $order->get_meta( Keys::ECPAY_LOGISTIC_RECORDS );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return false;
		}
		$records = array_values( $raw );
		$updated = false;
		foreach ( $records as &$r ) {
			if ( ( $r['id'] ?? '' ) === $logistics_id ) {
				$r['rtn_code']   = $rtn_code;
				$r['rtn_msg']    = $rtn_msg;
				$r['updated_at'] = current_time( 'mysql' );
				$updated         = true;
				break;
			}
		}
		unset( $r );
		if ( ! $updated ) {
			return false;
		}
		$order->update_meta_data( Keys::ECPAY_LOGISTIC_RECORDS, $records );
		return true;
	}

	public static function delete_record( \WC_Order $order, string $logistics_id ): bool {
		$records = self::get_records( $order );
		$kept    = array_values( array_filter( $records, static fn( array $r ) => ( $r['id'] ?? '' ) !== $logistics_id ) );
		if ( count( $kept ) === count( $records ) ) {
			return false;
		}
		if ( empty( $kept ) ) {
			$order->delete_meta_data( Keys::ECPAY_LOGISTIC_RECORDS );
			foreach ( [
				Keys::ECPAY_LOGISTIC_ID,
				Keys::ECPAY_LOGISTIC_MERCHANT_TRADE_NO,
				Keys::ECPAY_LOGISTIC_TYPE,
				Keys::ECPAY_LOGISTIC_SUBTYPE,
				Keys::ECPAY_LOGISTIC_RTN_CODE,
				Keys::ECPAY_LOGISTIC_RTN_MSG,
				Keys::ECPAY_LOGISTIC_CREATED_AT,
				Keys::ECPAY_LOGISTIC_CVS_PAYMENT_NO,
				Keys::ECPAY_LOGISTIC_CVS_VALIDATION_NO,
				Keys::ECPAY_LOGISTIC_BOOKING_NOTE,
			] as $k ) {
				$order->delete_meta_data( $k );
			}
		} else {
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_RECORDS, $kept );
			$latest = end( $kept );
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_ID, $latest['id'] );
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_MERCHANT_TRADE_NO, $latest['mtn'] );
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_TYPE, $latest['type'] );
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_SUBTYPE, $latest['subtype'] );
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_RTN_CODE, $latest['rtn_code'] );
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_RTN_MSG, $latest['rtn_msg'] );
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_CREATED_AT, $latest['created_at'] );
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_CVS_PAYMENT_NO, $latest['cvs_payment_no'] );
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_CVS_VALIDATION_NO, $latest['cvs_validation_no'] );
			$order->update_meta_data( Keys::ECPAY_LOGISTIC_BOOKING_NOTE, $latest['booking_note'] );
		}
		$order->add_order_note(
			sprintf(
			/* translators: %s: AllPayLogisticsID */
				__( '已從網站刪除物流單記錄 #%s（綠界端不會收到通知，僅刪除本地紀錄）', 'moksa-for-woocommerce' ),
				$logistics_id
			)
		);
		$order->save();
		return true;
	}

	private static function detect_method_id( \WC_Order $order ): string {
		$map = Module::method_map();
		foreach ( $order->get_shipping_methods() as $method ) {
			$mid = $method->get_method_id();
			if ( isset( $map[ $mid ] ) ) {
				return $mid;
			}
		}
		return '';
	}

	private static function resolve_order_shipping_method( \WC_Order $order, string $method_id ): ?\WC_Shipping_Method {
		$map = Module::method_map();
		if ( ! isset( $map[ $method_id ] ) ) {
			return null;
		}
		foreach ( $order->get_shipping_methods() as $line ) {
			if ( (string) $line->get_method_id() !== $method_id ) {
				continue;
			}
			$instance_id = (int) $line->get_instance_id();
			$class       = $map[ $method_id ];
			$instance    = new $class( $instance_id );
			$instance->init_form_fields();
			$instance->init_settings();
			return $instance;
		}
		return null;
	}

	private static function sanitize_goods_name( string $name ): string {
		$name = preg_replace( '/[\^\'`!@#\$%\*\+\\\\\"<>|_\[\]]/u', '', $name ) ?? ''; // ECPay 禁特殊字元
		return mb_substr( $name, 0, 50 );
	}

	private static function sender_name(): string {
		return mb_substr( (string) get_option( 'moksafowo_ecpay_shipping_sender_name', '' ), 0, 10 );
	}

	private static function sender_phone(): string {
		return (string) get_option( 'moksafowo_ecpay_shipping_sender_phone', '' );
	}

	private static function sender_cellphone(): string {
		return (string) get_option( 'moksafowo_ecpay_shipping_sender_cellphone', '' );
	}

	private static function sender_zip(): string {
		$addr = (string) get_option( 'moksafowo_ecpay_shipping_sender_address', '' );
		return preg_match( '/(\d{3,5})/', $addr, $m ) ? $m[1] : '';
	}

	private static function sender_address(): string {
		return (string) get_option( 'moksafowo_ecpay_shipping_sender_address', '' );
	}

	private static function receiver_name( \WC_Order $order ): string {
		$name = trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		}
		return mb_substr( $name, 0, 10 );
	}

	private static function receiver_cellphone( \WC_Order $order ): string {
		$phone = $order->get_billing_phone();
		$phone = preg_replace( '/[^\d]/', '', $phone ) ?? '';
		return $phone;
	}

	private static function full_shipping_address( \WC_Order $order ): string {
		$state            = (string) $order->get_shipping_state();
		static $tw_states = null;
		if ( null === $tw_states ) {
			$tw_states = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Address/Data/states-tw.php';
			$tw_states = $tw_states['TW'] ?? [];
		}
		if ( '' !== $state && isset( $tw_states[ $state ] ) ) {
			$state = (string) $tw_states[ $state ];
		}

		// 鄉鎮市區落地於 shipping_city；city 空才退用 Block 結帳附加欄位
		$district = (string) $order->get_shipping_city();
		if ( '' === $district ) {
			$district = (string) $order->get_meta( '_wc_shipping/moksafowo/district' );
		}

		return trim(
			implode(
				'',
				[
					$state,
					$district,
					$order->get_shipping_address_1(),
					$order->get_shipping_address_2(),
				]
			)
		);
	}
}
