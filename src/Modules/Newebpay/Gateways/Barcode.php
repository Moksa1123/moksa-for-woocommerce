<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Barcode extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_barcode';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'BARCODE' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay convenience store barcode', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Print the three barcode segments and pay at any 7-ELEVEN, FamilyMart, Hi-Life or OK Mart store. NewebPay allows amounts from NT$20 to NT$40,000.', 'moksa-for-woocommerce' );
	}

	protected function min_amount(): int {
		return 20;
	}

	protected function max_amount(): int {
		return 40000;
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'moksafowo_newebpay_barcode_expire_days', 7 );
		$days = max( 1, min( 180, $days ) );
		return [ 'ExpireDate' => gmdate( 'Ymd', time() + $days * DAY_IN_SECONDS ) ];
	}
}
