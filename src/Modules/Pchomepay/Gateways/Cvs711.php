<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay\Gateways;

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
		return __( '支付連 7-11 超商取貨付款', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '於支付連付款頁選擇 7-11 門市，到店取貨並付款。', 'mo-ectools' );
	}
}
