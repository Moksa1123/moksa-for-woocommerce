<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Operations;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Providers\SevenEleven\B2CUnified;
use Moksafowo\Modules\PayuniShipping\Providers\SevenEleven\C2CUnified;
use Moksafowo\Modules\PayuniShipping\Providers\TCat\HDUnified;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;
use Moksafowo\Modules\Shipping\Order\SplitByTemp;
use Moksafowo\Modules\Shipping\Temp\ProductTemp;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class CreateOrderUnified {

	public static function detect_method( \WC_Order $order ): ?object {
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid         = (string) $m->get_method_id();
			$instance_id = (int) $m->get_instance_id();
			$class       = null;
			if ( HDUnified::ID === $mid ) {
				$class = HDUnified::class;
			} elseif ( C2CUnified::ID === $mid ) {
				$class = C2CUnified::class;
			} elseif ( B2CUnified::ID === $mid ) {
				$class = B2CUnified::class;
			}
			if ( null === $class ) {
				continue;
			}
			$instance = new $class( $instance_id );
			$instance->init_form_fields();
			$instance->init_settings();
			return $instance;
		}
		return null;
	}


	public static function run( \WC_Order $order ): array {
		$method = self::detect_method( $order );
		if ( null === $method ) {
			return [
				'ok'      => false,
				'message' => __( '不是 PAYUNi unified method 訂單。', 'moksa-for-woocommerce' ),
			];
		}

		$is_cvs = ShipType::SEVEN === $method->moksafowo_payuni_ship_type();
		if ( $is_cvs ) {
			$store_id = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );
			if ( '' === $store_id ) {
				return [
					'ok'      => false,
					'message' => __( '尚未選擇取貨門市。', 'moksa-for-woocommerce' ),
				];
			}
		}

		$supported_temps = array_map( 'intval', array_keys( $method->supported_temperatures() ) );
		$packages        = SplitByTemp::for_order( $order, $supported_temps, $method instanceof \Moksafowo\Modules\Shipping\Methods\AbstractShippingMethod ? $method : null );
		if ( empty( $packages ) ) {
			return [
				'ok'      => false,
				'message' => __( '訂單沒有商品可建立物流單。', 'moksa-for-woocommerce' ),
			];
		}

		$existing       = self::get_records( $order );
		$existing_temps = [];
		foreach ( $existing as $r ) {
			$t = (int) ( $r['temp'] ?? 0 );
			if ( $t > 0 ) {
				$existing_temps[ $t ] = true;
			}
		}

		$created = [];
		$errors  = [];
		$now     = current_time( 'mysql' );

		foreach ( $packages as $pkg ) {
			$temp = (int) $pkg['temp'];
			if ( isset( $existing_temps[ $temp ] ) ) {
				continue;
			}

			$args     = self::build_request_args_for_package( $order, $pkg, $method );
			$response = self::call_api( $args, $method );
			if ( ! $response['ok'] ) {
				$errors[] = sprintf(
					/* translators: 1: temp label, 2: msg */
					__( '溫層 %1$s 建單失敗：%2$s', 'moksa-for-woocommerce' ),
					ProductTemp::label( $temp ),
					$response['message']
				);
				continue;
			}

			$created[] = [
				'ship_trade_no' => (string) ( $response['data']['ShipTradeNo'] ?? '' ),
				'mer_trade_no'  => (string) $args['MerTradeNo'],
				'odno'          => (string) ( $response['data']['Odno'] ?? '' ),
				'partner_id'    => (string) ( $response['data']['PartnerId'] ?? '' ),
				'file_no'       => (string) ( $response['data']['FileNo'] ?? '' ),
				'validation_no' => (string) ( $response['data']['ValidationNo'] ?? '' ),
				'temp'          => (string) $temp,
				'goods_type'    => $method::moksafowo_payuni_goods_type_for_temp( $temp ),
				'lgs_type'      => $method->moksafowo_payuni_lgs_type(),
				'ship_type'     => $method->moksafowo_payuni_ship_type(),
				'amount'        => (string) (int) $pkg['amount'],
				'goods_name'    => (string) $pkg['goods_name'],
				'rtn_msg'       => (string) ( $response['data']['Message'] ?? 'OK' ),
				'created_at'    => $now,
			];
		}

		if ( empty( $created ) && empty( $existing ) ) {
			$msg = $errors ? implode( ' / ', $errors ) : __( '建單失敗', 'moksa-for-woocommerce' );
			$order->add_order_note( __( 'PAYUNi 物流單全數建立失敗：', 'moksa-for-woocommerce' ) . $msg );
			$order->save();
			return [
				'ok'      => false,
				'message' => $msg,
			];
		}

		$records = $existing;
		foreach ( $created as $r ) {
			$records[] = $r;
		}
		$order->update_meta_data( Keys::PAYUNI_SHIPPING_RECORDS, $records );

		// Mirror 最新一筆到 single-key meta（向下相容 OrderMetaBox / Admin UI）
		if ( ! empty( $records ) ) {
			$last = end( $records );
			$order->update_meta_data( OrderMeta::ShipTradeNo, (string) $last['ship_trade_no'] );
			$order->update_meta_data( OrderMeta::Odno, (string) $last['odno'] );
			$order->update_meta_data( OrderMeta::ShipType, (string) $last['ship_type'] );
			$order->update_meta_data( OrderMeta::LgsType, (string) $last['lgs_type'] );
			$order->update_meta_data( OrderMeta::GoodsType, (string) $last['goods_type'] );
			if ( ! empty( $last['file_no'] ) ) {
				$order->update_meta_data( OrderMeta::FileNo, (string) $last['file_no'] );
			}
		}

		if ( count( $created ) > 1 ) {
			$lines = [];
			foreach ( $created as $r ) {
				$lines[] = sprintf(
					'%s — 物流序號 %s 取件編號 %s',
					ProductTemp::label( (int) $r['temp'] ),
					(string) $r['ship_trade_no'],
					(string) $r['odno']
				);
			}
			$order->add_order_note(
				sprintf(
				/* translators: 1: count, 2: list */
					__( 'PAYUNi 黑貓建單成功（多溫層拆 %1$d 包）：%2$s', 'moksa-for-woocommerce' ),
					count( $created ),
					"\n" . implode( "\n", $lines )
				)
			);
		} elseif ( ! empty( $created ) ) {
			$r = $created[0];
			$order->add_order_note(
				sprintf(
				/* translators: 1: temp label, 2: PAYUNi ship trade no, 3: pickup no */
					__( 'PAYUNi 黑貓宅配建單成功 — %1$s（物流序號 %2$s 取件編號 %3$s）', 'moksa-for-woocommerce' ),
					ProductTemp::label( (int) $r['temp'] ),
					(string) $r['ship_trade_no'],
					(string) $r['odno']
				)
			);
		}

		if ( ! empty( $errors ) ) {
			$order->add_order_note( __( '部分溫層建單失敗：', 'moksa-for-woocommerce' ) . implode( ' / ', $errors ) );
		}

		$order->save();

		$result = [
			'ok'              => true,
			'message'         => 'OK',
			'records_created' => count( $created ),
		];
		if ( ! empty( $errors ) ) {
			$result['warning'] = implode( ' / ', $errors );
		}
		return $result;
	}

	public static function get_records( \WC_Order $order ): array {
		$raw = $order->get_meta( Keys::PAYUNI_SHIPPING_RECORDS );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			return array_values( $raw );
		}
		return [];
	}


	private static function build_request_args_for_package( \WC_Order $order, array $pkg, $method ): array {
		$temp         = (int) $pkg['temp'];
		$goods_type   = $method::moksafowo_payuni_goods_type_for_temp( $temp );
		$ship_type    = $method->moksafowo_payuni_ship_type();
		$lgs_type     = $method->moksafowo_payuni_lgs_type();
		$is_cvs       = ShipType::SEVEN === $ship_type;
		$mer_trade_no = self::generate_mer_trade_no( $order, $temp, $is_cvs );

		$consignee_name = trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() );
		if ( '' === $consignee_name ) {
			$consignee_name = trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		}
		$consignee_mobile = PayuniShipping::moksafowo_payuni_get_shipping_phone( $order );
		if ( '' === (string) $consignee_mobile ) {
			$consignee_mobile = $order->get_billing_phone();
		}

		$args = [
			'MerID'           => PayuniShipping::get_mer_id(),
			'Timestamp'       => time(),
			'MerTradeNo'      => $mer_trade_no,
			'GoodsType'       => $goods_type,
			'LgsType'         => $lgs_type,
			'ShipType'        => $ship_type,
			'TradeAmt'        => max( 1, (int) $pkg['amount'] ),
			'ServiceType'     => ( 'cod' === (string) $order->get_payment_method() ) ? '1' : '3',
			'Consignee'       => mb_substr( $consignee_name, 0, 10 ),
			'ConsigneeMail'   => $order->get_billing_email(),
			'ConsigneeMobile' => $consignee_mobile,
			'RefundStoreID'   => '',
			'SenderName'      => (string) get_option( 'moksafowo_payuni_shipping_sender_name', '' ),
			'SenderMobile'    => (string) get_option( 'moksafowo_payuni_shipping_sender_phone', '' ),
		];

		if ( $is_cvs ) {
			$args['StoreID']   = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );
			$args['NotifyURL'] = wc()->api_request_url( 'moksafowo_payuni_shipping_711_notify' );
		} else {
			$args['StoreID']          = '';
			$args['ConsigneeAddress'] = self::get_shipping_address( $order );
			$args['DeliveryTimeTag']  = PayuniShipping::get_tcat_delivery_time();
			$args['ProdDesc']         = mb_substr( (string) $pkg['goods_name'], 0, 50 );
			$args['NotifyURL']        = wc()->api_request_url( 'moksafowo_payuni_shipping_tcat_notify' );
		}

		return apply_filters( 'moksafowo_payuni_shipping_unified_order_request_args', $args, $order, $pkg, $method );
	}

	private static function generate_mer_trade_no( \WC_Order $order, int $temp, bool $is_cvs = false ): string {
		$prefix = $is_cvs ? 'PUC' : 'PUL';
		$base   = $prefix . str_pad( (string) $order->get_id(), 6, '0', STR_PAD_LEFT ) . 'R' . substr( (string) wp_generate_uuid4(), 0, 4 );
		return mb_substr( $base, 0, 18 ) . 'T' . $temp;
	}

	private static function get_shipping_address( \WC_Order $order ): string {
		// shipping_state 可能是英文，走 state_label 轉中文；否則黑貓 HOME01064。
		$state = \Moksafowo\Modules\Address\TwAddress::state_label( (string) $order->get_shipping_state() );
		// 鄉鎮市區落地於 shipping_city；city 空才退用 Block 結帳附加欄位
		$city = (string) $order->get_shipping_city();
		if ( '' === $city ) {
			$city = (string) $order->get_meta( '_wc_shipping/moksafowo/district' );
		}

		if ( '' !== $city && '' !== $state && $city === $state ) {
			$city = '';
		}

		return trim(
			implode(
				'',
				[
					$state,
					$city,
					$order->get_shipping_address_1(),
					$order->get_shipping_address_2(),
				]
			)
		);
	}


	private static function call_api( array $args, $method ): array {
		PayuniShipping::log( 'CreateOrderUnified request: ' . wp_json_encode( $args, JSON_UNESCAPED_UNICODE ), 'info' );

		$encrypted = PayuniShipping::encrypt( $args );
		$endpoint  = $method->moksafowo_payuni_api_endpoint();
		$url       = PayuniShipping::$api_url . '/' . $endpoint . '/trade';

		$response = wp_remote_post(
			$url,
			[
				'timeout'     => 45,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent'   => 'WordPress',
				],
				'body'        => [
					'MerID'       => PayuniShipping::get_mer_id(),
					'Version'     => '1.0',
					'EncryptInfo' => $encrypted,
					'HashInfo'    => PayuniShipping::hash_info( $encrypted ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			PayuniShipping::log( 'CreateOrderUnified wp_error: ' . $msg, 'error' );
			return [
				'ok'      => false,
				'message' => $msg,
			];
		}

		$body = (string) wp_remote_retrieve_body( $response );
		PayuniShipping::log( 'CreateOrderUnified response body: ' . substr( $body, 0, 500 ), 'info' );

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return [
				'ok'      => false,
				'message' => 'Invalid JSON response: ' . substr( $body, 0, 200 ),
			];
		}

		$status = (string) ( $json['Status'] ?? '' );
		if ( 'SUCCESS' !== $status ) {
			// PAYUNi 錯誤原因加密在 EncryptInfo；plaintext Message 通常空
			$detail = (string) ( $json['Message'] ?? '' );
			$enc    = (string) ( $json['EncryptInfo'] ?? '' );
			if ( '' === $detail && '' !== $enc ) {
				$err = PayuniShipping::decrypt( $enc );
				if ( is_array( $err ) ) {
					$detail = (string) ( $err['Message'] ?? $err['ErrorMessage'] ?? $err['ErrMsg'] ?? $err['RtnMsg'] ?? '' );
				}
			}
			return [
				'ok'      => false,
				'message' => trim( $status . ': ' . ( '' !== $detail ? $detail : 'unknown' ) ),
			];
		}

		$encrypt_info = (string) ( $json['EncryptInfo'] ?? '' );
		if ( '' === $encrypt_info ) {
			return [
				'ok'      => false,
				'message' => 'EncryptInfo missing in response',
			];
		}

		$decoded = PayuniShipping::decrypt( $encrypt_info );
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return [
				'ok'      => false,
				'message' => 'Decrypt failed',
			];
		}

		PayuniShipping::log( 'CreateOrderUnified decoded: ' . wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE ), 'info' );

		return [
			'ok'      => true,
			'message' => 'OK',
			'data'    => $decoded,
		];
	}
}
