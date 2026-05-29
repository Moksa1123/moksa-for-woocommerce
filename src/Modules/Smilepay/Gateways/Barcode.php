<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Smilepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Barcode extends AbstractSmilepayGateway {

	public const GATEWAY_ID = 'mo_smilepay_barcode';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_zg(): string {
		return '3';
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
		return __( 'SmilePay 四大超商條碼', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '取得繳費條碼後，於期限內至四大超商繳款。', 'mo-ectools' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'mo_smilepay_barcode_deadline_days', 7 );
		$days = max( 1, min( 50, $days ) );
		return [ 'Deadline_date' => gmdate( 'Y/m/d', strtotime( "+{$days} days" ) ?: time() ) ];
	}
}
