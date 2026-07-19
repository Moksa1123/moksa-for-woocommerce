<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay;

defined( 'ABSPATH' ) || exit;

final class PaymentTypeCatalog {

	public static function label( string $raw, ?string $fallback = null ): string {
		if ( '' === $raw ) {
			return $fallback ?? __( '綠界', 'moksa-for-woocommerce' );
		}

		$map = [
			'Credit_CreditCard'  => __( '信用卡 — 一次付清', 'moksa-for-woocommerce' ),
			'Credit_Installment' => __( '信用卡 — 分期付款', 'moksa-for-woocommerce' ),
			'Credit_DCC'         => __( '信用卡 — 動態貨幣轉換', 'moksa-for-woocommerce' ),
			'WebATM_TAISHIN'     => __( '網路 ATM — 台新銀行', 'moksa-for-woocommerce' ),
			'WebATM_ESUN'        => __( '網路 ATM — 玉山銀行', 'moksa-for-woocommerce' ),
			'WebATM_BOT'         => __( '網路 ATM — 臺灣銀行', 'moksa-for-woocommerce' ),
			'WebATM_FUBON'       => __( '網路 ATM — 富邦銀行', 'moksa-for-woocommerce' ),
			'WebATM_CHINATRUST'  => __( '網路 ATM — 中國信託', 'moksa-for-woocommerce' ),
			'WebATM_FIRST'       => __( '網路 ATM — 第一銀行', 'moksa-for-woocommerce' ),
			'WebATM_LAND'        => __( '網路 ATM — 土地銀行', 'moksa-for-woocommerce' ),
			'WebATM_CATHAY'      => __( '網路 ATM — 國泰世華', 'moksa-for-woocommerce' ),
			'WebATM_TACHONG'     => __( '網路 ATM — 大眾銀行', 'moksa-for-woocommerce' ),
			'WebATM_PANHSIN'     => __( '網路 ATM — 板信銀行', 'moksa-for-woocommerce' ),
			'ATM_TAISHIN'        => __( 'ATM 虛擬帳號 — 台新銀行', 'moksa-for-woocommerce' ),
			'ATM_ESUN'           => __( 'ATM 虛擬帳號 — 玉山銀行', 'moksa-for-woocommerce' ),
			'ATM_BOT'            => __( 'ATM 虛擬帳號 — 臺灣銀行', 'moksa-for-woocommerce' ),
			'ATM_FUBON'          => __( 'ATM 虛擬帳號 — 富邦銀行', 'moksa-for-woocommerce' ),
			'ATM_CHINATRUST'     => __( 'ATM 虛擬帳號 — 中國信託', 'moksa-for-woocommerce' ),
			'ATM_FIRST'          => __( 'ATM 虛擬帳號 — 第一銀行', 'moksa-for-woocommerce' ),
			'ATM_LAND'           => __( 'ATM 虛擬帳號 — 土地銀行', 'moksa-for-woocommerce' ),
			'ATM_CATHAY'         => __( 'ATM 虛擬帳號 — 國泰世華', 'moksa-for-woocommerce' ),
			'ATM_TACHONG'        => __( 'ATM 虛擬帳號 — 大眾銀行', 'moksa-for-woocommerce' ),
			'ATM_PANHSIN'        => __( 'ATM 虛擬帳號 — 板信銀行', 'moksa-for-woocommerce' ),
			'CVS_CVS'            => __( '超商代碼繳費', 'moksa-for-woocommerce' ),
			'CVS_OK'             => __( '超商代碼 — OK', 'moksa-for-woocommerce' ),
			'CVS_FAMILY'         => __( '超商代碼 — 全家', 'moksa-for-woocommerce' ),
			'CVS_HILIFE'         => __( '超商代碼 — 萊爾富', 'moksa-for-woocommerce' ),
			'CVS_IBON'           => __( '超商代碼 — ibon', 'moksa-for-woocommerce' ),
			'BARCODE_BARCODE'    => __( '超商條碼繳費', 'moksa-for-woocommerce' ),
			'ApplePay'           => __( 'Apple Pay', 'moksa-for-woocommerce' ),
			'GooglePay'          => __( 'Google Pay', 'moksa-for-woocommerce' ),
			'TWQR_OPAY'          => __( '行動支付 — TWQR', 'moksa-for-woocommerce' ),
			'WeiXin'             => __( '微信支付', 'moksa-for-woocommerce' ),
			'JKO'                => __( '街口支付', 'moksa-for-woocommerce' ),
			'JKOPay_JKO'         => __( '街口支付', 'moksa-for-woocommerce' ),
			'iPassPay'           => __( '一卡通 iPASS', 'moksa-for-woocommerce' ),
			'BNPL_BNPL'          => __( '無卡分期 BNPL', 'moksa-for-woocommerce' ),
			'BNPL_URICH'         => __( '無卡分期 — 裕富數位', 'moksa-for-woocommerce' ),
			'BNPL_ZINGALA'       => __( '無卡分期 — 中租銀角零卡', 'moksa-for-woocommerce' ),
			'GWPay_OPAY'         => __( '綠界 Pay', 'moksa-for-woocommerce' ),
		];
		if ( isset( $map[ $raw ] ) ) {
			return $map[ $raw ];
		}

		$prefixes = [
			'Credit_'  => __( '信用卡', 'moksa-for-woocommerce' ),
			'WebATM_'  => __( '網路 ATM', 'moksa-for-woocommerce' ),
			'ATM_'     => __( 'ATM 虛擬帳號', 'moksa-for-woocommerce' ),
			'CVS_'     => __( '超商代碼繳費', 'moksa-for-woocommerce' ),
			'BARCODE_' => __( '超商條碼繳費', 'moksa-for-woocommerce' ),
			'BNPL_'    => __( '無卡分期', 'moksa-for-woocommerce' ),
		];
		foreach ( $prefixes as $prefix => $label ) {
			if ( str_starts_with( $raw, $prefix ) ) {
				return $label;
			}
		}
		return $raw;
	}

	private function __construct() {}
}
