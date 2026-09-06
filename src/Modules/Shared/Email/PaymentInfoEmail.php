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
		// WC 的區塊信件編輯器預設是拿 template_plain 把 'plain' 換成 'block' 推導出
		// 區塊範本名；這封信沒有純文字範本，推導不出來，所以明確指定。
		$this->template_block = 'emails/block/moksafowo-payment-info.php';
		$this->template_base  = MOKSAFOWO_PLUGIN_DIR . 'templates/';

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
		$this->render_additional_content_html();
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
		$extra = (string) $this->get_additional_content();
		if ( '' !== trim( $extra ) ) {
			$lines[] = '';
			$lines[] = wp_strip_all_tags( wptexturize( $extra ) );
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * 商家在信件設定頁填的「額外內容」。核心每封信都印這一段，這封原本漏了，
	 * 商家編輯了不會有任何效果。
	 */
	private function render_additional_content_html(): void {
		$extra = (string) $this->get_additional_content();
		if ( '' === trim( $extra ) ) {
			return;
		}
		echo wp_kses_post( wpautop( wptexturize( $extra ) ) );
	}

	/**
	 * 區塊信件編輯器 email-content 佔位符要填的內容。
	 *
	 * 預設實作渲染的是 WooCommerce 的 emails/block/general-block-email.php，
	 * 那份只處理訂單明細；這封信的主體是取號表格，所以自己組。
	 */
	public function get_block_editor_email_template_content() {
		$rows = $this->object instanceof \WC_Order ? PaymentInfoBox::rows( $this->object ) : [];
		ob_start();
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
		return (string) ob_get_clean();
	}
}
