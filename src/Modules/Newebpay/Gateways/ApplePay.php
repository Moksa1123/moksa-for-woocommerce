<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class ApplePay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_applepay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'APPLEPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 Apple Pay', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '使用 Apple Pay 快速付款，需 Safari 或 iOS 裝置，跳轉至藍新付款頁完成。', 'mo-ectools' );
	}
}
