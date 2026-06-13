<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class ApplePay extends AbstractEcpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_ecpay_applepay';
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'ApplePay';
	}

	protected function build_method_title(): string {
		return __( '綠界 Apple Pay', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '使用 Apple Pay 快速結帳，需 Safari 或支援 Apple Pay 的裝置。', 'mo-ectools' );
	}

	protected function supports_credit_action(): bool {
		return true;
	}
}
