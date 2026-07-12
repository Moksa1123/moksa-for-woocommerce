<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping\Api;

defined( 'ABSPATH' ) || exit;

final class ShippingRequest {

	private const BASE_SANDBOX = 'https://ccore.newebpay.com';
	private const BASE_PROD    = 'https://core.newebpay.com';

	private const PATH_STORE_MAP       = '/API/Logistic/storeMap';        // B51
	private const PATH_CREATE_SHIPMENT = '/API/Logistic/createShipment';  // B52
	private const PATH_GET_SHIPMENT_NO = '/API/Logistic/getShipmentNo';   // B53
	private const PATH_PRINT_LABEL     = '/API/Logistic/printLabel';      // B54
	private const PATH_QUERY_SHIPMENT  = '/API/Logistic/queryShipment';   // B55
	private const PATH_MODIFY_SHIPMENT = '/API/Logistic/modifyShipment';  // B56
	private const PATH_TRACE           = '/API/Logistic/trace';           // B57

	private static function base(): string {
		return Helper::is_sandbox() ? self::BASE_SANDBOX : self::BASE_PROD;
	}


	public static function open_store_map( array $args ): array {
		$encrypt = self::encrypt_args( array_merge( $args, [ 'TimeStamp' => (string) time() ] ) );
		if ( ! $encrypt['ok'] ) {
			return [
				'ok'        => false,
				'message'   => $encrypt['message'],
				'api_url'   => '',
				'form_data' => [],
			];
		}
		return [
			'ok'        => true,
			'api_url'   => self::base() . self::PATH_STORE_MAP,
			'form_data' => self::wrap_post( $encrypt['data'], $encrypt['hash'] ),
		];
	}


	public static function create_shipment( array $args ): array {
		return self::call( self::PATH_CREATE_SHIPMENT, array_merge( $args, [ 'TimeStamp' => (string) time() ] ) );
	}

	public static function get_shipment_no( array $merchant_order_nos ): array {
		return self::call(
			self::PATH_GET_SHIPMENT_NO,
			[
				'MerchantOrderNo' => $merchant_order_nos,
				'TimeStamp'       => (string) time(),
			]
		);
	}


	public static function print_label( $merchant_order_nos, string $lgs_type, string $ship_type ): array {
		$nos     = is_array( $merchant_order_nos ) ? $merchant_order_nos : [ $merchant_order_nos ];
		$encrypt = self::encrypt_args(
			[
				'MerchantOrderNo' => $nos,
				'LgsType'         => $lgs_type,
				'ShipType'        => $ship_type,
				'TimeStamp'       => (string) time(),
			]
		);
		if ( ! $encrypt['ok'] ) {
			return [
				'ok'        => false,
				'message'   => $encrypt['message'],
				'api_url'   => '',
				'form_data' => [],
			];
		}
		return [
			'ok'        => true,
			'api_url'   => self::base() . self::PATH_PRINT_LABEL,
			'form_data' => self::wrap_post( $encrypt['data'], $encrypt['hash'] ),
		];
	}

	public static function query_shipment( string $merchant_order_no ): array {
		return self::call(
			self::PATH_QUERY_SHIPMENT,
			[
				'MerchantOrderNo' => $merchant_order_no,
				'TimeStamp'       => (string) time(),
			]
		);
	}


	public static function modify_shipment( array $args ): array {
		return self::call( self::PATH_MODIFY_SHIPMENT, array_merge( $args, [ 'TimeStamp' => (string) time() ] ) );
	}

	public static function trace( string $merchant_order_no ): array {
		return self::call(
			self::PATH_TRACE,
			[
				'MerchantOrderNo' => $merchant_order_no,
				'TimeStamp'       => (string) time(),
			]
		);
	}



	private static function call( string $path, array $args ): array {
		$encrypt = self::encrypt_args( $args );
		if ( ! $encrypt['ok'] ) {
			return [
				'ok'      => false,
				'message' => $encrypt['message'],
			];
		}
		$post = self::wrap_post( $encrypt['data'], $encrypt['hash'] );
		$resp = wp_remote_post(
			self::base() . $path,
			[
				'body'    => $post,
				'timeout' => 30,
			]
		);
		if ( is_wp_error( $resp ) ) {
			return [
				'ok'      => false,
				'message' => $resp->get_error_message(),
			];
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		if ( 200 !== $code ) {
			return [
				'ok'      => false,
				'message' => 'HTTP ' . $code,
			];
		}
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return [
				'ok'      => false,
				'message' => __( '藍新物流回傳格式錯誤', 'mo-ectools' ),
			];
		}
		Helper::log(
			'shipping API ' . $path,
			[
				'status'  => (string) ( $decoded['Status'] ?? '' ),
				'message' => (string) ( $decoded['Message'] ?? '' ),
			]
		);

		if ( 'SUCCESS' !== ( $decoded['Status'] ?? '' ) ) {
			return [
				'ok'      => false,
				'message' => sprintf( '%s (%s)', $decoded['Message'] ?? '', $decoded['Status'] ?? '' ),
			];
		}

		$inner = isset( $decoded['EncryptData'] ) ? Helper::decrypt_trade_info( (string) $decoded['EncryptData'] ) : null;
		return [
			'ok'      => true,
			'message' => (string) ( $decoded['Message'] ?? 'OK' ),
			'data'    => $inner ?? [],
		];
	}


	private static function encrypt_args( array $args ): array {
		try {
			$query = http_build_query( $args );
			try {
				$hex = \Moksafowo\Crypto\Aes::encrypt_cbc_hex( $query, Helper::hash_key(), Helper::hash_iv() );
			} catch ( \Throwable $e ) {
				return [
					'ok'      => false,
					'message' => 'AES-256-CBC encrypt failed',
				];
			}
			$hash = strtoupper( hash( 'sha256', 'HashKey=' . Helper::hash_key() . '&' . $hex . '&HashIV=' . Helper::hash_iv() ) );
			return [
				'ok'      => true,
				'message' => 'OK',
				'data'    => $hex,
				'hash'    => $hash,
			];
		} catch ( \Throwable $e ) {
			return [
				'ok'      => false,
				'message' => $e->getMessage(),
			];
		}
	}

	private static function wrap_post( string $encrypt_data, string $hash_data ): array {
		return [
			'UID_'         => Helper::merchant_id(),
			'EncryptData_' => $encrypt_data,
			'HashData_'    => $hash_data,
			'Version_'     => '1.0',
			'RespondType_' => 'JSON',
		];
	}
}
