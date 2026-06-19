<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Api;

use MoksaWeb\Mowc\Crypto\Aes;

defined( 'ABSPATH' ) || exit;

final class PaymentRequest {

	private const PATH_CANCEL        = '/API/CreditCard/Cancel';
	private const PATH_CLOSE         = '/API/CreditCard/Close';
	private const PATH_QUERY         = '/API/QueryTradeInfo';
	private const PATH_WALLET_REFUND = '/API/EWallet/refund';
	private const PATH_BNPL_REFUND   = '/API/Bnpl/refund';
	private const PATH_BNPL_SETTLE   = '/API/Bnpl/settle';


	public static function cancel( array $args ): array {
		return self::call_encrypted(
			self::PATH_CANCEL,
			array_merge(
				$args,
				[
					'RespondType' => 'JSON',
					'Version'     => '1.0',
					'TimeStamp'   => (string) time(),
				]
			)
		);
	}


	public static function close( array $args ): array {
		return self::call_encrypted(
			self::PATH_CLOSE,
			array_merge(
				$args,
				[
					'RespondType' => 'JSON',
					'Version'     => '1.1',
					'TimeStamp'   => (string) time(),
					'CloseType'   => 1,
				]
			)
		);
	}


	public static function refund( array $args ): array {
		return self::call_encrypted(
			self::PATH_CLOSE,
			array_merge(
				$args,
				[
					'RespondType' => 'JSON',
					'Version'     => '1.1',
					'TimeStamp'   => (string) time(),
					'CloseType'   => 2,
				]
			)
		);
	}

	public static function cancel_close( array $args ): array {
		return self::call_encrypted(
			self::PATH_CLOSE,
			array_merge(
				$args,
				[
					'RespondType' => 'JSON',
					'Version'     => '1.1',
					'TimeStamp'   => (string) time(),
					'CloseType'   => 1,
					'Cancel'      => 1,
				]
			)
		);
	}

	public static function cancel_refund( array $args ): array {
		return self::call_encrypted(
			self::PATH_CLOSE,
			array_merge(
				$args,
				[
					'RespondType' => 'JSON',
					'Version'     => '1.1',
					'TimeStamp'   => (string) time(),
					'CloseType'   => 2,
					'Cancel'      => 1,
				]
			)
		);
	}


	public static function query( string $merchant_order_no, int $amt ): array {
		$args = [
			'MerchantID'      => Helper::merchant_id(),
			'Version'         => '1.3',
			'RespondType'     => 'JSON',
			'TimeStamp'       => (string) time(),
			'MerchantOrderNo' => $merchant_order_no,
			'Amt'             => $amt,
		];
		ksort( $args );
		$check_str          = http_build_query( $args );
		$args['CheckValue'] = strtoupper( hash( 'sha256', 'IV=' . Helper::hash_iv() . '&' . $check_str . '&Key=' . Helper::hash_key() ) );

		$resp = wp_remote_post(
			Helper::base_url() . self::PATH_QUERY,
			[
				'body'    => $args,
				'timeout' => 30,
			]
		);
		if ( is_wp_error( $resp ) ) {
			return [
				'ok'      => false,
				'message' => $resp->get_error_message(),
			];
		}
		$body    = wp_remote_retrieve_body( $resp );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return [
				'ok'      => false,
				'message' => __( '藍新查詢回傳格式錯誤', 'mo-ectools' ),
			];
		}
		Helper::log(
			'B02 query',
			[
				'mtn'    => $merchant_order_no,
				'status' => (string) ( $decoded['Status'] ?? '' ),
			]
		);
		if ( 'SUCCESS' !== ( $decoded['Status'] ?? '' ) ) {
			return [
				'ok'      => false,
				'message' => sprintf( '%s', $decoded['Message'] ?? '' ),
			];
		}
		return [
			'ok'      => true,
			'message' => 'OK',
			'data'    => (array) ( $decoded['Result'] ?? [] ),
		];
	}


	public static function wallet_refund( array $args ): array {
		return self::call_wallet_style( self::PATH_WALLET_REFUND, array_merge( $args, [ 'TimeStamp' => (string) time() ] ), '1.0' );
	}


	public static function bnpl_refund( array $args ): array {
		return self::call_wallet_style( self::PATH_BNPL_REFUND, array_merge( $args, [ 'TimeStamp' => (string) time() ] ), '1.1', false );
	}


	public static function bnpl_settle( array $args ): array {
		return self::call_wallet_style( self::PATH_BNPL_SETTLE, array_merge( $args, [ 'TimeStamp' => (string) time() ] ), '1.1', false );
	}


	private static function call_wallet_style( string $path, array $args, string $version, bool $use_json = true ): array {
		$payload = $use_json ? wp_json_encode( $args, JSON_UNESCAPED_UNICODE ) : http_build_query( $args );
		if ( false === $payload ) {
			return [
				'ok'      => false,
				'message' => 'JSON encode failed',
			];
		}
		try {
			$hex = Aes::encrypt_cbc_hex( $payload, Helper::hash_key(), Helper::hash_iv() );
		} catch ( \Throwable $e ) {
			return [
				'ok'      => false,
				'message' => 'AES-256-CBC encrypt failed',
			];
		}
		$hash = strtoupper( hash( 'sha256', 'HashKey=' . Helper::hash_key() . '&' . $hex . '&HashIV=' . Helper::hash_iv() ) );

		$resp = wp_remote_post(
			Helper::base_url() . $path,
			[
				'body'    => [
					'UID_'         => Helper::merchant_id(),
					'EncryptData_' => $hex,
					'HashData_'    => $hash,
					'Version_'     => $version,
					'RespondType_' => 'JSON',
				],
				'timeout' => 30,
			]
		);
		if ( is_wp_error( $resp ) ) {
			return [
				'ok'      => false,
				'message' => $resp->get_error_message(),
			];
		}
		$body    = wp_remote_retrieve_body( $resp );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			parse_str( $body, $decoded );
		}
		Helper::log( 'API ' . $path, [ 'status' => (string) ( $decoded['Status'] ?? '' ) ] );
		// NewebPay B06 reports SUCCESS as 1000; B07/B62 use "SUCCESS"
		$ok = ( '1000' === ( $decoded['Status'] ?? '' ) ) || ( 'SUCCESS' === ( $decoded['Status'] ?? '' ) );
		if ( ! $ok ) {
			return [
				'ok'      => false,
				'message' => (string) ( $decoded['Message'] ?? 'Unknown error' ),
			];
		}
		$inner = isset( $decoded['EncryptData'] ) ? Helper::decrypt_trade_info( (string) $decoded['EncryptData'] ) : null;
		return [
			'ok'      => true,
			'message' => (string) ( $decoded['Message'] ?? 'OK' ),
			'data'    => $inner ?? [],
		];
	}


	private static function call_encrypted( string $path, array $args ): array {
		$query = http_build_query( $args );
		try {
			$post_data = Aes::encrypt_cbc_hex( $query, Helper::hash_key(), Helper::hash_iv() );
		} catch ( \Throwable $e ) {
			return [
				'ok'      => false,
				'message' => 'AES-256-CBC encrypt failed',
			];
		}

		$resp = wp_remote_post(
			Helper::base_url() . $path,
			[
				'body'    => [
					'MerchantID_' => Helper::merchant_id(),
					'PostData_'   => $post_data,
				],
				'timeout' => 30,
			]
		);
		if ( is_wp_error( $resp ) ) {
			return [
				'ok'      => false,
				'message' => $resp->get_error_message(),
			];
		}
		$body    = wp_remote_retrieve_body( $resp );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			parse_str( $body, $decoded );
		}
		Helper::log( 'API ' . $path, [ 'status' => (string) ( $decoded['Status'] ?? '' ) ] );
		if ( 'SUCCESS' !== ( $decoded['Status'] ?? '' ) ) {
			return [
				'ok'      => false,
				'message' => (string) ( $decoded['Message'] ?? 'Unknown error' ),
			];
		}
		$result = is_array( $decoded['Result'] ?? null ) ? $decoded['Result'] : json_decode( (string) ( $decoded['Result'] ?? '' ), true );
		if ( ! is_array( $result ) ) {
			$result = [];
		}
		return [
			'ok'      => true,
			'message' => (string) ( $decoded['Message'] ?? 'OK' ),
			'data'    => $result,
		];
	}
}
