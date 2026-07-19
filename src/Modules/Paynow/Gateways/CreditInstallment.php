<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow\Gateways;

defined( 'ABSPATH' ) || exit;

final class CreditInstallment extends AbstractPaynowGateway {

	public const GATEWAY_ID = 'moksafowo_paynow_credit_installment';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_type(): string {
		return '11';
	}

	protected function build_method_title(): string {
		return __( 'PayNow 信用卡分期', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( '跳轉至 PayNow 付款頁，使用信用卡分期付款。', 'moksa-for-woocommerce' );
	}
}
