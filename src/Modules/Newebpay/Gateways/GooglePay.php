<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class GooglePay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_googlepay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'ANDROIDPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 Google Pay', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '使用 Google Pay 快速付款，需 Android 或 Chrome 瀏覽器，跳轉至藍新付款頁完成。', 'mo-ectools' );
	}
}
