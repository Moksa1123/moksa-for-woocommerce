<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class TaiwanPay extends AbstractNewebpayGateway {

	public function __construct() {
		$this->id = 'moksafowo_newebpay_taiwanpay';
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		return [ 'TAIWANPAY' => 1 ];
	}

	protected function build_method_title(): string {
		return __( '藍新 台灣 Pay', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '台灣 Pay QR Code 行動支付，跳轉至藍新付款頁完成。', 'mo-ectools' );
	}
}
