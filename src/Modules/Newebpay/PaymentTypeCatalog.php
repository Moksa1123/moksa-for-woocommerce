<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay;

defined( 'ABSPATH' ) || exit;

final class PaymentTypeCatalog {


	public static function label( string $type, ?string $fallback = null ): string {
		$map = [
			'CREDIT'     => __( '信用卡', 'moksa-for-woocommerce' ),
			'WEBATM'     => __( 'WebATM', 'moksa-for-woocommerce' ),
			'VACC'       => __( 'ATM 虛擬帳號', 'moksa-for-woocommerce' ),
			'CVS'        => __( '超商代碼', 'moksa-for-woocommerce' ),
			'BARCODE'    => __( '超商條碼', 'moksa-for-woocommerce' ),
			'APPLEPAY'   => __( 'Apple Pay', 'moksa-for-woocommerce' ),
			'ANDROIDPAY' => __( 'Google Pay', 'moksa-for-woocommerce' ),
			'SAMSUNGPAY' => __( 'Samsung Pay', 'moksa-for-woocommerce' ),
			'LINEPAY'    => __( 'LINE Pay', 'moksa-for-woocommerce' ),
			'ESUNWALLET' => __( '玉山 Wallet', 'moksa-for-woocommerce' ),
			'TAIWANPAY'  => __( '台灣 Pay', 'moksa-for-woocommerce' ),
			'TWQR'       => __( 'TWQR 行動支付', 'moksa-for-woocommerce' ),
			'EZPALIPAY'  => __( '支付寶', 'moksa-for-woocommerce' ),
			'EZPWECHAT'  => __( '微信支付', 'moksa-for-woocommerce' ),
			'AFTEE'      => __( 'AFTEE 無卡分期', 'moksa-for-woocommerce' ),
			'UNIONPAY'   => __( '銀聯卡', 'moksa-for-woocommerce' ),
		];
		return $map[ $type ] ?? ( $fallback ?? $type );
	}

	private function __construct() {}
}
