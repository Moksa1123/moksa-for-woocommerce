<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay\Api;

use Moksafowo\Modules\Shared\Api\AbstractCredentialHelper;

defined( 'ABSPATH' ) || exit;

final class Helper extends AbstractCredentialHelper {

	// API host（pay-by-prime / refund / query）。
	public const BASE_SANDBOX = 'https://sandbox.tappaysdk.com';
	public const BASE_PROD    = 'https://prod.tappaysdk.com';

	// 前端 tpdirect SDK（hosted-only，不可 vendoring）。
	public const SDK_URL = 'https://js.tappaysdk.com/sdk/tpdirect/v5';

	public const PATH_PAY_BY_PRIME = '/tpc/payment/pay-by-prime';
	public const PATH_REFUND       = '/tpc/transaction/refund';
	public const PATH_QUERY        = '/tpc/transaction/query';
	public const PATH_TRADE_HIST   = '/tpc/transaction/trade-history';
	public const PATH_CAP          = '/tpc/transaction/cap';
	public const PATH_RECONCILE    = '/tpc/transaction/reconciliation';

	public const SANDBOX_APP_ID      = '11327';
	public const SANDBOX_APP_KEY     = 'app_whdEWBH8e8Lzy4N6BysVRRMILYORF6UxXbiOFsICkz0J9j1C0JUlCHv1tVJC';
	public const SANDBOX_PARTNER_KEY = 'partner_6ID1DoDlaPrfHw6HBZsULfTYtDmWs0q0ZZGKMBpp4YICWBxgK97eK3RM';
	public const SANDBOX_MERCHANT_ID = 'GlobalTesting_CTBC';

	protected static function option_prefix(): string {
		return 'moksafowo_tappay';
	}

	protected static function log_source(): string {
		return 'tappay-payment';
	}

	/** 交易紀錄的 record_status 轉成文字（依 TapPay 官方定義），原始代碼放括號。 */
	public static function record_status_label( string $code ): string {
		if ( '' === $code ) {
			return '';
		}
		$label = [
			'-1' => __( 'Error', 'moksa-for-woocommerce' ),
			'0'  => __( 'Authorized, not yet captured', 'moksa-for-woocommerce' ),
			'1'  => __( 'Completed', 'moksa-for-woocommerce' ),
			'2'  => __( 'Partially refunded', 'moksa-for-woocommerce' ),
			'3'  => __( 'Refunded', 'moksa-for-woocommerce' ),
			'4'  => __( 'Awaiting payment', 'moksa-for-woocommerce' ),
			'5'  => __( 'Cancelled', 'moksa-for-woocommerce' ),
		][ $code ] ?? '';
		return '' !== $label ? $label . ' (' . $code . ')' : $code;
	}

	public static function is_sandbox(): bool {
		return 'yes' === get_option( 'moksafowo_tappay_sandbox_enabled', 'yes' );
	}

	private static function sandbox_value( string $option_suffix, string $const_define, string $fallback ): string {
		$opt = trim( (string) get_option( 'moksafowo_tappay_sandbox_' . $option_suffix, '' ) );
		if ( '' !== $opt ) {
			return $opt;
		}
		if ( defined( $const_define ) && '' !== (string) constant( $const_define ) ) {
			return (string) constant( $const_define );
		}
		return $fallback;
	}

	public static function app_id(): string {
		if ( self::is_sandbox() ) {
			return self::sandbox_value( 'app_id', 'MO_TAPPAY_SANDBOX_APP_ID', self::SANDBOX_APP_ID );
		}
		return (string) get_option( 'moksafowo_tappay_app_id', '' );
	}

	public static function app_key(): string {
		if ( self::is_sandbox() ) {
			return self::sandbox_value( 'app_key', 'MO_TAPPAY_SANDBOX_APP_KEY', self::SANDBOX_APP_KEY );
		}
		return (string) get_option( 'moksafowo_tappay_app_key', '' );
	}

	public static function partner_key(): string {
		if ( self::is_sandbox() ) {
			return self::sandbox_value( 'partner_key', 'MO_TAPPAY_SANDBOX_PARTNER_KEY', self::SANDBOX_PARTNER_KEY );
		}
		return (string) get_option( 'moksafowo_tappay_partner_key', '' );
	}

	public static function merchant_id(): string {
		if ( self::is_sandbox() ) {
			return self::sandbox_value( 'merchant_id', 'MO_TAPPAY_SANDBOX_MERCHANT_ID', self::SANDBOX_MERCHANT_ID );
		}
		return (string) get_option( 'moksafowo_tappay_merchant_id', '' );
	}

	/**
	 * 電子錢包、Apple／Google／Samsung Pay 在 TapPay Portal 各有自己的 Merchant ID，
	 * 拿信用卡的去付會被拒（67）。沒填時才退回信用卡那組。
	 */
	public static function gateway_merchant_id( \WC_Payment_Gateway $gateway ): string {
		$key = self::is_sandbox() ? 'tappay_sandbox_merchant_id' : 'tappay_merchant_id';
		$id  = trim( (string) $gateway->get_option( $key, '' ) );
		return '' !== $id ? $id : self::merchant_id();
	}

	public static function gateway_merchant_id_fields(): array {
		return [
			'tappay_merchant_id'         => [
				'title'       => __( 'Merchant ID for this payment method', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Each payment method has its own Merchant ID in the TapPay Portal. Leave blank to use the credit card Merchant ID.', 'moksa-for-woocommerce' ),
				'desc_tip'    => false,
			],
			'tappay_sandbox_merchant_id' => [
				'title'       => __( 'Test Merchant ID for this payment method', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Used in test mode. Leave blank to use the test credit card Merchant ID.', 'moksa-for-woocommerce' ),
				'desc_tip'    => false,
			],
		];
	}

	public static function notify_secret(): string {
		if ( self::is_sandbox() ) {
			$opt = trim( (string) get_option( 'moksafowo_tappay_sandbox_notify_secret', '' ) );
		} else {
			$opt = trim( (string) get_option( 'moksafowo_tappay_notify_secret', '' ) );
		}
		return '' !== $opt ? $opt : self::partner_key();
	}

	public static function three_domain_secure_enabled(): bool {
		return 'yes' === get_option( 'moksafowo_tappay_3ds_enabled', 'yes' );
	}

	public static function sdk_env(): string {
		return self::is_sandbox() ? 'sandbox' : 'production';
	}

	public static function has_credentials(): bool {
		return '' !== self::app_id()
			&& '' !== self::app_key()
			&& '' !== self::partner_key()
			&& '' !== self::merchant_id();
	}

	public static function base_url(): string {
		return self::is_sandbox() ? self::BASE_SANDBOX : self::BASE_PROD;
	}

	public static function pay_by_prime_url(): string {
		return self::base_url() . self::PATH_PAY_BY_PRIME;
	}

	public static function refund_url(): string {
		return self::base_url() . self::PATH_REFUND;
	}

	public static function query_url(): string {
		return self::base_url() . self::PATH_QUERY;
	}

	public static function trade_history_url(): string {
		return self::base_url() . self::PATH_TRADE_HIST;
	}

	public static function cap_url(): string {
		return self::base_url() . self::PATH_CAP;
	}

	public static function reconciliation_url(): string {
		return self::base_url() . self::PATH_RECONCILE;
	}

	public static function build_order_number( \WC_Order $order, bool $retry = false ): string {
		$base = (string) $order->get_order_number();
		if ( $retry ) {
			$base .= 'T' . time();
		}
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', $base ) ?? (string) $order->get_id();
	}

	/**
	 * 3DS 回跳網址上的驗證碼：只有拿到這張訂單付款網址的人才知道，防止拿任意單號換到含 order key 的網址。
	 */
	public static function result_token( \WC_Order $order ): string {
		return substr( hash_hmac( 'sha256', 'tappay-result|' . $order->get_id(), wp_salt( 'auth' ) . $order->get_order_key() ), 0, 24 );
	}

	public static function parse_order_id( string $order_number ): ?int {
		if ( ! preg_match( '/^[#]?(\d+)/', $order_number, $m ) ) {
			return null;
		}
		$id = (int) $m[1];
		return $id > 0 ? $id : null;
	}

	public static function verify_notify_signature( string $raw_body, string $signature ): bool {
		$secret = self::notify_secret();
		if ( '' === $secret || '' === $signature ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $raw_body, $secret );
		// TapPay 簽章為 lowercase hex；大小寫不敏感比對前先 normalize。
		return hash_equals( $expected, strtolower( trim( $signature ) ) );
	}
}
