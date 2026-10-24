<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Atm extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_atm';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'VACC' => 1 ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay ATM virtual account', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Transfer to the virtual account at an ATM before the payment deadline.', 'moksa-for-woocommerce' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'moksafowo_newebpay_atm_expire_days', 3 );
		$days = max( 1, min( 180, $days ) );
		// 繳費期限是台灣日期；用 UTC 算，台灣凌晨 0～8 點下單會少一天。
		return [ 'ExpireDate' => ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Taipei' ) ) )->modify( "+{$days} days" )->format( 'Ymd' ) ];
	}

	// NDNF：限 49,999 元以下
	protected function max_amount(): int {
		return 49999;
	}
}
