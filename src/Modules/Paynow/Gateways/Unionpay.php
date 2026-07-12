<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow\Gateways;

defined( 'ABSPATH' ) || exit;

final class Unionpay extends AbstractPaynowGateway {

	public const GATEWAY_ID = 'moksafowo_paynow_unionpay';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_type(): string {
		return '09';
	}

	protected function build_method_title(): string {
		return __( 'PayNow 銀聯卡', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '跳轉至 PayNow 付款頁，使用銀聯卡完成付款。', 'mo-ectools' );
	}
}
