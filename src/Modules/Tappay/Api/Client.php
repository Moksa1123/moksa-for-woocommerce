<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Tappay\Api;

use MoksaWeb\Mowc\Http\Request;

defined( 'ABSPATH' ) || exit;

final class Client {

	private const TIMEOUT = 30;


	public static function pay_by_prime( array $payload ): array {
		$result = self::request( Helper::pay_by_prime_url(), $payload );
		$data   = $result['data'];

		$payment_url = '';
		if ( isset( $data['payment_url'] ) && is_string( $data['payment_url'] ) ) {
			$payment_url = (string) $data['payment_url'];
		}

		$needs_3ds = $result['ok'] && '' !== $payment_url;

		return [
			'ok'          => $result['ok'],
			'status'      => $result['status'],
			'msg'         => $result['msg'],
			'needs_3ds'   => $needs_3ds,
			'payment_url' => $payment_url,
			'data'        => $data,
		];
	}


	public static function refund( string $rec_trade_id, int $amount ): array {
		return self::request(
			Helper::refund_url(),
			[
				'partner_key'  => Helper::partner_key(),
				'rec_trade_id' => $rec_trade_id,
				'amount'       => $amount,
			]
		);
	}


	public static function query_by_rec_trade_id( string $rec_trade_id ): array {
		return self::request(
			Helper::query_url(),
			[
				'partner_key' => Helper::partner_key(),
				'filters'     => [ 'rec_trade_id' => $rec_trade_id ],
			]
		);
	}

	public static function query_by_order_number( string $order_number ): array {
		return self::request(
			Helper::query_url(),
			[
				'partner_key' => Helper::partner_key(),
				'filters'     => [ 'order_number' => $order_number ],
			]
		);
	}


	private static function request( string $url, array $body ): array {
		$partner_key = Helper::partner_key();
		if ( '' === $partner_key ) {
			return [
				'ok'     => false,
				'status' => -1,
				'msg'    => __( 'TapPay Partner Key 未設定。', 'mo-ectools' ),
				'data'   => [],
			];
		}

		try {
			$resp = Request::post(
				$url,
				$body,
				[ 'x-api-key' => $partner_key ],
				'json',
				self::TIMEOUT
			);
		} catch ( \RuntimeException $e ) {
			Helper::log(
				'api transport error',
				[
					'url'   => $url,
					'error' => $e->getMessage(),
				]
			);
			return [
				'ok'     => false,
				'status' => -1,
				'msg'    => $e->getMessage(),
				'data'   => [],
			];
		}

		$decoded = $resp->json();
		$status  = isset( $decoded['status'] ) ? (int) $decoded['status'] : -1;
		$msg     = (string) ( $decoded['msg'] ?? '' );
		$ok      = $resp->ok() && 0 === $status;

		Helper::log(
			'api response',
			[
				'url'    => $url,
				'http'   => $resp->status,
				'status' => $status,
				'msg'    => $msg,
			]
		);

		return [
			'ok'     => $ok,
			'status' => $status,
			'msg'    => $msg,
			'data'   => $decoded,
		];
	}
}
