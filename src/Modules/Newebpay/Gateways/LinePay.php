<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class LinePay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_linepay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'LINEPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 LINE Pay', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '使用 LINE Pay 付款，跳轉至藍新付款頁完成。', 'mo-ectools' );
	}
}
