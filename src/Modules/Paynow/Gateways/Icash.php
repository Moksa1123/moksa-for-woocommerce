<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Paynow\Gateways;

defined( 'ABSPATH' ) || exit;

final class Icash extends AbstractPaynowGateway {

	public const GATEWAY_ID = 'mo_paynow_icash';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_type(): string {
		return '05';
	}

	protected function code_type(): string {
		return '2';
	}

	protected function build_method_title(): string {
		return __( 'PayNow iCash 錢包', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '跳轉至 iCash Pay 完成付款。', 'mo-ectools' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'mo_paynow_code_deadline_days', 0 );
		return $days > 0 ? [ 'DeadLine' => $days ] : [];
	}
}
