<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Api;

defined( 'ABSPATH' ) || exit;

final class ShippingRequest {


	public static function request_smseid( array $args ): array {
		$post = array_merge(
			[
				'Dcvc'       => Helper::dcvc(),
				'Rvg2c'      => Helper::rvg2c(),
				'Verify_key' => Helper::verify_key(),
			],
			$args
		);

		Helper::log(
			'SP_API request_smseid',
			[
				'data_id'   => $args['Data_id'] ?? '',
				'pay_zg'    => $args['Pay_zg'] ?? '',
				'pay_subzg' => $args['Pay_subzg'] ?? '',
			]
		);

		$response = wp_remote_post(
			Helper::ENDPOINT_SP_API,
			[
				'body'      => http_build_query( $post ),
				'timeout'   => 30,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'message' => $response->get_error_message(),
			];
		}
		$body = (string) wp_remote_retrieve_body( $response );
		Helper::log( 'SP_API response', [ 'body' => substr( $body, 0, 500 ) ] );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- remote XML response — malformed input returns false, validated below; @ suppresses the warning so the simplexml return value can be validated explicitly.
		$xml = @simplexml_load_string( $body );
		if ( ! $xml ) {
			return [
				'ok'      => false,
				'message' => 'XML parse failed: ' . substr( $body, 0, 200 ),
			];
		}
		$status = (string) ( $xml->Status ?? '' );
		if ( '1' !== $status ) {
			$desc = (string) ( $xml->Desc ?? 'unknown error' );
			return [
				'ok'      => false,
				'status'  => $status,
				'message' => trim( $status . ': ' . $desc ),
			];
		}
		return [
			'ok'     => true,
			'status' => $status,
			'smseid' => (string) ( $xml->SmilePayNO ?? '' ),
		];
	}


	public static function confirm_cvs( string $smseid, string $pay_subzg, string $cvs_service_type, bool $is_cod ): array {
		// C2C 走 C2CPayment.asp（取貨付款）或 C2CPaymentU.asp（純取貨），B2C 走 B2CPayment.asp
		$endpoint = Helper::ENDPOINT_C2C_API; // 預設 C2C cod
		$post     = [
			'Dcvc'       => Helper::dcvc(),
			'Verify_key' => Helper::verify_key(),
			'smseid'     => $smseid,
			'Pay_subzg'  => $pay_subzg,
			'types'      => 'Xml',
		];
		if ( 'B2C' === $cvs_service_type ) {
			$endpoint = Helper::ENDPOINT_B2C_API;
			$post     = [
				'Dcvc'       => Helper::dcvc(),
				'Verify_key' => Helper::verify_key(),
				'smseid'     => $smseid,
			];
		} elseif ( ! $is_cod ) {
			$endpoint = Helper::ENDPOINT_C2CU_API;
		}

		Helper::log(
			'confirm_cvs request',
			[
				'endpoint' => $endpoint,
				'smseid'   => $smseid,
				'cvs_type' => $cvs_service_type,
			]
		);
		$response = wp_remote_post(
			$endpoint,
			[
				'body'      => http_build_query( $post ),
				'timeout'   => 30,
				'sslverify' => true,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'message' => $response->get_error_message(),
			];
		}
		$body = (string) wp_remote_retrieve_body( $response );
		Helper::log( 'confirm_cvs response', [ 'body' => substr( $body, 0, 500 ) ] );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- remote XML response — malformed input returns false, validated below; @ suppresses the warning so the simplexml return value can be validated explicitly.
		$xml = @simplexml_load_string( $body );
		if ( ! $xml ) {
			return [
				'ok'      => false,
				'message' => 'XML parse failed: ' . substr( $body, 0, 200 ),
			];
		}
		if ( '1' !== (string) ( $xml->Status ?? '' ) ) {
			return [
				'ok'      => false,
				'message' => trim( ( $xml->Status ?? '' ) . ': ' . ( $xml->Desc ?? 'unknown' ) ),
			];
		}

		// C2C / B2C 欄位名不同 — 兼容
		if ( 'B2C' === $cvs_service_type ) {
			return [
				'ok'             => true,
				'eshop_order_no' => (string) ( $xml->EshopOrderNo ?? '' ),
				'store'          => [
					'id'   => (string) ( $xml->Storeid ?? '' ),
					'name' => (string) ( $xml->StoreName ?? '' ),
				],
			];
		}
		return [
			'ok'         => true,
			'payment_no' => (string) ( $xml->paymentno ?? '' ) . (string) ( $xml->validationno ?? '' ),
			'store'      => [
				'id'   => (string) ( $xml->storeid ?? '' ),
				'name' => (string) ( $xml->storename ?? '' ),
			],
		];
	}


	public static function confirm_tcat( string $smseid, string $temperature, string $package_size = '60' ): array {
		$post = [
			'Dcvc'         => Helper::dcvc(),
			'Verify_key'   => Helper::verify_key(),
			'smseid'       => $smseid,
			'package_size' => $package_size,
			'temperature'  => $temperature,
		];
		Helper::log(
			'confirm_tcat request',
			[
				'smseid'      => $smseid,
				'temperature' => $temperature,
			]
		);
		$response = wp_remote_post(
			Helper::ENDPOINT_TCAT_GET_TRACKNUM,
			[
				'body'      => http_build_query( $post ),
				'timeout'   => 30,
				'sslverify' => true,
			]
		);
		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'message' => $response->get_error_message(),
			];
		}
		$body = (string) wp_remote_retrieve_body( $response );
		Helper::log( 'confirm_tcat response', [ 'body' => substr( $body, 0, 500 ) ] );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- remote XML response — malformed input returns false, validated below; @ suppresses the warning so the simplexml return value can be validated explicitly.
		$xml = @simplexml_load_string( $body );
		if ( ! $xml ) {
			return [
				'ok'      => false,
				'message' => 'XML parse failed: ' . substr( $body, 0, 200 ),
			];
		}
		if ( '1' !== (string) ( $xml->Status ?? '' ) ) {
			return [
				'ok'      => false,
				'message' => trim( ( $xml->Status ?? '' ) . ': ' . ( $xml->Desc ?? 'unknown' ) ),
			];
		}
		return [
			'ok'        => true,
			'track_num' => (string) ( $xml->TrackNum ?? '' ),
		];
	}

	public static function build_emap_url( string $types_server, string $tempvar, string $return_url, string $interface = 'WEB' ): string {
		return Helper::ENDPOINT_LOGISTIC_EMAP . '?' . http_build_query(
			[
				'method'         => 'GET',
				'TypesServer'    => $types_server,
				'TypesInterface' => $interface,
				'tempvar'        => $tempvar,
				'url'            => $return_url,
			]
		);
	}
}
