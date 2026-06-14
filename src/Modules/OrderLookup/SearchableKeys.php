<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 可搜尋號碼 meta 的權威定義 —— 按「號碼類型(field)+ 產出模組(slug)」分組。
 *
 * 每個號碼類型是獨立開關 moksafowo_order_lookup_field_<field>(進階設定逐項勾選)。
 * 解耦:某組要被搜,需「該號碼類型有勾」且「產出模組已啟用」兩者皆成立。
 */
final class SearchableKeys {

	const FIELD_OPTION_PREFIX = 'moksafowo_order_lookup_field_';

	/**
	 * 各號碼類型的預設開關。常用三類預設開,其餘預設關。
	 *
	 * @return array<string, string>
	 */
	public static function field_defaults(): array {
		return [
			'invoice'  => 'yes',
			'shipping' => 'yes',
			'payment'  => 'yes',
			'ubn'      => 'no',
			'atm'      => 'no',
			'cvs'      => 'no',
			'card'     => 'no',
			'tcat'     => 'no',
		];
	}

	/**
	 * @return array<int, array{field:string, label:string, slug:string, keys:string[]}>
	 */
	private static function groups(): array {
		return [
			// 金流交易序號
			[ 'field' => 'payment', 'label' => __( '金流交易序號', 'mo-ectools' ), 'slug' => 'ecpay', 'keys' => [ Keys::ECPAY_TRADE_NO, Keys::ECPAY_MERCHANT_TRADE_NO ] ],
			[ 'field' => 'payment', 'label' => __( '金流交易序號', 'mo-ectools' ), 'slug' => 'newebpay', 'keys' => [ Keys::NEWEBPAY_TRADE_NO, Keys::NEWEBPAY_MERCHANT_ORDER_NO ] ],
			[ 'field' => 'payment', 'label' => __( '金流交易序號', 'mo-ectools' ), 'slug' => 'payuni', 'keys' => [ Keys::PAYUNI_ORDER_NO, Keys::PAYUNI_TRADE_NO ] ],
			[ 'field' => 'payment', 'label' => __( '金流交易序號', 'mo-ectools' ), 'slug' => 'smilepay', 'keys' => [ Keys::SMILEPAY_PAY_SMILEPAY_NO ] ],
			[ 'field' => 'payment', 'label' => __( '金流交易序號', 'mo-ectools' ), 'slug' => 'linepay', 'keys' => [ Keys::LINEPAY_TRANSACTION_ID ] ],
			[ 'field' => 'payment', 'label' => __( '金流交易序號', 'mo-ectools' ), 'slug' => 'paynow', 'keys' => [ Keys::PAYNOW_ORDER_NO ] ],
			[ 'field' => 'payment', 'label' => __( '金流交易序號', 'mo-ectools' ), 'slug' => 'pchomepay', 'keys' => [ Keys::PCHOMEPAY_ORDER_ID ] ],
			[ 'field' => 'payment', 'label' => __( '金流交易序號', 'mo-ectools' ), 'slug' => 'tappay', 'keys' => [ Keys::TAPPAY_REC_TRADE_ID, Keys::TAPPAY_ORDER_NUMBER ] ],
			[ 'field' => 'payment', 'label' => __( '金流交易序號', 'mo-ectools' ), 'slug' => 'shopline_payments', 'keys' => [ Keys::SLP_TRADE_ORDER_ID ] ],

			// 物流單號
			[ 'field' => 'shipping', 'label' => __( '物流單號', 'mo-ectools' ), 'slug' => 'ecpay_shipping', 'keys' => [ Keys::ECPAY_LOGISTIC_ID, Keys::ECPAY_LOGISTIC_CVS_PAYMENT_NO ] ],
			[ 'field' => 'shipping', 'label' => __( '物流單號', 'mo-ectools' ), 'slug' => 'newebpay_shipping', 'keys' => [ Keys::NEWEBPAY_SHIPPING_LGS_NO ] ],
			[ 'field' => 'shipping', 'label' => __( '物流單號', 'mo-ectools' ), 'slug' => 'payuni_shipping', 'keys' => [ Keys::PAYUNI_SHIPPING_TRADE_NO, Keys::PAYUNI_SHIPPING_SNO ] ],
			[ 'field' => 'shipping', 'label' => __( '物流單號', 'mo-ectools' ), 'slug' => 'smilepay_shipping', 'keys' => [ Keys::SMILEPAY_SHIPPING_NO, Keys::SMILEPAY_SHIPPING_PAY_NO ] ],

			// 發票號碼
			[ 'field' => 'invoice', 'label' => __( '發票號碼', 'mo-ectools' ), 'slug' => 'ecpay_invoice', 'keys' => [ Keys::ECPAY_INVOICE_NUMBER ] ],
			[ 'field' => 'invoice', 'label' => __( '發票號碼', 'mo-ectools' ), 'slug' => 'ezpay_invoice', 'keys' => [ Keys::EZPAY_INVOICE_NUMBER ] ],
			[ 'field' => 'invoice', 'label' => __( '發票號碼', 'mo-ectools' ), 'slug' => 'smilepay_invoice', 'keys' => [ Keys::SMILEPAY_INVOICE_NUMBER ] ],
			[ 'field' => 'invoice', 'label' => __( '發票號碼', 'mo-ectools' ), 'slug' => 'paynow_invoice', 'keys' => [ Keys::PAYNOW_INVOICE_NUMBER ] ],
			[ 'field' => 'invoice', 'label' => __( '發票號碼', 'mo-ectools' ), 'slug' => 'amego_invoice', 'keys' => [ Keys::AMEGO_INVOICE_NUMBER ] ],
			[ 'field' => 'invoice', 'label' => __( '發票號碼', 'mo-ectools' ), 'slug' => 'payuni', 'keys' => [ Keys::PAYUNI_EINVOICE_NO ] ],

			// 統一編號
			[ 'field' => 'ubn', 'label' => __( '統一編號', 'mo-ectools' ), 'slug' => 'ecpay_invoice', 'keys' => [ Keys::INVOICE_BUYER_UBN ] ],

			// ATM 虛擬帳號
			[ 'field' => 'atm', 'label' => __( 'ATM 虛擬帳號', 'mo-ectools' ), 'slug' => 'ecpay', 'keys' => [ Keys::ECPAY_ATM_V_ACCOUNT ] ],
			[ 'field' => 'atm', 'label' => __( 'ATM 虛擬帳號', 'mo-ectools' ), 'slug' => 'newebpay', 'keys' => [ Keys::NEWEBPAY_ATM_CODE_NO ] ],
			[ 'field' => 'atm', 'label' => __( 'ATM 虛擬帳號', 'mo-ectools' ), 'slug' => 'smilepay', 'keys' => [ Keys::SMILEPAY_PAY_ATM_NO ] ],
			[ 'field' => 'atm', 'label' => __( 'ATM 虛擬帳號', 'mo-ectools' ), 'slug' => 'payuni', 'keys' => [ Keys::PAYUNI_ATM_PAYNO ] ],

			// 超商繳費代碼
			[ 'field' => 'cvs', 'label' => __( '超商繳費代碼', 'mo-ectools' ), 'slug' => 'ecpay', 'keys' => [ Keys::ECPAY_CVS_PAYMENT_NO ] ],
			[ 'field' => 'cvs', 'label' => __( '超商繳費代碼', 'mo-ectools' ), 'slug' => 'newebpay', 'keys' => [ Keys::NEWEBPAY_CVS_CODE_NO ] ],

			// 卡末四碼
			[ 'field' => 'card', 'label' => __( '卡末四碼', 'mo-ectools' ), 'slug' => 'ecpay', 'keys' => [ Keys::ECPAY_CARD_LAST4 ] ],
			[ 'field' => 'card', 'label' => __( '卡末四碼', 'mo-ectools' ), 'slug' => 'tappay', 'keys' => [ Keys::TAPPAY_CARD_LAST4 ] ],

			// 黑貓追蹤號
			[ 'field' => 'tcat', 'label' => __( '黑貓追蹤號', 'mo-ectools' ), 'slug' => 'smilepay_shipping', 'keys' => [ Keys::SMILEPAY_SHIPPING_TRACK_NO ] ],
		];
	}

	public static function field_on( string $field ): bool {
		$defaults = self::field_defaults();
		$default  = $defaults[ $field ] ?? 'no';
		return 'yes' === get_option( self::FIELD_OPTION_PREFIX . $field, $default );
	}

	private static function group_active( array $group ): bool {
		return self::field_on( $group['field'] )
			&& 'yes' === get_option( sprintf( 'moksafowo_%s_enabled', $group['slug'] ), 'no' );
	}

	/**
	 * @return string[] 目前啟用的可搜尋 meta key（已去重）。
	 */
	public static function enabled_keys(): array {
		$keys = [];
		foreach ( self::groups() as $group ) {
			if ( self::group_active( $group ) ) {
				array_push( $keys, ...$group['keys'] );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * WC 訂單搜尋 meta-key filter 回呼（HPOS 與 CPT 共用）。
	 *
	 * @param mixed $keys WC 既有搜尋 meta key。
	 * @return string[]
	 */
	public static function add( $keys ): array {
		$keys = is_array( $keys ) ? $keys : [];
		return array_merge( $keys, self::enabled_keys() );
	}

	/**
	 * 判斷某訂單是哪個欄位命中搜尋字串（給結果標示用）。
	 *
	 * @param \WC_Order $order 訂單。
	 * @param string    $term  搜尋字串。
	 * @return string 命中欄位的中文標籤,找不到回空字串。
	 */
	public static function matched_label( \WC_Order $order, string $term ): string {
		$term = mb_strtolower( $term );
		foreach ( self::groups() as $group ) {
			if ( ! self::group_active( $group ) ) {
				continue;
			}
			foreach ( $group['keys'] as $key ) {
				$value = (string) $order->get_meta( $key );
				if ( '' !== $value && false !== mb_strpos( mb_strtolower( $value ), $term ) ) {
					return $group['label'];
				}
			}
		}
		return '';
	}
}
