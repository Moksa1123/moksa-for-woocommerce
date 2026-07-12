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
		return __( 'SmilePay 信用卡', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '跳轉至 SmilePay 完成線上刷卡。', 'mo-ectools' );
	}
}
