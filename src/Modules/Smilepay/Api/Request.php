<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Smilepay\Api;

use MoksaWeb\Mowc\Http\Request as HttpRequest;

defined( 'ABSPATH' ) || exit;

final class Request {

	
	public static function create_order( array $args ): array {
		$post = array_merge(
			[
				'Dcvc'       => Helper::dcvc(),
				'Rvg2c'      => Helper::rvg2c(),
				'Verify_key' => Helper::verify_key(),
			],
			$args
		);

		Helper::log( 'SPPayment request', [
			'data_id' => (string) ( $args['Data_id'] ?? '' ),
			'pay_zg'  => (string) ( $args['Pay_zg'] ?? '' ),
			'amount'  => (string) ( $args['Amount'] ?? '' ),
		] );

		try {
			$resp = HttpRequest::post( Helper::ENDPOINT_SP_API, $post, [], 'form' );
		} catch ( \RuntimeException $e ) {
			Helper::log( 'SPPayment transport error', [ 'error' => $e->getMessage() ] );
			return [ 'ok' => false, 'status' => 'TRANSPORT', 'message' => $e->getMessage(), 'data' => [] ];
		}

		$raw = $resp->body;
		$xml = @simplexml_load_string( $raw );
		if ( ! is_object( $xml ) || false === strpos( $raw, '<SmilePay>' ) ) {
			Helper::log( 'SPPayment parse fail', [ 'body' => substr( $raw, 0, 300 ) ] );
			return [
				'ok'      => false,
				'status'  => 'PARSE_FAIL',
				'message' => __( 'SmilePay 回傳格式無法解析（連線異常）', 'mo-ectools' ),
				'data'    => [],
			];
		}

		$status = (string) ( $xml->Status ?? '' );
		$desc   = (string) ( $xml->Desc ?? '' );

		$data = [];
		foreach ( $xml->children() as $key => $val ) {
			$data[ (string) $key ] = (string) $val;
		}

		Helper::log( 'SPPayment response', [ 'status' => $status, 'desc' => $desc ] );

		return [
			'ok'      => '1' === $status,
			'status'  => $status,
			'message' => '1' === $status ? 'OK' : trim( $status . ': ' . $desc ),
			'data'    => $data,
		];
	}

	public static function build_mtmk_url( array $args ): string {
		$query = array_merge(
			[
				'Dcvc'       => Helper::dcvc(),
				'Rvg2c'      => Helper::rvg2c(),
				'Verify_key' => Helper::verify_key(),
			],
			$args
		);

		Helper::log( 'mtmk redirect', [
			'data_id' => (string) ( $args['Data_id'] ?? '' ),
			'pay_zg'  => (string) ( $args['Pay_zg'] ?? '' ),
			'amount'  => (string) ( $args['Amount'] ?? '' ),
			'stage'   => (string) ( $args['Stage'] ?? '' ),
		] );

		return Helper::ENDPOINT_MTMK . '?' . http_build_query( $query );
	}
}
