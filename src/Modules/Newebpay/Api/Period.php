<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Api;

defined( 'ABSPATH' ) || exit;

/**
 * 信用卡定期定額 API（NDNP-1.0.8，reference/newebpay-docs/信用卡定期定額串接技術手冊_NDNP-1.0.8.pdf）。
 *
 * 參數一律以 MerchantID_ + PostData_（AES-256-CBC 後轉十六進位，4.1）送出；
 * 回傳的 Period 用同一組金鑰解開（4.2）。通知沒有 TradeSha，只有持有 HashKey/HashIV 的雙方解得開。
 */
final class Period {

	public const PATH_CREATE       = '/MPG/period';
	public const PATH_ALTER_STATUS = '/MPG/period/AlterStatus';
	public const PATH_QUERY        = '/MPG/period/query';

	public static function create_url(): string {
		return Helper::base_url() . self::PATH_CREATE;
	}

	/**
	 * 建立委託（NPA-B05）的表單欄位，由付款頁自動送出到 create_url()。
	 *
	 * @param array<string,mixed> $args PostData_ 內容（4.3.1）。
	 * @return array{MerchantID_:string,PostData_:string}
	 */
	public static function create_form( array $args ): array {
		return [
			'MerchantID_' => Helper::merchant_id(),
			'PostData_'   => Helper::encrypt_trade_info(
				array_merge(
					[
						'RespondType' => 'JSON',
						'TimeStamp'   => (string) time(),
						'Version'     => '1.5',
					],
					$args
				)
			),
		];
	}

	/** 修改委託狀態（NPA-B051）：suspend 暫停、terminate 終止、restart 啟用。需藍新審核開通（PER10075）。 */
	public static function alter_status( string $mer_order_no, string $period_no, string $alter_type ): array {
		return self::call(
			self::PATH_ALTER_STATUS,
			[
				'RespondType' => 'JSON',
				'Version'     => '1.0',
				'TimeStamp'   => (string) time(),
				'MerOrderNo'  => $mer_order_no,
				'PeriodNo'    => $period_no,
				'AlterType'   => $alter_type,
			]
		);
	}

	/** 委託單查詢（NPA-B053）。 */
	public static function query( string $mer_order_no, string $period_no ): array {
		return self::call(
			self::PATH_QUERY,
			[
				'RespondType' => 'JSON',
				'Version'     => '1.0',
				'TimeStamp'   => (string) time(),
				'MerOrderNo'  => $mer_order_no,
				'PeriodNo'    => $period_no,
			]
		);
	}

	/**
	 * 解開 Period 回傳。官方範例同時出現大寫與小寫鍵（4.6.2 的 status/result），統一成首字大寫。
	 *
	 * @return array{Status:string,Message:string,Result:array<string,mixed>}|null
	 */
	public static function decode( string $hex ): ?array {
		$data = Helper::decrypt_trade_info( trim( $hex ) );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$status = (string) ( $data['Status'] ?? $data['status'] ?? '' );
		if ( '' === $status ) {
			return null;
		}
		$result = $data['Result'] ?? $data['result'] ?? [];
		if ( is_string( $result ) ) {
			$decoded = json_decode( $result, true );
			$result  = is_array( $decoded ) ? $decoded : [];
		}
		$result = map_deep( is_array( $result ) ? $result : [], static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
		return [
			'Status'  => sanitize_text_field( $status ),
			'Message' => sanitize_text_field( (string) ( $data['Message'] ?? $data['message'] ?? '' ) ),
			'Result'  => $result,
		];
	}

	/**
	 * @return array{ok:bool,message:string,data:array<string,mixed>}
	 */
	private static function call( string $path, array $args ): array {
		$response = wp_remote_post(
			Helper::base_url() . $path,
			[
				'timeout'    => 30,
				'sslverify'  => true,
				// 藍新 WAF 會擋 WordPress 預設 User-Agent（回 403）。
				'user-agent' => \Moksafowo\Http\Request::user_agent(),
				'body'       => [
					'MerchantID_' => Helper::merchant_id(),
					'PostData_'   => Helper::encrypt_trade_info( $args ),
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			Helper::log( 'period api http error: ' . $response->get_error_message(), [ 'path' => $path ] );
			return [
				'ok'      => false,
				'message' => $response->get_error_message(),
				'data'    => [],
			];
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = self::decode( self::extract_period( $body ) );
		// 驗證失敗這類錯誤藍新直接回未加密的「Status=PER10006&Message=...」。
		if ( null === $decoded ) {
			parse_str( $body, $plain );
			if ( ! empty( $plain['Status'] ) ) {
				$decoded = [
					'Status'  => sanitize_text_field( (string) $plain['Status'] ),
					'Message' => sanitize_text_field( (string) ( $plain['Message'] ?? '' ) ),
					'Result'  => [],
				];
			}
		}
		Helper::log(
			'period api response',
			[
				'path'   => $path,
				'http'   => (int) wp_remote_retrieve_response_code( $response ),
				'status' => $decoded['Status'] ?? '',
			]
		);
		if ( null === $decoded ) {
			return [
				'ok'      => false,
				'message' => __( 'The response from NewebPay could not be read.', 'moksa-for-woocommerce' ),
				'data'    => [],
			];
		}
		return [
			'ok'      => 'SUCCESS' === $decoded['Status'],
			'message' => '' !== $decoded['Message'] ? $decoded['Message'] : $decoded['Status'],
			'data'    => $decoded['Result'],
		];
	}

	/** 手冊只寫回傳欄位叫 Period／period（AES 密文），沒寫外層格式；JSON、表單字串、純密文都接受。 */
	private static function extract_period( string $body ): string {
		$body = trim( $body );
		$json = json_decode( $body, true );
		if ( is_array( $json ) ) {
			return (string) ( $json['Period'] ?? $json['period'] ?? '' );
		}
		if ( str_contains( $body, '=' ) ) {
			parse_str( $body, $form );
			return (string) ( $form['Period'] ?? $form['period'] ?? '' );
		}
		return ctype_xdigit( $body ) ? $body : '';
	}
}
