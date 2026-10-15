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

	/**
	 * 合併模式也套用繳費期限。藍新只有一個 ExpireDate，ATM／超商代碼／條碼共用，
	 * 取三者中最短的天數，才不會超過超商代碼的上限。
	 */
	protected function extra_params( \WC_Order $order ): array {
		$days = min(
			(int) get_option( 'moksafowo_newebpay_atm_expire_days', 3 ),
			(int) get_option( 'moksafowo_newebpay_cvs_expire_days', 7 ),
			(int) get_option( 'moksafowo_newebpay_barcode_expire_days', 7 )
		);
		$days = max( 1, min( 180, $days ) );
		// 繳費期限是台灣日期；用 UTC 算，台灣凌晨 0～8 點下單會少一天。
		return [ 'ExpireDate' => ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Taipei' ) ) )->modify( "+{$days} days" )->format( 'Ymd' ) ];
	}

	protected function build_method_title(): string {
		return __( 'NewebPay', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Redirect to the NewebPay checkout, where the customer picks a credit card, instalments, ATM transfer, a convenience store code, Apple Pay, LINE Pay or another method.', 'moksa-for-woocommerce' );
	}
}
