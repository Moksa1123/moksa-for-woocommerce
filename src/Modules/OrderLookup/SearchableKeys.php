<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * Tier-1 可搜尋號碼 meta，按「產出該號碼的模組」分組。
 *
 * 解耦：只有對應模組已啟用（moksafowo_<slug>_enabled === 'yes'）時，
 * 該組 key 才會被加進訂單搜尋 —— 沒開 ECPay 發票就不會去搜 ECPay 發票號。
 */
final class SearchableKeys {

	/**
	 * @return array<string, string[]> 模組 slug => 該模組的可搜尋 meta key
	 */
	private static function map(): array {
		return [
			// 金流交易序號（商家對帳 / 退款前回查）
			'ecpay'             => [ Keys::ECPAY_TRADE_NO, Keys::ECPAY_MERCHANT_TRADE_NO ],
			'newebpay'          => [ Keys::NEWEBPAY_TRADE_NO, Keys::NEWEBPAY_MERCHANT_ORDER_NO ],
			'payuni'            => [ Keys::PAYUNI_ORDER_NO, Keys::PAYUNI_TRADE_NO, Keys::PAYUNI_EINVOICE_NO ],
			'smilepay'          => [ Keys::SMILEPAY_PAY_SMILEPAY_NO ],
			'linepay'           => [ Keys::LINEPAY_TRANSACTION_ID ],
			'paynow'            => [ Keys::PAYNOW_ORDER_NO ],
			'pchomepay'         => [ Keys::PCHOMEPAY_ORDER_ID ],
			'tappay'            => [ Keys::TAPPAY_REC_TRADE_ID, Keys::TAPPAY_ORDER_NUMBER ],
			'shopline_payments' => [ Keys::SLP_TRADE_ORDER_ID ],

			// 物流託運 / 寄件 / 超商取貨編號（客人問到貨 / 超商核對）
			'ecpay_shipping'    => [ Keys::ECPAY_LOGISTIC_ID, Keys::ECPAY_LOGISTIC_CVS_PAYMENT_NO ],
			'newebpay_shipping' => [ Keys::NEWEBPAY_SHIPPING_LGS_NO ],
			'payuni_shipping'   => [ Keys::PAYUNI_SHIPPING_TRADE_NO, Keys::PAYUNI_SHIPPING_SNO ],
			'smilepay_shipping' => [ Keys::SMILEPAY_SHIPPING_NO, Keys::SMILEPAY_SHIPPING_PAY_NO ],

			// 發票號碼（客人拿發票來問 / 作廢前回查）
			'ecpay_invoice'     => [ Keys::ECPAY_INVOICE_NUMBER ],
			'ezpay_invoice'     => [ Keys::EZPAY_INVOICE_NUMBER ],
			'smilepay_invoice'  => [ Keys::SMILEPAY_INVOICE_NUMBER ],
			'paynow_invoice'    => [ Keys::PAYNOW_INVOICE_NUMBER ],
			'amego_invoice'     => [ Keys::AMEGO_INVOICE_NUMBER ],
		];
	}

	/**
	 * WC 訂單搜尋 meta-key filter 回呼（HPOS 與 CPT 共用同一份）。
	 *
	 * @param mixed $keys WC 既有的搜尋 meta key 陣列。
	 * @return string[]
	 */
	public static function add( $keys ): array {
		$keys = is_array( $keys ) ? $keys : [];
		foreach ( self::map() as $slug => $meta_keys ) {
			if ( 'yes' === get_option( sprintf( 'moksafowo_%s_enabled', $slug ), 'no' ) ) {
				array_push( $keys, ...$meta_keys );
			}
		}
		return $keys;
	}
}
