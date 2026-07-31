<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Credit extends AbstractSmilepayGateway {

	public const GATEWAY_ID = 'moksafowo_smilepay_credit';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_zg(): string {
		return '1';
	}

	protected function redirect_flow(): bool {
		return true;
	}

	protected function min_amount(): int {
		return 1;
	}

	protected function build_method_title(): string {
		return __( 'SmilePay credit card', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'The customer is redirected to SmilePay to pay by card.', 'moksa-for-woocommerce' );
	}
}
