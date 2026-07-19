<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow\Gateways;

defined( 'ABSPATH' ) || exit;

final class Ibon extends AbstractPaynowGateway {

	public const GATEWAY_ID = 'moksafowo_paynow_ibon';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_type(): string {
		return '05';
	}

	protected function code_type(): string {
		return '0';
	}

	protected function build_method_title(): string {
		return __( 'PayNow ibon 代碼繳費', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( '取得繳費代碼後，至 7-11 ibon 機台列印繳費單付款。', 'moksa-for-woocommerce' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'moksafowo_paynow_code_deadline_days', 0 );
		return $days > 0 ? [ 'DeadLine' => $days ] : [];
	}
}
