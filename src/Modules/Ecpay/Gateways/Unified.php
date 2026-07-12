<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Unified extends AbstractEcpayGateway {

	public const GATEWAY_ID = 'moksafowo_ecpay_unified';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function choose_payment(): string {
		return 'ALL';
	}

	protected function build_method_title(): string {
		// 簡短版 — 跟 WC 自動 note「透過 [title] 付款」連讀比較順。
		return __( '綠界', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '跳轉至綠界收銀台，由顧客選擇信用卡、ATM、超商代碼、超商條碼、WebATM、Apple Pay、TWQR 等付款方式。', 'mo-ectools' );
	}

	protected function supports_credit_action(): bool {
		return true;
	}
}
