<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Smilepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Ibon extends AbstractSmilepayGateway {

	public const GATEWAY_ID = 'mo_smilepay_ibon';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_zg(): string {
		return '4';
	}

	protected function redirect_flow(): bool {
		return false;
	}

	protected function min_amount(): int {
		return 30;
	}

	protected function max_amount(): int {
		return 20000;
	}

	protected function build_method_title(): string {
		return __( 'SmilePay ibon 代碼繳費', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '取得繳費代碼後，於 7-11 ibon 或 LifeET 列印繳費單繳款。', 'mo-ectools' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'mo_smilepay_ibon_deadline_days', 6 );
		$days = max( 1, min( 6, $days ) );
		return [ 'Deadline_date' => gmdate( 'Y/m/d', strtotime( "+{$days} days" ) ?: time() ) ];
	}
}
