<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 外掛設定彙整(唯讀)。回傳商家常問的非敏感設定:哪些金流/物流/發票管道開著、
 * 各模組測試/正式模式、發票開立時機、訂單查號可搜尋欄位、AI 助手是否啟用。
 *
 * 安全:**絕不回傳任何憑證**(merchant_id / hash_key / hash_iv / secret / api_key 等)。
 */
final class PluginSettings {

	const CAP = 'manage_woocommerce';

	/**
	 * 各模組實際用來判斷測試模式的函式。option 名稱與預設值各家不同
	 * （PAYUNi、LINE Pay 名稱不一樣，綠界與 TapPay 沒存過設定時預設是測試模式），不能自己拼。
	 */
	private static function is_sandbox( string $slug ): bool {
		$map = array(
			'ecpay'             => \Moksafowo\Modules\Ecpay\Api\Helper::class,
			'ecpay_shipping'    => \Moksafowo\Modules\EcpayShipping\Api\Helper::class,
			'ecpay_invoice'     => \Moksafowo\Modules\EcpayInvoice\Api\Helper::class,
			'ezpay_invoice'     => \Moksafowo\Modules\EzpayInvoice\Api\Helper::class,
			'amego_invoice'     => \Moksafowo\Modules\AmegoInvoice\Api\Helper::class,
			'newebpay'          => \Moksafowo\Modules\Newebpay\Api\Helper::class,
			'newebpay_shipping' => \Moksafowo\Modules\NewebpayShipping\Api\Helper::class,
			'paynow'            => \Moksafowo\Modules\Paynow\Api\Helper::class,
			'paynow_invoice'    => \Moksafowo\Modules\PaynowInvoice\Api\Helper::class,
			'pchomepay'         => \Moksafowo\Modules\Pchomepay\Api\Helper::class,
			'shopline_payments' => \Moksafowo\Modules\ShoplinePayments\Api\Helper::class,
			'smilepay'          => \Moksafowo\Modules\Smilepay\Api\Helper::class,
			'smilepay_invoice'  => \Moksafowo\Modules\SmilepayInvoice\Api\Helper::class,
			'smilepay_shipping' => \Moksafowo\Modules\SmilepayShipping\Api\Helper::class,
			'tappay'            => \Moksafowo\Modules\Tappay\Api\Helper::class,
		);
		if ( isset( $map[ $slug ] ) && is_callable( array( $map[ $slug ], 'is_sandbox' ) ) ) {
			return (bool) call_user_func( array( $map[ $slug ], 'is_sandbox' ) );
		}
		// PAYUNi 金流與物流共用同一個測試模式開關。
		if ( 'payuni' === $slug || 'payuni_shipping' === $slug ) {
			return \Moksafowo\Modules\Payuni\Credentials::test_mode_enabled();
		}
		if ( 'linepay' === $slug ) {
			return wc_string_to_bool( get_option( 'moksafowo_linepay_sandboxmode_enabled', 'no' ) );
		}
		return 'yes' === get_option( 'moksafowo_' . $slug . '_sandbox_enabled', 'no' );
	}

	/**
	 * @param mixed $input 未使用。
	 * @return array<string,mixed>
	 */
	public static function execute( $input ): array {
		if ( ! current_user_can( self::CAP ) ) {
			return array();
		}

		$channels = ChannelOps::list_channels( array( 'category' => 'all' ) )['channels'] ?? array();
		$rows     = array();
		foreach ( $channels as $c ) {
			$slug   = (string) $c['slug'];
			$rows[] = array(
				'slug'     => $slug,
				'name'     => (string) $c['name'],
				'category' => (string) $c['category'],
				'enabled'  => (bool) $c['enabled'],
				'sandbox'  => self::is_sandbox( $slug ),
			);
		}

		$invoice_timing = array();
		foreach ( array( 'ecpay', 'ezpay', 'smilepay', 'paynow', 'amego' ) as $p ) {
			if ( 'yes' === get_option( 'moksafowo_' . $p . '_invoice_enabled', 'no' ) ) {
				$invoice_timing[ $p ] = (string) get_option( 'moksafowo_' . $p . '_invoice_issue_when', 'paid' );
			}
		}

		$search_fields = array();
		foreach ( array_keys( SearchableKeys::field_defaults() ) as $field ) {
			$search_fields[ $field ] = SearchableKeys::field_on( $field );
		}

		return array(
			'channels'             => $rows,
			'invoice_issue_timing' => $invoice_timing,
			'order_search_fields'  => $search_fields,
			'ai_assistant_enabled' => 'yes' === get_option( 'moksafowo_ai_assistant_enabled', 'no' ),
			'note'                 => __( 'No credentials such as merchant IDs or hash keys are included here.', 'moksa-for-woocommerce' ),
		);
	}
}
