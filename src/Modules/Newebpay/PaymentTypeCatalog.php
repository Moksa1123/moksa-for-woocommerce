<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay;

defined( 'ABSPATH' ) || exit;

final class PaymentTypeCatalog {


	public static function label( string $type, ?string $fallback = null ): string {
		$map = [
			'CREDIT'     => __( 'Credit card', 'moksa-for-woocommerce' ),
			'WEBATM'     => __( 'WebATM', 'moksa-for-woocommerce' ),
			'VACC'       => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
			'CVS'        => __( 'Convenience store code', 'moksa-for-woocommerce' ),
			'BARCODE'    => __( 'Convenience store barcode', 'moksa-for-woocommerce' ),
			'APPLEPAY'   => __( 'Apple Pay', 'moksa-for-woocommerce' ),
			'ANDROIDPAY' => __( 'Google Pay', 'moksa-for-woocommerce' ),
			'SAMSUNGPAY' => __( 'Samsung Pay', 'moksa-for-woocommerce' ),
			'LINEPAY'    => __( 'LINE Pay', 'moksa-for-woocommerce' ),
			'ESUNWALLET' => __( 'E.SUN Wallet', 'moksa-for-woocommerce' ),
			'TAIWANPAY'  => __( 'Taiwan Pay', 'moksa-for-woocommerce' ),
			'TWQR'       => __( 'TWQR mobile payment', 'moksa-for-woocommerce' ),
			'EZPALIPAY'  => __( 'Alipay', 'moksa-for-woocommerce' ),
			'EZPWECHAT'  => __( 'WeChat Pay', 'moksa-for-woocommerce' ),
			'AFTEE'      => __( 'AFTEE buy now, pay later', 'moksa-for-woocommerce' ),
			'UNIONPAY'   => __( 'UnionPay', 'moksa-for-woocommerce' ),
		];
		return $map[ $type ] ?? ( $fallback ?? $type );
	}

	private function __construct() {}
}
