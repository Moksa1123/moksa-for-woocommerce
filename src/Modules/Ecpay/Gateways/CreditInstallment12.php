<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class CreditInstallment12 extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_credit_12';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'Credit';
	}

	protected function build_method_title(): string {
		return __( '綠界 信用卡分期 12 期', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '信用卡分 12 期付款，跳轉至綠界支付頁完成付款。', 'mo-ectools' );
	}

	protected function extra_aio_params( \WC_Order $order ): array {
		return [ 'CreditInstallment' => 12 ];
	}

	protected function supports_credit_action(): bool {
		return true;
	}
}
