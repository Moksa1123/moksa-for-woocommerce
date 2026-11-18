<?php
/**
 * 存在 option 裡的金鑰清單：設定頁用它決定哪些欄位不印明文（SecretFields）。
 * WP 7.2 Secrets API 搬遷（SecretStore）因核心 11-17 撤回 API，移到分支 feature/wp-secrets。
 *
 * @package Moksafowo
 */

declare( strict_types=1 );

namespace Moksafowo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * 只列真的是秘密的值（HashKey/IV、密碼、簽章與 API 金鑰）。商店代號等識別碼不是秘密，留在 option。
 * 新增金流／物流／發票的金鑰時一定要加進來，單元測試會掃 src 比對有沒有漏。
 */
final class SecretMap {

	public const OPTIONS = array(
		// 綠界金流
		'moksafowo_ecpay_hash_key',
		'moksafowo_ecpay_hash_iv',
		'moksafowo_ecpay_sandbox_hash_key',
		'moksafowo_ecpay_sandbox_hash_iv',
		// 綠界物流
		'moksafowo_ecpay_shipping_b2c_hash_key',
		'moksafowo_ecpay_shipping_b2c_hash_iv',
		'moksafowo_ecpay_shipping_b2c_sandbox_hash_key',
		'moksafowo_ecpay_shipping_b2c_sandbox_hash_iv',
		'moksafowo_ecpay_shipping_c2c_hash_key',
		'moksafowo_ecpay_shipping_c2c_hash_iv',
		'moksafowo_ecpay_shipping_c2c_sandbox_hash_key',
		'moksafowo_ecpay_shipping_c2c_sandbox_hash_iv',
		// 綠界發票
		'moksafowo_ecpay_invoice_hash_key',
		'moksafowo_ecpay_invoice_hash_iv',
		'moksafowo_ecpay_invoice_sandbox_hash_key',
		'moksafowo_ecpay_invoice_sandbox_hash_iv',
		// 藍新金流／物流
		'moksafowo_newebpay_hash_key',
		'moksafowo_newebpay_hash_iv',
		'moksafowo_newebpay_sandbox_hash_key',
		'moksafowo_newebpay_sandbox_hash_iv',
		'moksafowo_newebpay_shipping_hash_key',
		'moksafowo_newebpay_shipping_hash_iv',
		'moksafowo_newebpay_shipping_sandbox_hash_key',
		'moksafowo_newebpay_shipping_sandbox_hash_iv',
		// PAYUNi 金流（物流共用）
		'moksafowo_payuni_payment_hashkey',
		'moksafowo_payuni_payment_hashiv',
		'moksafowo_payuni_payment_hashkey_test',
		'moksafowo_payuni_payment_hashiv_test',
		// ezPay 發票
		'moksafowo_ezpay_invoice_hash_key',
		'moksafowo_ezpay_invoice_hash_iv',
		'moksafowo_ezpay_invoice_sandbox_hash_key',
		'moksafowo_ezpay_invoice_sandbox_hash_iv',
		// PayNow 金流／發票
		'moksafowo_paynow_trade_password',
		'moksafowo_paynow_sandbox_trade_password',
		'moksafowo_paynow_invoice_mem_password',
		'moksafowo_paynow_invoice_sandbox_mem_password',
		// 速買配 金流／物流／發票
		'moksafowo_smilepay_verify_key',
		'moksafowo_smilepay_mtmk_send_verify_key',
		'moksafowo_smilepay_shipping_verify_key',
		'moksafowo_smilepay_invoice_verify_key',
		'moksafowo_smilepay_invoice_sandbox_verify_key',
		// 支付連
		'moksafowo_pchomepay_secret',
		'moksafowo_pchomepay_sandbox_secret',
		// LINE Pay
		'moksafowo_linepay_channel_secret',
		'moksafowo_linepay_sandbox_channel_secret',
		// TapPay
		'moksafowo_tappay_partner_key',
		'moksafowo_tappay_sandbox_partner_key',
		'moksafowo_tappay_app_key',
		'moksafowo_tappay_sandbox_app_key',
		'moksafowo_tappay_notify_secret',
		'moksafowo_tappay_sandbox_notify_secret',
		// Shopline Payments
		'moksafowo_shopline_payments_api_key',
		'moksafowo_shopline_payments_sandbox_api_key',
		'moksafowo_shopline_payments_sign_key',
		'moksafowo_shopline_payments_sandbox_sign_key',
		'moksafowo_shopline_payments_client_key',
		'moksafowo_shopline_payments_sandbox_client_key',
		// AMEGO 發票
		'moksafowo_amego_invoice_app_key',
		'moksafowo_amego_invoice_sandbox_app_key',
	);

	public const NAMESPACE_SLUG = 'moksa-for-woocommerce';

	/** Option 名稱 → secret 名稱（moksa-for-woocommerce/ecpay_hash_key）。 */
	public static function secret_name( string $option ): string {
		$key = 0 === strpos( $option, 'moksafowo_' ) ? substr( $option, strlen( 'moksafowo_' ) ) : $option;
		return self::NAMESPACE_SLUG . '/' . $key;
	}

	public static function has( string $option ): bool {
		return in_array( $option, self::OPTIONS, true );
	}
}
