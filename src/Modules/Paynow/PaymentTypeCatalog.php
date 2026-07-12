<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow;

defined( 'ABSPATH' ) || exit;

final class PaymentTypeCatalog {

	public static function label( string $type, ?string $fallback = null ): string {
		$map = [
			'01' => __( '信用卡', 'mo-ectools' ),
			'02' => __( 'WebATM', 'mo-ectools' ),
			'03' => __( 'ATM 虛擬帳號', 'mo-ectools' ),
			'05' => __( '代碼繳費', 'mo-ectools' ),
			'09' => __( '銀聯卡', 'mo-ectools' ),
			'10' => __( '超商條碼', 'mo-ectools' ),
			'11' => __( '信用卡分期', 'mo-ectools' ),
		];
		return $map[ $type ] ?? ( $fallback ?? $type );
	}

	private function __construct() {}
}
