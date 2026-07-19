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
		return __( '跳轉至 PayNow 付款頁，使用 WebATM 即時轉帳付款（非約定帳戶單日上限 NT$3 萬）。', 'moksa-for-woocommerce' );
	}
}
