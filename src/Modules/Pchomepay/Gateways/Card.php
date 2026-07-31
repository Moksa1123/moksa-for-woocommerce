<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Pchomepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Card extends AbstractPchomepayGateway {

	public const GATEWAY_ID = 'moksafowo_pchomepay_card';

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function pay_types(): array {
		return [ 'CARD' ];
	}

	protected function min_amount(): int {
		return 30;
	}

	protected function max_amount(): int {
		return 199999;
	}

	protected function build_method_title(): string {
		return __( 'PChomePay credit card', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'The customer is redirected to PChomePay to pay by credit card, with instalments available.', 'moksa-for-woocommerce' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$inst = trim( (string) get_option( 'moksafowo_pchomepay_card_installment', '' ) );
		if ( '' === $inst ) {
			return [];
		}
		$inst = preg_replace( '/[^0-9,]/', '', $inst ) ?? '';
		return '' !== $inst ? [ 'card_installment' => $inst ] : [];
	}
}
