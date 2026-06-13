<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Smilepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Unionpay extends AbstractSmilepayGateway {

	public const GATEWAY_ID = 'moksafowo_smilepay_unionpay';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_zg(): string {
		return '11';
	}

	protected function redirect_flow(): bool {
		return true;
	}

	protected function min_amount(): int {
		return 1;
	}

	protected function build_method_title(): string {
		return __( 'SmilePay 銀聯線上刷卡', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '跳轉至 SmilePay 完成銀聯卡線上刷卡。', 'mo-ectools' );
	}
}
