<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay;

defined( 'ABSPATH' ) || exit;

final class PaymentTypeCatalog {

	public static function label( string $type, ?string $fallback = null ): string {
		$map = [
			'CARD'   => __( '信用卡', 'mo-ectools' ),
			'PI'     => __( '拍錢包', 'mo-ectools' ),
			'ATM'    => __( 'ATM 虛擬帳號', 'mo-ectools' ),
			'BCODE'  => __( '超商代碼繳費', 'mo-ectools' ),
			'IPL7'   => __( '7-11 取貨付款', 'mo-ectools' ),
			'IPLFM'  => __( '全家取貨付款', 'mo-ectools' ),
			'IPLHL'  => __( '萊爾富取貨付款', 'mo-ectools' ),
		];
		return $map[ $type ] ?? ( $fallback ?? $type );
	}

	public static function logistic_label( string $notify_type, ?string $fallback = null ): string {
		$map = [
			'seller_dispatched' => __( '商品已至寄件門店', 'mo-ectools' ),
			'pickup_shipped'    => __( '商品已至取件門店', 'mo-ectools' ),
			'return_shipped'    => __( '商品已至退件門店', 'mo-ectools' ),
		];
		return $map[ $notify_type ] ?? ( $fallback ?? $notify_type );
	}

	private function __construct() {}
}
