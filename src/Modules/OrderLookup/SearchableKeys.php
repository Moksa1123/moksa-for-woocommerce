<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

use Moksafowo\Order\Meta\Keys;

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
			[
				'field' => 'payment',
				'label' => __( '金流交易序號', 'moksa-for-woocommerce' ),
				'slug'  => 'ecpay',
				'keys'  => [ Keys::ECPAY_TRADE_NO, Keys::ECPAY_MERCHANT_TRADE_NO ],
			],
			[
				'field' => 'payment',
				'label' => __( '金流交易序號', 'moksa-for-woocommerce' ),
				'slug'  => 'newebpay',
				'keys'  => [ Keys::NEWEBPAY_TRADE_NO, Keys::NEWEBPAY_MERCHANT_ORDER_NO ],
			],
			[
				'field' => 'payment',
				'label' => __( '金流交易序號', 'moksa-for-woocommerce' ),
				'slug'  => 'payuni',
				'keys'  => [ Keys::PAYUNI_ORDER_NO, Keys::PAYUNI_TRADE_NO ],
			],
			[
				'field' => 'payment',
				'label' => __( '金流交易序號', 'moksa-for-woocommerce' ),
				'slug'  => 'smilepay',
				'keys'  => [ Keys::SMILEPAY_PAY_SMILEPAY_NO ],
			],
			[
				'field' => 'payment',
				'label' => __( '金流交易序號', 'moksa-for-woocommerce' ),
				'slug'  => 'linepay',
				'keys'  => [ Keys::LINEPAY_TRANSACTION_ID ],
			],
			[
				'field' => 'payment',
				'label' => __( '金流交易序號', 'moksa-for-woocommerce' ),
				'slug'  => 'paynow',
				'keys'  => [ Keys::PAYNOW_ORDER_NO ],
			],
			[
				'field' => 'payment',
				'label' => __( '金流交易序號', 'moksa-for-woocommerce' ),
				'slug'  => 'pchomepay',
				'keys'  => [ Keys::PCHOMEPAY_ORDER_ID ],
			],
			[
				'field' => 'payment',
				'label' => __( '金流交易序號', 'moksa-for-woocommerce' ),
				'slug'  => 'tappay',
				'keys'  => [ Keys::TAPPAY_REC_TRADE_ID, Keys::TAPPAY_ORDER_NUMBER ],
			],
			[
				'field' => 'payment',
				'label' => __( '金流交易序號', 'moksa-for-woocommerce' ),
				'slug'  => 'shopline_payments',
				'keys'  => [ Keys::SLP_TRADE_ORDER_ID ],
			],
			[
				'field' => 'shipping',
				'label' => __( '物流單號', 'moksa-for-woocommerce' ),
				'slug'  => 'ecpay_shipping',
				'keys'  => [ Keys::ECPAY_LOGISTIC_ID, Keys::ECPAY_LOGISTIC_CVS_PAYMENT_NO ],
			],
			[
				'field' => 'shipping',
				'label' => __( '物流單號', 'moksa-for-woocommerce' ),
				'slug'  => 'newebpay_shipping',
				'keys'  => [ Keys::NEWEBPAY_SHIPPING_LGS_NO ],
			],
			[
				'field' => 'shipping',
				'label' => __( '物流單號', 'moksa-for-woocommerce' ),
				'slug'  => 'payuni_shipping',
				'keys'  => [ Keys::PAYUNI_SHIPPING_TRADE_NO, Keys::PAYUNI_SHIPPING_SNO ],
			],
			[
				'field' => 'shipping',
				'label' => __( '物流單號', 'moksa-for-woocommerce' ),
				'slug'  => 'smilepay_shipping',
				'keys'  => [ Keys::SMILEPAY_SHIPPING_NO, Keys::SMILEPAY_SHIPPING_PAY_NO ],
			],
			[
				'field' => 'invoice',
				'label' => __( '發票號碼', 'moksa-for-woocommerce' ),
				'slug'  => 'ecpay_invoice',
				'keys'  => [ Keys::ECPAY_INVOICE_NUMBER ],
			],
			[
				'field' => 'invoice',
				'label' => __( '發票號碼', 'moksa-for-woocommerce' ),
				'slug'  => 'ezpay_invoice',
				'keys'  => [ Keys::EZPAY_INVOICE_NUMBER ],
			],
			[
				'field' => 'invoice',
				'label' => __( '發票號碼', 'moksa-for-woocommerce' ),
				'slug'  => 'smilepay_invoice',
				'keys'  => [ Keys::SMILEPAY_INVOICE_NUMBER ],
			],
			[
				'field' => 'invoice',
				'label' => __( '發票號碼', 'moksa-for-woocommerce' ),
				'slug'  => 'paynow_invoice',
				'keys'  => [ Keys::PAYNOW_INVOICE_NUMBER ],
			],
			[
				'field' => 'invoice',
				'label' => __( '發票號碼', 'moksa-for-woocommerce' ),
				'slug'  => 'amego_invoice',
				'keys'  => [ Keys::AMEGO_INVOICE_NUMBER ],
			],
			[
				'field' => 'invoice',
				'label' => __( '發票號碼', 'moksa-for-woocommerce' ),
				'slug'  => 'payuni',
				'keys'  => [ Keys::PAYUNI_EINVOICE_NO ],
			],
			[
				'field' => 'ubn',
				'label' => __( '統一編號', 'moksa-for-woocommerce' ),
				'slug'  => 'ecpay_invoice',
				'keys'  => [ Keys::INVOICE_BUYER_UBN ],
			],
			[
				'field' => 'atm',
				'label' => __( 'ATM 虛擬帳號', 'moksa-for-woocommerce' ),
				'slug'  => 'ecpay',
				'keys'  => [ Keys::ECPAY_ATM_V_ACCOUNT ],
			],
			[
				'field' => 'atm',
				'label' => __( 'ATM 虛擬帳號', 'moksa-for-woocommerce' ),
				'slug'  => 'newebpay',
				'keys'  => [ Keys::NEWEBPAY_ATM_CODE_NO ],
			],
			[
				'field' => 'atm',
				'label' => __( 'ATM 虛擬帳號', 'moksa-for-woocommerce' ),
				'slug'  => 'smilepay',
				'keys'  => [ Keys::SMILEPAY_PAY_ATM_NO ],
			],
			[
				'field' => 'atm',
				'label' => __( 'ATM 虛擬帳號', 'moksa-for-woocommerce' ),
				'slug'  => 'payuni',
				'keys'  => [ Keys::PAYUNI_ATM_PAYNO ],
			],
			[
				'field' => 'cvs',
				'label' => __( '超商繳費代碼', 'moksa-for-woocommerce' ),
				'slug'  => 'ecpay',
				'keys'  => [ Keys::ECPAY_CVS_PAYMENT_NO ],
			],
			[
				'field' => 'cvs',
				'label' => __( '超商繳費代碼', 'moksa-for-woocommerce' ),
				'slug'  => 'newebpay',
				'keys'  => [ Keys::NEWEBPAY_CVS_CODE_NO ],
			],
			[
				'field' => 'card',
				'label' => __( '卡末四碼', 'moksa-for-woocommerce' ),
				'slug'  => 'ecpay',
				'keys'  => [ Keys::ECPAY_CARD_LAST4 ],
			],
			[
				'field' => 'card',
				'label' => __( '卡末四碼', 'moksa-for-woocommerce' ),
				'slug'  => 'tappay',
				'keys'  => [ Keys::TAPPAY_CARD_LAST4 ],
			],
			[
				'field' => 'tcat',
				'label' => __( '黑貓追蹤號', 'moksa-for-woocommerce' ),
				'slug'  => 'smilepay_shipping',
				'keys'  => [ Keys::SMILEPAY_SHIPPING_TRACK_NO ],
			],
		];
	}

	public static function field_on( string $field ): bool {
		$defaults = self::field_defaults();
		$default  = $defaults[ $field ] ?? 'no';
		return 'yes' === get_option( self::FIELD_OPTION_PREFIX . $field, $default );
	}

	/**
	 * 索引查詢路徑的命中標籤 —— 掃訂單號碼，找出 exact / prefix 命中且該類型開著的欄位。
	 *
	 * @param \WC_Order $order 訂單。
	 * @param string    $term  搜尋字串。
	 * @return string 命中號碼類型的中文標籤，找不到回空字串。
	 */
	public static function index_matched_label( \WC_Order $order, string $term ): string {
		$term = mb_strtolower( trim( $term ) );
		$on   = self::query_fields();
		foreach ( self::index_pairs( $order ) as $pair ) {
			if ( ! in_array( $pair['field'], $on, true ) ) {
				continue;
			}
			$val = mb_strtolower( $pair['num'] );
			if ( $val === $term || 0 === mb_strpos( $val, $term ) ) {
				return self::field_label( $pair['field'] );
			}
		}
		return '';
	}

	/**
	 * 號碼類型 → 中文標籤（索引查詢路徑用，索引只存 field 不存 slug）。
	 *
	 * @return array<string, string>
	 */
	public static function field_labels(): array {
		return [
			'invoice'  => __( '發票號碼', 'moksa-for-woocommerce' ),
			'shipping' => __( '物流單號', 'moksa-for-woocommerce' ),
			'payment'  => __( '金流交易序號', 'moksa-for-woocommerce' ),
			'ubn'      => __( '統一編號', 'moksa-for-woocommerce' ),
			'atm'      => __( 'ATM 虛擬帳號', 'moksa-for-woocommerce' ),
			'cvs'      => __( '超商繳費代碼', 'moksa-for-woocommerce' ),
			'card'     => __( '卡末四碼', 'moksa-for-woocommerce' ),
			'tcat'     => __( '黑貓追蹤號', 'moksa-for-woocommerce' ),
		];
	}

	public static function field_label( string $field ): string {
		return self::field_labels()[ $field ] ?? '';
	}

	/**
	 * 目前「開著」的號碼類型（索引查詢時用來 gate；不含模組啟用判斷 ——
	 * 索引以號碼類型為準，停用某金流模組後仍可查到舊單的號碼）。
	 *
	 * @return string[]
	 */
	public static function query_fields(): array {
		$out = [];
		foreach ( array_keys( self::field_defaults() ) as $field ) {
			if ( self::field_on( $field ) ) {
				$out[] = $field;
			}
		}
		return $out;
	}

	/**
	 * 抽出一張訂單所有可搜尋號碼（field + 值），給索引表寫入用。
	 * 索引「全部號碼類型」（不受 field 開關 / 模組啟用 gate）—— gate 在查詢時做，
	 * 之後開啟某類型不必重建索引。同一 (field, 值) 去重。
	 *
	 * @param \WC_Order $order 訂單。
	 * @return array<int, array{field:string, num:string}>
	 */
	public static function index_pairs( \WC_Order $order ): array {
		$seen = [];
		$out  = [];
		foreach ( self::groups() as $group ) {
			$field = $group['field'];
			foreach ( $group['keys'] as $key ) {
				$value = trim( (string) $order->get_meta( $key ) );
				if ( '' === $value ) {
					continue;
				}
				$value = mb_substr( $value, 0, 64 );
				$dedup = $field . "\0" . mb_strtolower( $value );
				if ( isset( $seen[ $dedup ] ) ) {
					continue;
				}
				$seen[ $dedup ] = true;
				$out[]          = [
					'field' => $field,
					'num'   => $value,
				];
			}
		}
		return $out;
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

	/**
	 * 取某「號碼類型」在訂單上的第一個非空值（明細顯示用，不受搜尋開關 gate）。
	 *
	 * @param \WC_Order $order 訂單。
	 * @param string    $field 號碼類型（invoice / shipping / payment ...）。
	 * @return string 找到的號碼，沒有回空字串。
	 */
	public static function field_value( \WC_Order $order, string $field ): string {
		foreach ( self::groups() as $group ) {
			if ( $group['field'] !== $field ) {
				continue;
			}
			foreach ( $group['keys'] as $key ) {
				$value = trim( (string) $order->get_meta( $key ) );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
	}
}
