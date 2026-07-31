<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Email;

use Moksafowo\Modules\Shared\Frontend\PaymentInfoBox;

defined( 'ABSPATH' ) || exit;


final class PaymentInfoEmail extends \WC_Email {

	public function __construct() {
		$this->id             = 'moksafowo_payment_info';
		$this->customer_email = true;
		$this->title          = __( 'Moksa payment code notification', 'moksa-for-woocommerce' );
		$this->description    = __( 'For payment codes such as ATM virtual accounts, convenience store codes and barcodes: emails the payment details to the customer after checkout, separately from the standard WooCommerce order email.', 'moksa-for-woocommerce' );
		$this->heading        = __( 'Please complete your payment', 'moksa-for-woocommerce' );
		/* translators: %s: site title */
		$this->subject = __( '[{site_title}] Payment details for order {order_number}', 'moksa-for-woocommerce' );

		$this->template_html  = '';
		$this->template_plain = '';

		// 取號資訊擷取完成時觸發。
		add_action( 'moksafowo_payment_info_email', [ $this, 'trigger' ], 10, 1 );

		parent::__construct();
	}

	public function get_default_subject(): string {
		return $this->subject;
	}

	public function get_default_heading(): string {
		return $this->heading;
	}


	public function trigger( $order_id ): void {
		$this->setup_locale();

		$order = $order_id ? wc_get_order( (int) $order_id ) : null;
		if ( $order instanceof \WC_Order ) {
			$this->object    = $order;
			$this->recipient = $order->get_billing_email();
			// 沒有取號資訊就不寄（避免對信用卡 / COD 訂單誤發）。
			if ( empty( PaymentInfoBox::rows( $order ) ) ) {
				$this->restore_locale();
				return;
			}
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	public function get_content_html(): string {
		$rows = $this->object instanceof \WC_Order ? PaymentInfoBox::rows( $this->object ) : [];
		ob_start();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_get_template_html returns escaped WC template content.
		echo wc_get_template_html(
			'emails/email-header.php',
			[
				'email_heading' => $this->get_heading(),
				'email'         => $this,
			]
		);
		echo '<p>' . esc_html__( 'Hi, your order has been placed. Please use the details below to pay before the deadline:', 'moksa-for-woocommerce' ) . '</p>';
		echo wp_kses( PaymentInfoBox::render_html( $rows ), PaymentInfoBox::kses_allowlist() );
		if ( $this->object instanceof \WC_Order ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_get_template_html returns escaped WC template content.
			echo wc_get_template_html(
				'emails/email-order-details.php',
				[
					'order'         => $this->object,
					'sent_to_admin' => false,
					'plain_text'    => false,
					'email'         => $this,
				]
			);
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_get_template_html returns escaped WC template content.
		echo wc_get_template_html( 'emails/email-footer.php', [] );
		return (string) ob_get_clean();
	}

	public function get_content_plain(): string {
		$rows  = $this->object instanceof \WC_Order ? PaymentInfoBox::rows( $this->object ) : [];
		$lines = [ wp_strip_all_tags( $this->get_heading() ), '', __( 'Please pay before the deadline:', 'moksa-for-woocommerce' ) ];
		foreach ( $rows as $row ) {
			if ( '' !== ( $row['value'] ?? '' ) ) {
				$lines[] = ( $row['label'] ?? '' ) . '：' . $row['value'];
			}
		}
		return implode( "\n", $lines ) . "\n";
	}
}
