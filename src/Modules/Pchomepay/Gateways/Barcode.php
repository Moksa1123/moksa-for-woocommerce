<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Pchomepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Barcode extends AbstractPchomepayGateway {

	public const GATEWAY_ID = 'moksafowo_pchomepay_barcode';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_types(): array {
		return [ 'BCODE' ];
	}

	protected function min_amount(): int {
		return 25;
	}

	protected function max_amount(): int {
		return 20000;
	}

	protected function build_method_title(): string {
		return __( '支付連 超商代碼繳費', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '取得繳費代碼後，至超商（ibon / FamiPort）完成付款。', 'mo-ectools' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$days = (int) get_option( 'moksafowo_pchomepay_bcode_expire_days', 7 );
		$days = max( 1, min( 7, $days ) );
		return [ 'bcode_info' => [ 'expire_days' => $days ] ];
	}
}
