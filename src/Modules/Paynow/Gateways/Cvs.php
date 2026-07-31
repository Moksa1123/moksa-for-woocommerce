<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow\Gateways;

defined( 'ABSPATH' ) || exit;

final class Cvs extends AbstractPaynowGateway {

	public const GATEWAY_ID = 'moksafowo_paynow_cvs';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_type(): string {
		return '10';
	}

	protected function build_method_title(): string {
		return __( 'PayNow convenience store barcode', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'Show the barcode at 7-ELEVEN, FamilyMart, Hi-Life or OK Mart to pay.', 'moksa-for-woocommerce' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'moksafowo_paynow_cvs_deadline_days', 0 );
		return $days > 0 ? [ 'DeadLine' => $days ] : [];
	}
}
