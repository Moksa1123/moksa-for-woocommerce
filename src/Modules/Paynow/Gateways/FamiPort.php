<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Paynow\Gateways;

defined( 'ABSPATH' ) || exit;

final class FamiPort extends AbstractPaynowGateway {

	public const GATEWAY_ID = 'mo_paynow_famiport';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_type(): string {
		return '05';
	}

	protected function code_type(): string {
		return '1';
	}

	protected function build_method_title(): string {
		return __( 'PayNow FamiPort 代碼繳費', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '取得繳費代碼後，至全家 FamiPort 機台列印繳費單付款。', 'mo-ectools' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'mo_paynow_code_deadline_days', 0 );
		return $days > 0 ? [ 'DeadLine' => $days ] : [];
	}
}
