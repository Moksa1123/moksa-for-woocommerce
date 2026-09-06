<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Email;

use Moksafowo\Modules\Shared\Frontend\PaymentInfoBox;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 把台灣特有的欄位註冊成 WooCommerce 區塊信件編輯器的個人化標籤。
 *
 * 為什麼值得做：區塊編輯器以前，商家只能接受我們寫死的那句話加一張表；
 * 有了標籤，他們可以自己寫「你的包裹已送達 [超商店名]，請於 [繳費期限] 前取件」。
 *
 * ⚠️ 一律走跨供應商的正規化層，不要暴露 ECPAY_* / NEWEBPAY_* 這類各家自己的
 * meta key。否則標籤會變成「綠界虛擬帳號」「藍新虛擬帳號」兩個不同的東西，
 * 商家換金流商信件就壞了。繳費資訊走 PaymentInfoBox::rows() 的 key，
 * 物流與發票走 Order\Meta\Keys 裡本來就共用的那幾個。
 */
final class PersonalizationTags {

	/** 對齊 WooCommerce Internal\EmailEditor\Integration::EMAIL_POST_TYPE。
	 * 不直接引用那個類別 —— 它在 Internal 命名空間下，隨時可能改。 */
	private const EMAIL_POST_TYPE = 'woo_email';

	public static function init(): void {
		add_filter( 'woocommerce_email_editor_register_personalization_tags', [ __CLASS__, 'register' ] );
	}

	/**
	 * @param object $registry WooCommerce 的 Personalization_Tags_Registry。
	 * @return object
	 */
	public static function register( $registry ) {
		$tag_class = '\Automattic\WooCommerce\EmailEditor\Engine\PersonalizationTags\Personalization_Tag';
		if ( ! class_exists( $tag_class ) || ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return $registry;
		}

		// 分類名稱刻意用完整字詞，不要單一個 Shipping / Payment ——
		// 那些 msgid 在別處已有翻譯（Shipping 被翻成「運費」），拿來當標籤分類會很怪。
		$shipping = __( 'Shipping and pickup', 'moksa-for-woocommerce' );
		$payment  = __( 'Payment details', 'moksa-for-woocommerce' );
		$invoice  = __( 'E-invoice', 'moksa-for-woocommerce' );

		// 物流 / 發票：訂單 meta 本來就是跨供應商共用的。
		$meta_tags = [
			[ __( 'Pickup store name', 'moksa-for-woocommerce' ), 'moksafowo/cvs-store-name', $shipping, Keys::SHIPPING_CVS_STORE_NAME ],
			[ __( 'Pickup store ID', 'moksa-for-woocommerce' ), 'moksafowo/cvs-store-id', $shipping, Keys::SHIPPING_CVS_STORE_ID ],
			[ __( 'Pickup store address', 'moksa-for-woocommerce' ), 'moksafowo/cvs-store-address', $shipping, Keys::SHIPPING_CVS_STORE_ADDRESS ],
			[ __( 'Tracking number', 'moksa-for-woocommerce' ), 'moksafowo/tracking-number', $shipping, Keys::SHIPPING_LABEL_NUMBER ],
			[ __( 'Shipping provider', 'moksa-for-woocommerce' ), 'moksafowo/shipping-provider', $shipping, Keys::SHIPPING_LABEL_PROVIDER ],
			[ __( 'Invoice number', 'moksa-for-woocommerce' ), 'moksafowo/invoice-number', $invoice, Keys::ECPAY_INVOICE_NUMBER ],
		];
		foreach ( $meta_tags as [ $label, $token, $category, $meta_key ] ) {
			$registry->register(
				new $tag_class(
					$label,
					$token,
					$category,
					static function ( array $context ) use ( $meta_key ): string {
						$order = $context['order'] ?? null;
						return $order instanceof \WC_Order ? (string) $order->get_meta( $meta_key ) : '';
					},
					[],
					null,
					[ self::EMAIL_POST_TYPE ]
				)
			);
		}

		// 繳費資訊：走 PaymentInfoBox 的正規化列，五家金流共用同一組 key。
		$payment_tags = [
			[ __( 'Bank code', 'moksa-for-woocommerce' ), 'moksafowo/atm-bank', 'atm_bank' ],
			[ __( 'ATM virtual account', 'moksa-for-woocommerce' ), 'moksafowo/atm-account', 'atm_account' ],
			[ __( 'Convenience store payment code', 'moksa-for-woocommerce' ), 'moksafowo/cvs-payment-code', 'cvs_code' ],
			[ __( 'Barcode segment 1', 'moksa-for-woocommerce' ), 'moksafowo/barcode-1', 'barcode_1' ],
			[ __( 'Barcode segment 2', 'moksa-for-woocommerce' ), 'moksafowo/barcode-2', 'barcode_2' ],
			[ __( 'Barcode segment 3', 'moksa-for-woocommerce' ), 'moksafowo/barcode-3', 'barcode_3' ],
			[ __( 'Payment deadline', 'moksa-for-woocommerce' ), 'moksafowo/payment-deadline', 'deadline' ],
		];
		foreach ( $payment_tags as [ $label, $token, $row_key ] ) {
			$registry->register(
				new $tag_class(
					$label,
					$token,
					$payment,
					static function ( array $context ) use ( $row_key ): string {
						$order = $context['order'] ?? null;
						if ( ! $order instanceof \WC_Order ) {
							return '';
						}
						foreach ( PaymentInfoBox::rows( $order ) as $row ) {
							if ( $row_key === ( $row['key'] ?? '' ) ) {
								return (string) ( $row['value'] ?? '' );
							}
						}
						return '';
					},
					[],
					null,
					[ self::EMAIL_POST_TYPE ]
				)
			);
		}

		return $registry;
	}
}
