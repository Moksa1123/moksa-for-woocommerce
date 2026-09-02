<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Api;

use Moksafowo\Http\Request;

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


	/**
	 * 交易歷程（Trade History）—— 這筆交易在 TapPay 端經過哪些狀態變化。
	 *
	 * 注意：這支不是對帳。要拿「TapPay 與銀行請退款後的結果」請用
	 * reconciliation()，官方是兩支不同的 API。
	 */
	public static function trade_history( string $rec_trade_id ): array {
		return self::request(
			Helper::trade_history_url(),
			[
				'partner_key'  => Helper::partner_key(),
				'rec_trade_id' => $rec_trade_id,
			]
		);
	}

	/**
	 * 對帳（Reconciliation）—— 查 TapPay 與銀行請退款後的實際結果。
	 *
	 * 出帳爭議時要看的是這支，不是 query（那個回的是 TapPay 端的交易狀態）。
	 * 官方註明當日資料要 13:00 後才查得到，且只涵蓋 2020/10/16 之後的交易，
	 * 所以查不到不代表出錯。
	 */
	public static function reconciliation( string $rec_trade_id ): array {
		return self::request(
			Helper::reconciliation_url(),
			[
				'partner_key'  => Helper::partner_key(),
				'rec_trade_id' => $rec_trade_id,
			]
		);
	}

	/**
	 * 當日請款（Cap Today）。
	 *
	 * 只有在 pay-by-prime 帶了 `delay_capture_in_days: -1`（關閉自動請款）時才需要 ——
	 * 本外掛不帶該參數，預設 0 就是當日自動請款，所以正常流程用不到這支。
	 * 保留它是為了商家在 TapPay 後台改過設定、或未來要支援「先授權後請款」時可用。
	 */
	public static function cap( string $rec_trade_id ): array {
		return self::request(
			Helper::cap_url(),
			[
				'partner_key'  => Helper::partner_key(),
				'rec_trade_id' => $rec_trade_id,
			]
		);
	}

	private static function request( string $url, array $body ): array {
		$partner_key = Helper::partner_key();
		if ( '' === $partner_key ) {
			return [
				'ok'     => false,
				'status' => -1,
				'msg'    => __( 'The TapPay partner key is not set.', 'moksa-for-woocommerce' ),
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
