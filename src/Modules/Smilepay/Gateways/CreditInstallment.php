<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Smilepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class CreditInstallment extends AbstractSmilepayGateway {

	public const GATEWAY_ID = 'mo_smilepay_credit_installment';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_zg(): string {
		return '1';
	}

	protected function redirect_flow(): bool {
		return true;
	}

	protected function min_amount(): int {
		return 1;
	}

	protected function build_method_title(): string {
		return __( 'SmilePay 信用卡分期', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '跳轉至 SmilePay 完成信用卡分期付款。', 'mo-ectools' );
	}

	protected function mtmk_extra_params( \WC_Order $order ): array {
		$raw = (string) get_option( 'mo_smilepay_installment', '' );
		$raw = preg_replace( '/[^0-9,]/', '', $raw ) ?? '';
		if ( '' === $raw ) {
			return [];
		}
		$first = (int) explode( ',', $raw )[0];
		return $first > 0 ? [ 'Stage' => $first ] : [];
	}
}
