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
		return __( '財金公司 QR Code 跨行行動支付，金融卡 / 信用卡綁定。跳轉至藍新支付頁完成。', 'mo-ectools' );
	}
}
