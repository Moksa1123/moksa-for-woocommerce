<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay\Api;

defined( 'ABSPATH' ) || exit;

final class PaymentRequest {


	public static function create( array $args ): array {
		$result = Helper::api_post( Helper::payment_url(), $args );
		$data   = $result['data'];
		return [
			'ok'          => $result['ok'],
			'message'     => $result['message'],
			'code'        => $result['code'],
			'order_id'    => (string) ( $data['order_id'] ?? '' ),
			'payment_url' => (string) ( $data['payment_url'] ?? '' ),
			'data'        => $data,
		];
	}


	public static function refund( string $order_id, string $refund_id, int $amount ): array {
		$result = Helper::api_post(
			Helper::refund_url(),
			[
				'order_id'     => $order_id,
				'refund_id'    => $refund_id,
				'trade_amount' => $amount,
			]
		);
		return [
			'ok'      => $result['ok'],
			'message' => $result['message'],
			'code'    => $result['code'],
			'data'    => $result['data'],
		];
	}
}
