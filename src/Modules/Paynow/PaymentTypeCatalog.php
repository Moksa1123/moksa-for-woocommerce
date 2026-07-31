<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow;

defined( 'ABSPATH' ) || exit;

final class PaymentTypeCatalog {

	public static function label( string $type, ?string $fallback = null ): string {
		$map = [
			'01' => __( 'Credit card', 'moksa-for-woocommerce' ),
			'02' => __( 'WebATM', 'moksa-for-woocommerce' ),
			'03' => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
			'05' => __( 'Code payment', 'moksa-for-woocommerce' ),
			'09' => __( 'UnionPay', 'moksa-for-woocommerce' ),
			'10' => __( 'Convenience store barcode', 'moksa-for-woocommerce' ),
			'11' => __( 'Credit card instalments', 'moksa-for-woocommerce' ),
		];
		return $map[ $type ] ?? ( $fallback ?? $type );
	}

	private function __construct() {}
}
