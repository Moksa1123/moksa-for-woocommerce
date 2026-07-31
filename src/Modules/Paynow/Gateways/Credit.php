<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow\Gateways;

defined( 'ABSPATH' ) || exit;

final class Credit extends AbstractPaynowGateway {

	public const GATEWAY_ID = 'moksafowo_paynow_credit';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_type(): string {
		return '01';
	}

	protected function build_method_title(): string {
		return __( 'PayNow credit card', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'The customer is redirected to PayNow to pay by credit card.', 'moksa-for-woocommerce' );
	}
}
