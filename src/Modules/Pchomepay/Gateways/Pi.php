<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Pi extends AbstractPchomepayGateway {

	public const GATEWAY_ID = 'mo_pchomepay_pi';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_types(): array {
		return [ 'PI' ];
	}

	protected function min_amount(): int {
		return 1;
	}

	protected function max_amount(): int {
		return 199999;
	}

	protected function build_method_title(): string {
		return __( '支付連 拍錢包', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '使用 PChomePay 拍錢包 App 完成付款。', 'mo-ectools' );
	}
}
