<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Paynow\Gateways;

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
		return __( 'PayNow 信用卡', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '跳轉至 PayNow 付款頁，使用信用卡完成付款。', 'mo-ectools' );
	}
}
