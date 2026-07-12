<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\AmegoInvoice\Api;

defined( 'ABSPATH' ) || exit;


final class Request {


	public static function post( string $path, array $data ): array {
		$invoice_id = Helper::invoice_id();
		if ( '' === $invoice_id ) {
			return [
				'ok'      => false,
				'message' => 'invoice_id 未設定',
				'raw'     => '',
				'data'    => [],
				'code'    => -1,
			];
		}

		$json = (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
		if ( '' === $json ) {
			return [
				'ok'      => false,
				'message' => 'JSON encode failed',
				'raw'     => '',
				'data'    => [],
				'code'    => -1,
			];
		}

		$time = time();
		$sign = Helper::sign( $json, $time );

		$body = [
			'invoice' => $invoice_id,
			'data'    => $json,           // wp_remote_post 會自己 urlencode form fields
			'time'    => (string) $time,
			'sign'    => $sign,
		];

		$resp = wp_remote_post(
			Helper::ENDPOINT . $path,
			[
				'timeout'   => 30,
				'sslverify' => true,
				'headers'   => [ 'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8' ],
				'body'      => $body,
			]
		);

		if ( is_wp_error( $resp ) ) {
			return [
				'ok'      => false,
				'message' => $resp->get_error_message(),
				'raw'     => '',
				'data'    => [],
				'code'    => -1,
			];
		}

		$http = (int) wp_remote_retrieve_response_code( $resp );
		$bdy  = (string) wp_remote_retrieve_body( $resp );

		if ( 200 !== $http ) {
			return [
				'ok'      => false,
				'message' => "HTTP $http",
				'raw'     => $bdy,
				'data'    => [],
				'code'    => $http,
			];
		}

		$json_resp = json_decode( $bdy, true );
		if ( ! is_array( $json_resp ) ) {
			return [
				'ok'      => false,
				'message' => 'Response not JSON: ' . substr( $bdy, 0, 200 ),
				'raw'     => $bdy,
				'data'    => [],
				'code'    => -1,
			];
		}

		$code = (int) ( $json_resp['code'] ?? -1 );
		$msg  = (string) ( $json_resp['msg'] ?? '' );
		if ( 0 !== $code ) {
			return [
				'ok'      => false,
				'message' => $msg !== '' ? $msg : "code $code",
				'raw'     => $bdy,
				'data'    => $json_resp,
				'code'    => $code,
			];
		}

		return [
			'ok'      => true,
			'message' => 'OK',
			'raw'     => $bdy,
			'data'    => $json_resp,
			'code'    => 0,
		];
	}
}
