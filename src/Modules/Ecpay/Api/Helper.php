<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Ecpay\Api;

use MoksaWeb\Mowc\Modules\Shared\Api\AbstractCredentialHelper;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	public const SANDBOX_MERCHANT_ID = '3002607';
	public const SANDBOX_HASH_KEY    = 'pwFHCqoQZGmho4w6';
	public const SANDBOX_HASH_IV     = 'EkRm7iFT261dpevs';

	public const ENDPOINT_SANDBOX_AIO    = 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5';
	public const ENDPOINT_PROD_AIO       = 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5';
	public const ENDPOINT_SANDBOX_ACTION = 'https://payment-stage.ecpay.com.tw/CreditDetail/DoAction';
	public const ENDPOINT_PROD_ACTION    = 'https://payment.ecpay.com.tw/CreditDetail/DoAction';
	public const ENDPOINT_SANDBOX_QUERY  = 'https://ecpayment-stage.ecpay.com.tw/1.0.0/CreditDetail/QueryTrade';
	public const ENDPOINT_PROD_QUERY     = 'https://ecpayment.ecpay.com.tw/1.0.0/CreditDetail/QueryTrade';

	protected static function option_prefix(): string {
		return 'moksafowo_ecpay';
	}

	protected static function log_source(): string {
		return 'ecpay-payment';
	}

	public static function merchant_id(): string {
		if ( self::is_sandbox() ) {
			$v = (string) get_option( 'moksafowo_ecpay_sandbox_merchant_id', '' );
			return '' !== $v ? $v : self::SANDBOX_MERCHANT_ID;
		}
		return (string) get_option( 'moksafowo_ecpay_merchant_id', '' );
	}

	public static function hash_key(): string {
		if ( self::is_sandbox() ) {
			$v = (string) get_option( 'moksafowo_ecpay_sandbox_hash_key', '' );
			return '' !== $v ? $v : self::SANDBOX_HASH_KEY;
		}
		return (string) get_option( 'moksafowo_ecpay_hash_key', '' );
	}

	public static function hash_iv(): string {
		if ( self::is_sandbox() ) {
			$v = (string) get_option( 'moksafowo_ecpay_sandbox_hash_iv', '' );
			return '' !== $v ? $v : self::SANDBOX_HASH_IV;
		}
		return (string) get_option( 'moksafowo_ecpay_hash_iv', '' );
	}

	public static function aio_endpoint(): string {
		return self::is_sandbox() ? self::ENDPOINT_SANDBOX_AIO : self::ENDPOINT_PROD_AIO;
	}

	public static function action_endpoint(): string {
		return self::is_sandbox() ? self::ENDPOINT_SANDBOX_ACTION : self::ENDPOINT_PROD_ACTION;
	}

	public static function query_endpoint(): string {
		return self::is_sandbox() ? self::ENDPOINT_SANDBOX_QUERY : self::ENDPOINT_PROD_QUERY;
	}

	public static function query_credit_trade( \WC_Order $order ) {
		$mtn = (string) $order->get_meta( Keys::ECPAY_MERCHANT_TRADE_NO );
		if ( '' === $mtn ) {
			return new \WP_Error( 'moksafowo_ecpay_query_no_mtn', __( '找不到綠界 MerchantTradeNo。', 'mo-ectools' ) );
		}

		$inner = wp_json_encode( [
			'MerchantID'      => self::merchant_id(),
			'MerchantTradeNo' => $mtn,
		] );

		// V2 API 的特殊 urlencode：urlencode 後把 - _ . * ! ( ) 還原成原字
		$enc_url  = self::v2_urlencode( (string) $inner );
		$enc_data = openssl_encrypt( $enc_url, 'aes-128-cbc', self::hash_key(), 0, self::hash_iv() );
		if ( false === $enc_data ) {
			return new \WP_Error( 'moksafowo_ecpay_query_encrypt', __( 'AES 加密失敗。', 'mo-ectools' ) );
		}

		$body = [
			'MerchantID' => self::merchant_id(),
			'RqHeader'   => [ 'Timestamp' => time() ],
			'Data'       => $enc_data,
		];

		self::log( 'query_credit_trade request', [ 'order_id' => $order->get_id(), 'mtn' => $mtn ] );

		$response = wp_remote_post(
			self::query_endpoint(),
			[
				'timeout'   => 30,
				'sslverify' => true,
				'headers'   => [ 'Content-Type' => 'application/json' ],
				'body'      => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			self::log( 'query_credit_trade http error: ' . $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP response code */
			return new \WP_Error( 'moksafowo_ecpay_query_http', sprintf( __( '綠界回傳 HTTP %d', 'mo-ectools' ), $code ) );
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'moksafowo_ecpay_query_parse', __( '綠界回傳格式無法解析。', 'mo-ectools' ) );
		}

		$trans_code = (int) ( $json['TransCode'] ?? 0 );
		if ( 1 !== $trans_code ) {
			return new \WP_Error(
				'moksafowo_ecpay_query_failed',
				/* translators: %s: ECPay TransMsg */
				sprintf( __( '綠界查詢失敗：%s', 'mo-ectools' ), $json['TransMsg'] ?? '' )
			);
		}

		$plain_url = openssl_decrypt( (string) ( $json['Data'] ?? '' ), 'aes-128-cbc', self::hash_key(), 0, self::hash_iv() );
		if ( false === $plain_url ) {
			return new \WP_Error( 'moksafowo_ecpay_query_decrypt', __( 'AES 解密失敗。', 'mo-ectools' ) );
		}

		$plain  = urldecode( $plain_url );
		$result = json_decode( $plain, true );
		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'moksafowo_ecpay_query_inner_parse', __( '綠界 Data 解碼失敗。', 'mo-ectools' ) );
		}

		self::log( 'query_credit_trade response', [
			'order_id' => $order->get_id(),
			'status'   => $result['RtnValue']['Status'] ?? '',
			'amount'   => $result['RtnValue']['Amount'] ?? '',
			'cls_amt'  => $result['RtnValue']['ClsAmt'] ?? '',
		] );

		return $result;
	}

	private static function v2_urlencode( string $s ): string {
		return str_replace(
			[ '%2D', '%2d', '%5F', '%5f', '%2E', '%2e', '%2A', '%2a', '%21', '%28', '%29' ],
			[ '-', '-', '_', '_', '.', '.', '*', '*', '!', '(', ')' ],
			urlencode( $s )
		);
	}

	public static function credit_action( \WC_Order $order, string $action, int $amount ) {
		$trade_no          = (string) $order->get_meta( Keys::ECPAY_TRADE_NO );
		$merchant_trade_no = (string) $order->get_meta( Keys::ECPAY_MERCHANT_TRADE_NO );
		if ( '' === $trade_no || '' === $merchant_trade_no ) {
			$trade_no          = '' !== $trade_no ? $trade_no : (string) $order->get_transaction_id();
			$merchant_trade_no = '' !== $merchant_trade_no ? $merchant_trade_no : '';
		}
		if ( '' === $trade_no || '' === $merchant_trade_no ) {
			return new \WP_Error(
				'moksafowo_ecpay_credit_action_missing_meta',
				__( '找不到綠界交易編號，無法退款。請至綠界後台手動處理。', 'mo-ectools' )
			);
		}

		$args = [
			'MerchantID'      => self::merchant_id(),
			'MerchantTradeNo' => $merchant_trade_no,
			'TradeNo'         => $trade_no,
			'Action'          => $action,
			'TotalAmount'     => $amount,
		];
		$args['CheckMacValue'] = self::generate_check_mac_value( $args );

		self::log( 'credit_action request', [ 'order_id' => $order->get_id(), 'action' => $action, 'amount' => $amount ] );

		$response = wp_remote_post(
			self::action_endpoint(),
			[
				'timeout'   => 30,
				'sslverify' => true,
				'body'      => $args,
			]
		);

		if ( is_wp_error( $response ) ) {
			self::log( 'credit_action http error: ' . $response->get_error_message(), [ 'order_id' => $order->get_id() ] );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			self::log( 'credit_action HTTP ' . $code, [ 'order_id' => $order->get_id() ] );
			return new \WP_Error(
				'moksafowo_ecpay_credit_action_http',
				/* translators: %d: HTTP status code */
				sprintf( __( '綠界回傳 HTTP %d，請稍後再試。', 'mo-ectools' ), $code )
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		parse_str( $body, $result );
		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'moksafowo_ecpay_credit_action_parse', __( '綠界回傳格式無法解析。', 'mo-ectools' ) );
		}

		self::log( 'credit_action response', [ 'order_id' => $order->get_id(), 'rtn_code' => $result['RtnCode'] ?? '', 'rtn_msg' => $result['RtnMsg'] ?? '' ] );

		return array_map( 'strval', $result );
	}

	public static function generate_merchant_trade_no( int $order_id ): string {
		$prefix = (string) get_option( 'moksafowo_ecpay_order_prefix', '' );
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ?? '';
		$prefix = substr( $prefix, 0, 5 );
		$random = bin2hex( random_bytes( 3 ) );
		$base   = $prefix . str_pad( (string) $order_id, 6, '0', STR_PAD_LEFT ) . 'R' . $random;
		return substr( $base, 0, 20 );
	}

	public static function parse_order_id_from_merchant_trade_no( string $merchant_trade_no ): ?int {
		$prefix = (string) get_option( 'moksafowo_ecpay_order_prefix', '' );
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ?? '';
		$prefix = substr( $prefix, 0, 5 );

		// Strip prefix if it was set; otherwise treat the whole string as the rest.
		$rest = ( '' !== $prefix && str_starts_with( $merchant_trade_no, $prefix ) )
			? substr( $merchant_trade_no, strlen( $prefix ) )
			: $merchant_trade_no;
		if ( preg_match( '/^(\d{6})R/', $rest, $m ) ) {
			$order_id = (int) ltrim( $m[1], '0' );
			if ( $order_id > 0 && wc_get_order( $order_id ) ) {
				return $order_id;
			}
		}

		// Fallback — 直接用 meta key 查
		$found = wc_get_orders( [
			'limit'      => 1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
			'meta_key'   => Keys::ECPAY_MERCHANT_TRADE_NO,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Order meta lookup required for IPN/order resolution; HPOS table has meta_key index.
			'meta_value' => $merchant_trade_no,
		] );
		if ( ! empty( $found ) ) {
			$order = $found[0];
			return $order instanceof \WC_Order ? $order->get_id() : null;
		}
		return null;
	}

	public static function generate_check_mac_value( array $data ): string {
		$svc = new \Ecpay\Sdk\Services\CheckMacValueService(
			self::hash_key(),
			self::hash_iv(),
			\Ecpay\Sdk\Services\CheckMacValueService::METHOD_SHA256
		);
		return $svc->generate( $data );
	}

	public static function verify_check_mac_value( array $posted ): bool {
		if ( empty( $posted['CheckMacValue'] ) ) {
			return false;
		}
		$signed = $posted;
		unset( $signed['CheckMacValue'] );
		$expected = self::generate_check_mac_value( $signed );
		return hash_equals( $expected, (string) $posted['CheckMacValue'] );
	}

	// is_sandbox / log_enabled / log inherited from AbstractCredentialHelper
}
