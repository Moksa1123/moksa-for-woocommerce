<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Ibon extends AbstractSmilepayGateway {

	public const GATEWAY_ID = 'moksafowo_smilepay_ibon';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_zg(): string {
		return '4';
	}

	protected function redirect_flow(): bool {
		return false;
	}

	protected function min_amount(): int {
		return 30;
	}

	protected function max_amount(): int {
		return 20000;
	}

	protected function build_method_title(): string {
		return __( 'SmilePay ibon code', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Print a payment slip from a 7-ELEVEN ibon or Hi-Life LifeET machine and pay with the code.', 'moksa-for-woocommerce' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'moksafowo_smilepay_ibon_deadline_days', 6 );
		$days = max( 1, min( 6, $days ) );
		// 繳費期限是台灣日期；用 UTC 算，台灣凌晨 0～8 點下單會少一天。
		return [ 'Deadline_date' => ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Taipei' ) ) )->modify( "+{$days} days" )->format( 'Y/m/d' ) ];
	}
}
