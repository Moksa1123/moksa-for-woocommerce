<?php
namespace Moksafowo\Modules\Payuni\Gateways;

use Moksafowo\Modules\Payuni\Api\PaymentRequest;
use Moksafowo\Modules\Payuni\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

trait TraitCreditInstallment {

	public $installs;

	public function init_installment( $installs, $min_amount ) {

		$this->supports = array(
			'products',
			'refunds',
		);

		$this->set_installs( $installs );
		$this->min_amount = $min_amount;
	}
	private function set_installs( $installs ) {
		$this->installs = $installs;
	}

	public function set_min_amount( $amount ) {
		$this->min_amount = $amount;
	}

	public function init_form_fields() {
		$this->form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Payuni/Settings/CreditInstallmentSetting.php';
		/* translators: %s: number of installments */
		$this->form_fields['title']['default'] = sprintf( __( 'PAYUNi credit card, %s instalments', 'moksa-for-woocommerce' ), $this->installs );
	}

	public function is_available() {
		$is_available = ( 'yes' === $this->enabled );

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->max_amount && $this->max_amount < $this->get_order_total() ) {
			$is_available = false;
		}

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->min_amount && $this->min_amount > $this->get_order_total() ) {
			$is_available = false;
		}

		return $is_available;
	}

	public function moksafowo_payuni_payment_installment_transaction_arrgs( $args, $order ) {
		return array_merge(
			$args,
			array(
				'CreditInst' => $this->installs,
			)
		);
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$request = new PaymentRequest();
		return $request->refund( $order_id, $amount, $reason );
	}

	public static function get_payment_order_metas() {
		$order_metas =
		array(
			OrderMeta::CREDIT_AUTH_TYPE => __( 'Authorization method', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_CARD_4NO  => __( 'Last four card digits', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_INSTALL   => __( 'Number of instalments', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_FIRST_AMT => __( 'First instalment', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_EACH_AMT  => __( 'Amount per instalment', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_AUTH_DAY  => __( 'Authorization date', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_AUTH_TIME => __( 'Authorized at', 'moksa-for-woocommerce' ),
		);

		return $order_metas;
	}
}
