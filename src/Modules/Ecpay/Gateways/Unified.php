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
		return __( 'ECPay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Redirect to the ECPay checkout, where the customer picks a credit card, ATM transfer, convenience store code or barcode, WebATM, Apple Pay, TWQR or another method.', 'moksa-for-woocommerce' );
	}

	protected function supports_credit_action(): bool {
		return true;
	}
}
