<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments\Gateways;

use Moksafowo\Modules\ShoplinePayments\Api\Client;
use Moksafowo\Modules\ShoplinePayments\Api\Helper;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class SessionGateway extends \WC_Payment_Gateway {

	use TradePayload;

	public const GATEWAY_ID = 'moksafowo_shopline_payments';

	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Shopline Payments', 'moksa-for-woocommerce' );
		$this->method_description = __( 'Redirect to the hosted Shopline Payments checkout, which supports credit cards, Apple Pay, Google Pay, LINE Pay, JKOPAY and more.', 'moksa-for-woocommerce' );
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title', $this->method_title );
		$this->description = (string) $this->get_option( 'description', '' );
		$this->enabled     = (string) $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields(): void {
		$this->form_fields = [
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

	public function is_available(): bool {
		if ( ! parent::is_available() || ! \Moksafowo\Modules\Shared\Gateways\TwdOnly::ok() ) {
			return false;
		}
		return Helper::has_credentials();
	}


	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( esc_html__( 'The order could not be found', 'moksa-for-woocommerce' ) );
		}

		// 曾送過（失敗重試）→ referenceId 加時間後綴避免 SLP 端撞號。
		$retry        = '' !== (string) $order->get_meta( Keys::SLP_REFERENCE_ID );
		$reference_id = Helper::build_reference_id( (int) $order_id, $retry );

		// allowPaymentMethodList 為 SLP 必填 — 留空時 fallback 信用卡。
		$allowed = Helper::allowed_payment_methods();
		if ( $allowed === [] ) {
			$allowed = [ 'CreditCard' ];
		}

		$body = [
			'referenceId'            => $reference_id,
			'amount'                 => [
				// ×100：先 round WC total（避免浮點尾差）再轉整數 cents。TWD 仍 ×100。
				'value'    => (int) round( (float) $order->get_total() * 100 ),
				'currency' => 'TWD',
			],
			'returnUrl'              => $order->get_checkout_order_received_url(),
			'mode'                   => 'regular',
			'allowPaymentMethodList' => $allowed,
			'order'                  => $this->build_order( $order ),
			'billing'                => $this->build_billing( $order ),
			'customer'               => $this->build_customer( $order ),
			'client'                 => $this->build_client(),
		];

		$resp        = Client::create_session( $body );
		$data        = $resp['data'];
		$session_id  = (string) ( $data['sessionId'] ?? '' );
		$session_url = (string) ( $data['sessionUrl'] ?? '' );

		Helper::log(
			'session create',
			[
				'order_id'     => $order_id,
				'reference_id' => $reference_id,
				'ok'           => $resp['ok'],
				'code'         => $resp['code'],
				'session_id'   => $session_id,
			]
		);

		if ( ! $resp['ok'] || '' === $session_url ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: error message */
					__( 'The Shopline Payments payment could not be created: %s', 'moksa-for-woocommerce' ),
					$resp['message']
				),
				'error'
			);
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}

		$order->update_meta_data( Keys::SLP_REFERENCE_ID, $reference_id );
		$order->update_meta_data( Keys::SLP_SESSION_ID, $session_id );
		$order->update_meta_data( Keys::SLP_SESSION_URL, $session_url );
		$order->update_meta_data( Keys::SLP_STATUS, (string) ( $data['status'] ?? 'CREATED' ) );
		$order->update_status( 'pending', __( 'Waiting for the customer to pay at Shopline Payments.', 'moksa-for-woocommerce' ) );
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => $session_url,
		];
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return $this->slp_refund( $order_id, $amount, (string) $reason );
	}
}
