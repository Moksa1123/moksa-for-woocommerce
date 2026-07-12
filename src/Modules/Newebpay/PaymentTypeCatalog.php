<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay;

defined( 'ABSPATH' ) || exit;

final class PaymentTypeCatalog {


	public static function label( string $type, ?string $fallback = null ): string {
		$map = [
			'CREDIT'     => __( '信用卡', 'mo-ectools' ),
			'WEBATM'     => __( 'WebATM', 'mo-ectools' ),
			'VACC'       => __( 'ATM 虛擬帳號', 'mo-ectools' ),
			'CVS'        => __( '超商代碼', 'mo-ectools' ),
			'BARCODE'    => __( '超商條碼', 'mo-ectools' ),
			'APPLEPAY'   => __( 'Apple Pay', 'mo-ectools' ),
			'ANDROIDPAY' => __( 'Google Pay', 'mo-ectools' ),
			'SAMSUNGPAY' => __( 'Samsung Pay', 'mo-ectools' ),
			'LINEPAY'    => __( 'LINE Pay', 'mo-ectools' ),
			'ESUNWALLET' => __( '玉山 Wallet', 'mo-ectools' ),
			'TAIWANPAY'  => __( '台灣 Pay', 'mo-ectools' ),
			'TWQR'       => __( 'TWQR 行動支付', 'mo-ectools' ),
			'EZPALIPAY'  => __( '支付寶', 'mo-ectools' ),
			'EZPWECHAT'  => __( '微信支付', 'mo-ectools' ),
			'AFTEE'      => __( 'AFTEE 無卡分期', 'mo-ectools' ),
			'UNIONPAY'   => __( '銀聯卡', 'mo-ectools' ),
		];
		return $map[ $type ] ?? ( $fallback ?? $type );
	}

	private function __construct() {}
}
