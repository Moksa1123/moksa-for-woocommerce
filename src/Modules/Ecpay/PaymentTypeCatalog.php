<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay;

defined( 'ABSPATH' ) || exit;

final class PaymentTypeCatalog {

	public static function label( string $raw, ?string $fallback = null ): string {
		if ( '' === $raw ) {
			return $fallback ?? __( 'ECPay', 'moksa-for-woocommerce' );
		}

		$map = [
			'Credit_CreditCard'  => __( 'Credit card — pay in full', 'moksa-for-woocommerce' ),
			'Credit_Installment' => __( 'Credit card — instalments', 'moksa-for-woocommerce' ),
			'Credit_DCC'         => __( 'Credit card — dynamic currency conversion', 'moksa-for-woocommerce' ),
			'WebATM_TAISHIN'     => __( 'WebATM — Taishin Bank', 'moksa-for-woocommerce' ),
			'WebATM_ESUN'        => __( 'WebATM — E.SUN Bank', 'moksa-for-woocommerce' ),
			'WebATM_BOT'         => __( 'WebATM — Bank of Taiwan', 'moksa-for-woocommerce' ),
			'WebATM_FUBON'       => __( 'WebATM — Fubon Bank', 'moksa-for-woocommerce' ),
			'WebATM_CHINATRUST'  => __( 'WebATM — CTBC Bank', 'moksa-for-woocommerce' ),
			'WebATM_FIRST'       => __( 'WebATM — First Bank', 'moksa-for-woocommerce' ),
			'WebATM_LAND'        => __( 'WebATM — Land Bank of Taiwan', 'moksa-for-woocommerce' ),
			'WebATM_CATHAY'      => __( 'WebATM — Cathay United Bank', 'moksa-for-woocommerce' ),
			'WebATM_TACHONG'     => __( 'WebATM — Ta Chong Bank', 'moksa-for-woocommerce' ),
			'WebATM_PANHSIN'     => __( 'WebATM — Bank of Panhsin', 'moksa-for-woocommerce' ),
			'ATM_TAISHIN'        => __( 'ATM virtual account — Taishin Bank', 'moksa-for-woocommerce' ),
			'ATM_ESUN'           => __( 'ATM virtual account — E.SUN Bank', 'moksa-for-woocommerce' ),
			'ATM_BOT'            => __( 'ATM virtual account — Bank of Taiwan', 'moksa-for-woocommerce' ),
			'ATM_FUBON'          => __( 'ATM virtual account — Fubon Bank', 'moksa-for-woocommerce' ),
			'ATM_CHINATRUST'     => __( 'ATM virtual account — CTBC Bank', 'moksa-for-woocommerce' ),
			'ATM_FIRST'          => __( 'ATM virtual account — First Bank', 'moksa-for-woocommerce' ),
			'ATM_LAND'           => __( 'ATM virtual account — Land Bank of Taiwan', 'moksa-for-woocommerce' ),
			'ATM_CATHAY'         => __( 'ATM virtual account — Cathay United Bank', 'moksa-for-woocommerce' ),
			'ATM_TACHONG'        => __( 'ATM virtual account — Ta Chong Bank', 'moksa-for-woocommerce' ),
			'ATM_PANHSIN'        => __( 'ATM virtual account — Bank of Panhsin', 'moksa-for-woocommerce' ),
			'CVS_CVS'            => __( 'Convenience store code payment', 'moksa-for-woocommerce' ),
			'CVS_OK'             => __( 'Convenience store code — OK Mart', 'moksa-for-woocommerce' ),
			'CVS_FAMILY'         => __( 'Convenience store code — FamilyMart', 'moksa-for-woocommerce' ),
			'CVS_HILIFE'         => __( 'Convenience store code — Hi-Life', 'moksa-for-woocommerce' ),
			'CVS_IBON'           => __( 'Convenience store code — ibon', 'moksa-for-woocommerce' ),
			'BARCODE_BARCODE'    => __( 'Convenience store barcode payment', 'moksa-for-woocommerce' ),
			'ApplePay'           => __( 'Apple Pay', 'moksa-for-woocommerce' ),
			'GooglePay'          => __( 'Google Pay', 'moksa-for-woocommerce' ),
			'TWQR_OPAY'          => __( 'Mobile payment — TWQR', 'moksa-for-woocommerce' ),
			'WeiXin'             => __( 'WeChat Pay', 'moksa-for-woocommerce' ),
			'JKO'                => __( 'JKOPAY', 'moksa-for-woocommerce' ),
			'JKOPay_JKO'         => __( 'JKOPAY', 'moksa-for-woocommerce' ),
			'iPassPay'           => __( 'iPASS Money', 'moksa-for-woocommerce' ),
			'BNPL_BNPL'          => __( 'Buy now, pay later (BNPL)', 'moksa-for-woocommerce' ),
			'BNPL_URICH'         => __( 'Buy now, pay later — Yufu Digital', 'moksa-for-woocommerce' ),
			'BNPL_ZINGALA'       => __( 'Buy now, pay later — Zingala', 'moksa-for-woocommerce' ),
			'GWPay_OPAY'         => __( 'ECPay Pay', 'moksa-for-woocommerce' ),
		];
		if ( isset( $map[ $raw ] ) ) {
			return $map[ $raw ];
		}

		$prefixes = [
			'Credit_'  => __( 'Credit card', 'moksa-for-woocommerce' ),
			'WebATM_'  => __( 'WebATM', 'moksa-for-woocommerce' ),
			'ATM_'     => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
			'CVS_'     => __( 'Convenience store code payment', 'moksa-for-woocommerce' ),
			'BARCODE_' => __( 'Convenience store barcode payment', 'moksa-for-woocommerce' ),
			'BNPL_'    => __( 'Buy now, pay later', 'moksa-for-woocommerce' ),
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
