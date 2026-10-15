<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Api;

use Moksafowo\Http\Request as HttpRequest;

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

		Helper::log(
			'SPPayment request',
			[
				'data_id' => (string) ( $args['Data_id'] ?? '' ),
				'pay_zg'  => (string) ( $args['Pay_zg'] ?? '' ),
				'amount'  => (string) ( $args['Amount'] ?? '' ),
			]
		);

		try {
			$resp = HttpRequest::post( Helper::ENDPOINT_SP_API, $post, [], 'form' );
		} catch ( \RuntimeException $e ) {
			Helper::log( 'SPPayment transport error', [ 'error' => $e->getMessage() ] );
			return [
				'ok'      => false,
				'status'  => 'TRANSPORT',
				'message' => $e->getMessage(),
				'data'    => [],
			];
		}

		$raw = $resp->body;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- remote XML response — malformed input returns false, validated below; @ suppresses the warning so the simplexml return value can be validated explicitly.
		$xml = @simplexml_load_string( $raw );
		if ( ! is_object( $xml ) || false === strpos( $raw, '<SmilePay>' ) ) {
			Helper::log( 'SPPayment parse fail', [ 'body' => substr( $raw, 0, 300 ) ] );
			return [
				'ok'      => false,
				'status'  => 'PARSE_FAIL',
				'message' => __( 'The response from SmilePay could not be parsed, which usually means a connection problem', 'moksa-for-woocommerce' ),
				'data'    => [],
			];
		}

		$status = (string) ( $xml->Status ?? '' );
		$desc   = (string) ( $xml->Desc ?? '' );

		$data = [];
		foreach ( $xml->children() as $key => $val ) {
			$data[ (string) $key ] = (string) $val;
		}

		Helper::log(
			'SPPayment response',
			[
				'status' => $status,
				'desc'   => $desc,
			]
		);

		return [
			'ok'      => '1' === $status,
			'status'  => $status,
			'message' => '1' === $status ? 'OK' : trim( $status . ': ' . $desc ),
			'data'    => $data,
		];
	}

	public static function build_mtmk_url( array $args ): string {
		$base = [
			'Dcvc'  => Helper::dcvc(),
			'Rvg2c' => Helper::rvg2c(),
		];
		// 這是顧客瀏覽器看得到的轉址網址。速買配客服回覆 mtmk 目前不需要 Verify_key（RY Tools 也不帶），
		// 不放進網址；日後速買配要求時用 filter 打開。
		if ( apply_filters( 'moksafowo_smilepay_mtmk_send_verify_key', false ) ) {
			$base['Verify_key'] = Helper::verify_key();
		}
		$query = array_merge( $base, $args );

		Helper::log(
			'mtmk redirect',
			[
				'data_id' => (string) ( $args['Data_id'] ?? '' ),
				'pay_zg'  => (string) ( $args['Pay_zg'] ?? '' ),
				'amount'  => (string) ( $args['Amount'] ?? '' ),
				'stage'   => (string) ( $args['Stage'] ?? '' ),
			]
		);

		return Helper::ENDPOINT_MTMK . '?' . http_build_query( $query );
	}
}
