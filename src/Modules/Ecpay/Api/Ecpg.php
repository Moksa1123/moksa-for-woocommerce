<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Api;

defined( 'ABSPATH' ) || exit;

/**
 * 站內付 2.0 信用卡綁定 API（AES-128-CBC + JSON）。
 *
 * 規格：reference/ecpay-official/ecpg-bindcard-docs/（developers.ecpay.com.tw 35529 起）。
 * Data 先 URLEncode 再 AES 加密（35676），回應的 Data 也一樣解。
 */
final class Ecpg {

	public const SDK_URL_SANDBOX = 'https://ecpg-stage.ecpay.com.tw/Scripts/sdk-1.0.0.js';
	public const SDK_URL_PROD    = 'https://ecpg.ecpay.com.tw/Scripts/sdk-1.0.0.js';

	private const HOST_ECPG_SANDBOX    = 'https://ecpg-stage.ecpay.com.tw';
	private const HOST_ECPG_PROD       = 'https://ecpg.ecpay.com.tw';
	private const HOST_PAYMENT_SANDBOX = 'https://ecpayment-stage.ecpay.com.tw';
	private const HOST_PAYMENT_PROD    = 'https://ecpayment.ecpay.com.tw';

	public static function sdk_url(): string {
		return Helper::is_sandbox() ? self::SDK_URL_SANDBOX : self::SDK_URL_PROD;
	}

	/** JS SDK ECPay.initialize() 的 ServerType（35559）。 */
	public static function sdk_server_type(): string {
		return Helper::is_sandbox() ? 'Stage' : 'Prod';
	}

	/** 取得廠商驗證碼（GetTokenbyBindingCard，35591）。 */
	public static function get_binding_token( array $data ): array {
		return self::post( self::ecpg_host() . '/Merchant/GetTokenbyBindingCard', $data );
	}

	/** 建立綁定信用卡交易（CreateBindCard，35596）。 */
	public static function create_bind_card( string $bind_card_pay_token, string $member_id ): array {
		return self::post(
			self::ecpg_host() . '/Merchant/CreateBindCard',
			[
				'PlatformID'       => '',
				'MerchantID'       => Helper::merchant_id(),
				'BindCardPayToken' => $bind_card_pay_token,
				'MerchantMemberID' => $member_id,
			],
			60
		);
	}

	/** 幕後交易授權（CreatePaymentWithCardID，35630）。官方建議逾時至少 30 秒。 */
	public static function pay_with_card_id( array $data ): array {
		return self::post( self::ecpg_host() . '/Merchant/CreatePaymentWithCardID', $data, 60 );
	}

	/** 刪除綁定信用卡（DeleteMemberBindCard，35608）。 */
	public static function delete_bind_card( string $bind_card_id ): array {
		return self::post(
			self::ecpg_host() . '/Merchant/DeleteMemberBindCard',
			[
				'PlatformID' => '',
				'MerchantID' => Helper::merchant_id(),
				'BindCardID' => $bind_card_id,
			]
		);
	}

	/** 信用卡請退款（Credit/DoAction，35642）：C 關帳、R 退刷、E 取消、N 放棄。 */
	public static function do_action( string $merchant_trade_no, string $trade_no, string $action, int $amount ): array {
		return self::post(
			self::payment_host() . '/1.0.0/Credit/DoAction',
			[
				'PlatformID'      => '',
				'MerchantID'      => Helper::merchant_id(),
				'MerchantTradeNo' => $merchant_trade_no,
				'TradeNo'         => $trade_no,
				'Action'          => $action,
				'TotalAmount'     => $amount,
			]
		);
	}

	/**
	 * 解開綠界送來的外層 JSON（ReturnURL、OrderResultURL 的 ResultData）。
	 * 只有持有 HashKey/HashIV 的雙方解得開，解不開或 MerchantID 不符就當成無效通知。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function decode_envelope( array $envelope ): ?array {
		if ( (string) ( $envelope['MerchantID'] ?? '' ) !== Helper::merchant_id() ) {
			return null;
		}
		$data = self::decrypt( (string) ( $envelope['Data'] ?? '' ) );
		if ( null === $data ) {
			return null;
		}
		return map_deep( $data, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
	}

	/**
	 * @return array{ok:bool,trans_code:int,message:string,data:array<string,mixed>}
	 */
	private static function post( string $url, array $data, int $timeout = 30 ): array {
		$encrypted = self::encrypt( $data );
		if ( null === $encrypted ) {
			return self::fail( __( 'AES encryption failed.', 'moksa-for-woocommerce' ) );
		}

		$response = wp_remote_post(
			$url,
			[
				'timeout'   => $timeout,
				'sslverify' => true,
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => wp_json_encode(
					[
						'MerchantID' => Helper::merchant_id(),
						'RqHeader'   => [ 'Timestamp' => time() ],
						'Data'       => $encrypted,
					]
				),
			]
		);
		if ( is_wp_error( $response ) ) {
			Helper::log( 'ecpg http error: ' . $response->get_error_message(), [ 'url' => $url ] );
			return self::fail( $response->get_error_message(), -1 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			Helper::log(
				'ecpg bad response',
				[
					'url'  => $url,
					'http' => $code,
				]
			);
			/* translators: %d: HTTP response code */
			return self::fail( sprintf( __( 'ECPay returned HTTP %d', 'moksa-for-woocommerce' ), $code ), -1 );
		}

		$trans_code = (int) ( $json['TransCode'] ?? 0 );
		$payload    = 1 === $trans_code ? self::decrypt( (string) ( $json['Data'] ?? '' ) ) : null;
		Helper::log(
			'ecpg response',
			[
				'url'        => $url,
				'trans_code' => $trans_code,
				'rtn_code'   => $payload['RtnCode'] ?? '',
				'rtn_msg'    => $payload['RtnMsg'] ?? ( $json['TransMsg'] ?? '' ),
			]
		);
		if ( null === $payload ) {
			return self::fail( (string) ( $json['TransMsg'] ?? __( 'The ECPay data could not be decoded.', 'moksa-for-woocommerce' ) ), $trans_code );
		}

		return [
			'ok'         => 1 === (int) ( $payload['RtnCode'] ?? 0 ),
			'trans_code' => $trans_code,
			'message'    => (string) ( $payload['RtnMsg'] ?? '' ),
			'data'       => $payload,
		];
	}

	private static function fail( string $message, int $trans_code = 0 ): array {
		return [
			'ok'         => false,
			'trans_code' => $trans_code,
			'message'    => $message,
			'data'       => [],
		];
	}

	private static function encrypt( array $data ): ?string {
		$plain = urlencode( (string) wp_json_encode( $data ) );
		$enc   = openssl_encrypt( $plain, 'aes-128-cbc', Helper::hash_key(), 0, Helper::hash_iv() );
		return false === $enc ? null : $enc;
	}

	/** @return array<string,mixed>|null */
	private static function decrypt( string $cipher ): ?array {
		if ( '' === $cipher ) {
			return null;
		}
		$plain = openssl_decrypt( $cipher, 'aes-128-cbc', Helper::hash_key(), 0, Helper::hash_iv() );
		if ( false === $plain ) {
			return null;
		}
		$data = json_decode( urldecode( $plain ), true );
		return is_array( $data ) ? $data : null;
	}

	private static function ecpg_host(): string {
		return Helper::is_sandbox() ? self::HOST_ECPG_SANDBOX : self::HOST_ECPG_PROD;
	}

	private static function payment_host(): string {
		return Helper::is_sandbox() ? self::HOST_PAYMENT_SANDBOX : self::HOST_PAYMENT_PROD;
	}
}
