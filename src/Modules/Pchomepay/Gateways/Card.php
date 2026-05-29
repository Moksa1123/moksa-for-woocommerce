<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay\Gateways;

defined( 'ABSPATH' ) || exit;

final class Card extends AbstractPchomepayGateway {

	public const GATEWAY_ID = 'mo_pchomepay_card';

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
		return __( '支付連 信用卡', 'mo-ectools' );
	}

	protected function build_method_description(): string {
		return __( '跳轉至支付連支付頁，使用信用卡（含分期）完成付款。', 'mo-ectools' );
	}

	protected function extra_params( \WC_Order $order ): array {
		$inst = trim( (string) get_option( 'mo_pchomepay_card_installment', '' ) );
		if ( '' === $inst ) {
			return [];
		}
		$inst = preg_replace( '/[^0-9,]/', '', $inst ) ?? '';
		return '' !== $inst ? [ 'card_installment' => $inst ] : [];
	}
}
