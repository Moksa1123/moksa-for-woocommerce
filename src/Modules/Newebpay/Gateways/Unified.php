<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Unified extends AbstractNewebpayGateway {

	public const GATEWAY_ID = 'moksafowo_newebpay_unified';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function payment_type_flags(): array {
		// 全部開 = 顧客在藍新付款頁自選付款方式（17 種全開）
		// AFTEE / UNIONPAY 須商家於藍新後台開通才會顯示，否則藍新自動 hide。
		return [
			'CREDIT'     => 1,
			'WEBATM'     => 1,
			'VACC'       => 1,
			'CVS'        => 1,
			'BARCODE'    => 1,
			'APPLEPAY'   => 1,
			'ANDROIDPAY' => 1,
			'SAMSUNGPAY' => 1,
			'LINEPAY'    => 1,
			'ESUNWALLET' => 1,
			'TAIWANPAY'  => 1,
			'TWQR'       => 1,
			'EZPALIPAY'  => 1,
			'EZPWECHAT'  => 1,
			'AFTEE'      => 1,
			'UNIONPAY'   => 1,
			'InstFlag'   => '0,3,6,12,18,24',
		];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Redirect to the NewebPay checkout, where the customer picks a credit card, instalments, ATM transfer, a convenience store code, Apple Pay, LINE Pay or another method.', 'moksa-for-woocommerce' );
	}
}
