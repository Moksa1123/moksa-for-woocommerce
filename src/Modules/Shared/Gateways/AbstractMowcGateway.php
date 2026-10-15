<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Gateways;

defined( 'ABSPATH' ) || exit;

abstract class AbstractMowcGateway extends \WC_Payment_Gateway {

	public function __construct() {
		$this->has_fields         = false;
		$this->method_title       = $this->build_method_title();
		$this->method_description = $this->build_method_description();
		$this->supports           = $this->gateway_supports();

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title', $this->method_title );
		$this->description = (string) $this->get_option( 'description', '' );
		$this->enabled     = (string) $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		$this->register_receipt_action();
	}

	abstract protected function build_method_title(): string;

	abstract protected function build_method_description(): string;

	protected function gateway_supports(): array {
		return [ 'products' ];
	}

	protected function register_receipt_action(): void {}

	public function init_form_fields(): void {
		$this->form_fields = $this->build_form_fields();
	}


	protected function build_form_fields(): array {
		return [
			'enabled'     => [
				'title'   => __( 'Enable this payment method', 'moksa-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'moksa-for-woocommerce' ),
				'type'        => 'text',
				'default'     => $this->method_title,
				'description' => __( 'The name customers see at checkout.', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'moksa-for-woocommerce' ),
				'type'        => 'textarea',
				'default'     => '',
				'description' => __( 'The payment method description shown at checkout.', 'moksa-for-woocommerce' ),
				'desc_tip'    => true,
			],
		];
	}

	protected function min_amount(): int {
		return 0;
	}

	protected function max_amount(): int {
		return 0;
	}

	protected function helper_has_credentials(): bool {
		return true;
	}

	public function is_available(): bool {
		if ( ! parent::is_available() || ! \Moksafowo\Modules\Shared\Gateways\TwdOnly::ok() ) {
			return false;
		}
		if ( ! $this->helper_has_credentials() ) {
			return false;
		}
		return $this->amount_in_range();
	}

	protected function amount_in_range(): bool {
		$min = $this->min_amount();
		$max = $this->max_amount();
		if ( 0 === $min && 0 === $max ) {
			return true;
		}
		// 付款頁（失敗後重付）要看那張訂單的金額，購物車通常是空的；get_order_total() 兩種情況都會處理。
		$total = (float) $this->get_order_total();
		if ( 0.0 === $total && ! WC()->cart ) {
			return true;
		}
		if ( $min > 0 && $total < $min ) {
			return false;
		}
		if ( $max > 0 && $total > $max ) {
			return false;
		}
		return true;
	}
}
