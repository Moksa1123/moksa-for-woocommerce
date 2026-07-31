<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Pchomepay;

defined( 'ABSPATH' ) || exit;

final class PaymentTypeCatalog {

	public static function label( string $type, ?string $fallback = null ): string {
		$map = [
			'CARD'  => __( 'Credit card', 'moksa-for-woocommerce' ),
			'PI'    => __( 'Pi Wallet', 'moksa-for-woocommerce' ),
			'ATM'   => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
			'BCODE' => __( 'Convenience store code payment', 'moksa-for-woocommerce' ),
			'IPL7'  => __( '7-ELEVEN pickup and pay', 'moksa-for-woocommerce' ),
			'IPLFM' => __( 'FamilyMart pickup and pay', 'moksa-for-woocommerce' ),
			'IPLHL' => __( 'Hi-Life pickup and pay', 'moksa-for-woocommerce' ),
		];
		return $map[ $type ] ?? ( $fallback ?? $type );
	}

	public static function logistic_label( string $notify_type, ?string $fallback = null ): string {
		$map = [
			'seller_dispatched' => __( 'Arrived at the drop-off store', 'moksa-for-woocommerce' ),
			'pickup_shipped'    => __( 'Arrived at the pickup store', 'moksa-for-woocommerce' ),
			'return_shipped'    => __( 'Arrived at the return store', 'moksa-for-woocommerce' ),
		];
		return $map[ $notify_type ] ?? ( $fallback ?? $notify_type );
	}

	private function __construct() {}
}
