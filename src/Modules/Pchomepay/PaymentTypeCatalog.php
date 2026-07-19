<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Pchomepay;

defined( 'ABSPATH' ) || exit;

final class PaymentTypeCatalog {

	public static function label( string $type, ?string $fallback = null ): string {
		$map = [
			'CARD'  => __( '信用卡', 'moksa-for-woocommerce' ),
			'PI'    => __( '拍錢包', 'moksa-for-woocommerce' ),
			'ATM'   => __( 'ATM 虛擬帳號', 'moksa-for-woocommerce' ),
			'BCODE' => __( '超商代碼繳費', 'moksa-for-woocommerce' ),
			'IPL7'  => __( '7-11 取貨付款', 'moksa-for-woocommerce' ),
			'IPLFM' => __( '全家取貨付款', 'moksa-for-woocommerce' ),
			'IPLHL' => __( '萊爾富取貨付款', 'moksa-for-woocommerce' ),
		];
		return $map[ $type ] ?? ( $fallback ?? $type );
	}

	public static function logistic_label( string $notify_type, ?string $fallback = null ): string {
		$map = [
			'seller_dispatched' => __( '商品已至寄件門店', 'moksa-for-woocommerce' ),
			'pickup_shipped'    => __( '商品已至取件門店', 'moksa-for-woocommerce' ),
			'return_shipped'    => __( '商品已至退件門店', 'moksa-for-woocommerce' ),
		];
		return $map[ $notify_type ] ?? ( $fallback ?? $notify_type );
	}

	private function __construct() {}
}
