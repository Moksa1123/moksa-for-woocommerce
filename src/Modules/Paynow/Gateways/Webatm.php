<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow\Gateways;

defined( 'ABSPATH' ) || exit;

final class Webatm extends AbstractPaynowGateway {

	public const GATEWAY_ID = 'moksafowo_paynow_webatm';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_type(): string {
		return '02';
	}

	protected function max_amount(): int {
		return 30000;
	}

	protected function build_method_title(): string {
		return __( 'PayNow WebATM', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'The customer is redirected to PayNow to pay by instant WebATM transfer. Unregistered accounts are capped at NT$30,000 a day.', 'moksa-for-woocommerce' );
	}
}
