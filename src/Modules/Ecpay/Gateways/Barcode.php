<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Barcode extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_barcode';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'BARCODE';
	}

	protected function build_method_title(): string {
		return __( 'ECPay convenience store barcode', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Print the barcode and pay at 7-ELEVEN, FamilyMart, Hi-Life or OK Mart within 7 days.', 'moksa-for-woocommerce' );
	}

	protected function extra_aio_params( \WC_Order $order ): array {
		return [
			'StoreExpireDate' => max( 1, min( 7, (int) get_option( 'moksafowo_ecpay_barcode_expire_days', 7 ) ) ),
			'PaymentInfoURL'  => home_url( '/wc-api/moksafowo_ecpay_payment' ),
		];
	}
}
