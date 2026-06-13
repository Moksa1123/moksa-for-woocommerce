<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay\Gateways;

use MoksaWeb\Mowc\Modules\Linepay\Api\PaymentRequest;
use MoksaWeb\Mowc\Modules\Linepay\Constants;
use MoksaWeb\Mowc\Modules\Linepay\LinePay;
use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

class Credit extends WC_Payment_Gateway {

	public $payment_type;
	public $supported_currencies;

	public function __construct() {

		$this->id                 = Constants::ID;
		$this->icon               = $this->get_icon();
		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		$this->has_fields         = false;
		$this->method_title       = __( 'LINE Pay', 'mo-ectools' );
		$this->method_description = __( '使用 LINE Pay 行動支付完成結帳。', 'mo-ectools' );

		$this->payment_type = 'NORMAL';

		$this->supports = array(
			'products',
			'refunds',
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Moksafowo_LinePay_ is wpbrewer fork BC prefix per CLAUDE.md fork-then-patch.
		$this->supported_currencies = apply_filters( 'Moksafowo_LinePay_support_currencies', array( 'TWD' ) );

		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'thankyou_order_on_hold_message' ), 10, 2 );
		add_action( 'woocommerce_order_details_before_order_table', array( $this, 'display_on_hold_message_on_order_details' ) );
	}

	public function get_icon() {

		$icon_html = '';
		if ( 'yes' === get_option( 'Moksafowo_LinePay_display_logo_enabled' ) ) {
			$icon_html .= sprintf(
				'<img src="%s" alt="%s" />',
				esc_url( MOKSAFOWO_PLUGIN_URL . 'src/Modules/Linepay/assets/images/linepay-logo.png' ),
				esc_attr__( 'LINE Pay Taiwan', 'mo-ectools' )
			);
		}

		return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC core convention extension point.
	}

	public function init_form_fields(): void {
		$this->form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Linepay/Settings/settings-fields.php';
	}

	public function process_payment( $order_id ) {

		WC()->cart->empty_cart();

		$linepay_request = new PaymentRequest( $this );
		return $linepay_request->request( $order_id );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$linepay_request = new PaymentRequest( $this );
		return $linepay_request->refund( $order_id, $amount, $reason );
	}

	public function is_available() {
		$is_available = ( 'yes' === $this->enabled );

		$channel_info = LinePay::get_channel_info();
		if ( empty( $channel_info['channel_id'] ) || empty( $channel_info['channel_secret'] ) ) {
			$is_available = false;
		}

		$cur_currency = get_woocommerce_currency();
		if ( ! in_array( $cur_currency, $this->supported_currencies, true ) ) {
			$is_available = false;
		}

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->max_amount && $this->max_amount < $this->get_order_total() ) {
			$is_available = false;
		}

		return $is_available;
	}

	public function thankyou_order_on_hold_message( $text, $order ) {

		if ( $order ) {
			if ( $order->get_payment_method() !== $this->id ) {
				return $text;
			}

			if ( 'on-hold' === $order->get_status() ) {
				$text = esc_html__( 'We have received your order, but the payment status need to be confirmed. Please contact the support.', 'mo-ectools' );
			}

			if ( 'pending' === $order->get_status() ) {
				$text = esc_html__( 'We have received your order, but the order is awaiting payment. Please pay again.', 'mo-ectools' );
			}
		}

		return $text;
	}

	public function display_on_hold_message_on_order_details( $order ): void {

		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		if ( 'on-hold' === $order->get_status() ) {
			echo '<p class="moksafowo-linepay-status-note">' . esc_html__( 'We have received your order, but the payment status need to be confirmed. Please contact the support.', 'mo-ectools' ) . '</p>';
		}

		if ( 'pending' === $order->get_status() ) {
			echo '<p class="moksafowo-linepay-status-note">' . esc_html__( 'We have received your order, but the order is awaiting payment. Please pay again.', 'mo-ectools' ) . '</p>';
		}
	}
}
