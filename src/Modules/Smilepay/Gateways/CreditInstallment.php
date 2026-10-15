<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class CreditInstallment extends AbstractSmilepayGateway {

	public const GATEWAY_ID = 'moksafowo_smilepay_credit_installment';

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
		return __( 'SmilePay credit card instalments', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'The customer is redirected to SmilePay to pay by card in instalments.', 'moksa-for-woocommerce' );
	}

	protected function mtmk_extra_params( \WC_Order $order ): array {
		// 跟設定欄位同一個預設；取清單裡第一個大於 0 的期數，都不合法時用 3 期。
		$raw = (string) get_option( 'moksafowo_smilepay_installment', '3' );
		$raw = preg_replace( '/[^0-9,]/', '', $raw ) ?? '';
		foreach ( explode( ',', $raw ) as $stage ) {
			if ( (int) $stage > 0 ) {
				return [ 'Stage' => (int) $stage ];
			}
		}
		return [ 'Stage' => 3 ];
	}
}
