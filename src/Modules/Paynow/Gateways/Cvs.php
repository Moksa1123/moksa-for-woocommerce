<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Paynow\Gateways;

defined( 'ABSPATH' ) || exit;

final class Cvs extends AbstractPaynowGateway {

	public const GATEWAY_ID = 'mo_paynow_cvs';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_type(): string {
		return '10';
	}

	protected function build_method_title(): string {
		return __( 'PayNow 超商條碼繳費', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '取得超商條碼後，至 7-11 / 全家 / 萊爾富 / OK 出示繳費。', 'mo-ectools' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'mo_paynow_cvs_deadline_days', 0 );
		return $days > 0 ? [ 'DeadLine' => $days ] : [];
	}
}
