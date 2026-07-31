<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Pchomepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Cvs711 extends AbstractPchomepayGateway {

	public const GATEWAY_ID = 'moksafowo_pchomepay_cvs711';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_types(): array {
		return [ 'IPL7' ];
	}

	protected function min_amount(): int {
		return 65;
	}

	protected function max_amount(): int {
		return 20000;
	}

	protected function build_method_title(): string {
		return __( 'PChomePay 7-ELEVEN pickup and pay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'The customer picks a 7-ELEVEN store on the PChomePay page, then pays when collecting.', 'moksa-for-woocommerce' );
	}
}
