<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Ecpay;

defined( 'ABSPATH' ) || exit;

final class PaymentTypeCatalog {

	public static function label( string $raw, ?string $fallback = null ): string {
		if ( '' === $raw ) {
			return $fallback ?? __( '綠界', 'mo-ectools' );
		}

		$map = [
			'Credit_CreditCard'         => __( '信用卡 — 一次付清', 'mo-ectools' ),
			'Credit_Installment'        => __( '信用卡 — 分期付款', 'mo-ectools' ),
			'Credit_DCC'                => __( '信用卡 — 動態貨幣轉換', 'mo-ectools' ),
			'WebATM_TAISHIN'            => __( '網路 ATM — 台新銀行', 'mo-ectools' ),
			'WebATM_ESUN'               => __( '網路 ATM — 玉山銀行', 'mo-ectools' ),
			'WebATM_BOT'                => __( '網路 ATM — 臺灣銀行', 'mo-ectools' ),
			'WebATM_FUBON'              => __( '網路 ATM — 富邦銀行', 'mo-ectools' ),
			'WebATM_CHINATRUST'         => __( '網路 ATM — 中國信託', 'mo-ectools' ),
			'WebATM_FIRST'              => __( '網路 ATM — 第一銀行', 'mo-ectools' ),
			'WebATM_LAND'               => __( '網路 ATM — 土地銀行', 'mo-ectools' ),
			'WebATM_CATHAY'             => __( '網路 ATM — 國泰世華', 'mo-ectools' ),
			'WebATM_TACHONG'            => __( '網路 ATM — 大眾銀行', 'mo-ectools' ),
			'WebATM_PANHSIN'            => __( '網路 ATM — 板信銀行', 'mo-ectools' ),
			'ATM_TAISHIN'               => __( 'ATM 虛擬帳號 — 台新銀行', 'mo-ectools' ),
			'ATM_ESUN'                  => __( 'ATM 虛擬帳號 — 玉山銀行', 'mo-ectools' ),
			'ATM_BOT'                   => __( 'ATM 虛擬帳號 — 臺灣銀行', 'mo-ectools' ),
			'ATM_FUBON'                 => __( 'ATM 虛擬帳號 — 富邦銀行', 'mo-ectools' ),
			'ATM_CHINATRUST'            => __( 'ATM 虛擬帳號 — 中國信託', 'mo-ectools' ),
			'ATM_FIRST'                 => __( 'ATM 虛擬帳號 — 第一銀行', 'mo-ectools' ),
			'ATM_LAND'                  => __( 'ATM 虛擬帳號 — 土地銀行', 'mo-ectools' ),
			'ATM_CATHAY'                => __( 'ATM 虛擬帳號 — 國泰世華', 'mo-ectools' ),
			'ATM_TACHONG'               => __( 'ATM 虛擬帳號 — 大眾銀行', 'mo-ectools' ),
			'ATM_PANHSIN'               => __( 'ATM 虛擬帳號 — 板信銀行', 'mo-ectools' ),
			'CVS_CVS'                   => __( '超商代碼繳費', 'mo-ectools' ),
			'CVS_OK'                    => __( '超商代碼 — OK', 'mo-ectools' ),
			'CVS_FAMILY'                => __( '超商代碼 — 全家', 'mo-ectools' ),
			'CVS_HILIFE'                => __( '超商代碼 — 萊爾富', 'mo-ectools' ),
			'CVS_IBON'                  => __( '超商代碼 — ibon', 'mo-ectools' ),
			'BARCODE_BARCODE'           => __( '超商條碼繳費', 'mo-ectools' ),
			'ApplePay'                  => __( 'Apple Pay', 'mo-ectools' ),
			'GooglePay'                 => __( 'Google Pay', 'mo-ectools' ),
			'TWQR_OPAY'                 => __( '行動支付 — TWQR', 'mo-ectools' ),
			'WeiXin'                    => __( '微信支付', 'mo-ectools' ),
			'JKO'                       => __( '街口支付', 'mo-ectools' ),
			'JKOPay_JKO'                => __( '街口支付', 'mo-ectools' ),
			'iPassPay'                  => __( '一卡通 iPASS', 'mo-ectools' ),
			'BNPL_BNPL'                 => __( '無卡分期 BNPL', 'mo-ectools' ),
			'BNPL_URICH'                => __( '無卡分期 — 裕富數位', 'mo-ectools' ),
			'BNPL_ZINGALA'              => __( '無卡分期 — 中租銀角零卡', 'mo-ectools' ),
			'GWPay_OPAY'                => __( '綠界 Pay', 'mo-ectools' ),
		];
		if ( isset( $map[ $raw ] ) ) {
			return $map[ $raw ];
		}

		// 大類別前綴（銀行多到列不完）
		$prefixes = [
			'Credit_'  => __( '信用卡', 'mo-ectools' ),
			'WebATM_'  => __( '網路 ATM', 'mo-ectools' ),
			'ATM_'     => __( 'ATM 虛擬帳號', 'mo-ectools' ),
			'CVS_'     => __( '超商代碼繳費', 'mo-ectools' ),
			'BARCODE_' => __( '超商條碼繳費', 'mo-ectools' ),
			'BNPL_'    => __( '無卡分期', 'mo-ectools' ),
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
