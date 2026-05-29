<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Emails;

use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

abstract class AbstractShippingEmail extends \WC_Email {

	abstract protected function get_status_slug(): string;

	public function __construct() {
		$slug = $this->get_status_slug();

		$this->id             = 'customer_' . str_replace( '-', '_', $slug );
		$this->customer_email = true;
		$this->template_html  = 'emails/' . $slug . '.php';
		$this->template_plain = 'emails/plain/' . $slug . '.php';
		$this->template_base  = MOWC_PLUGIN_DIR . 'templates/';

		$this->placeholders = [
			'{site_title}'   => $this->get_blogname(),
			'{order_date}'   => '',
			'{order_number}' => '',
			'{store_name}'   => '',
			'{store_id}'     => '',
		];

		parent::__construct();

		add_action( 'mo_shipping_status_' . $slug . '_notification', [ $this, 'trigger' ], 10, 2 );
	}

	public function trigger( $order_id, $order = false ): void {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! is_a( $order, 'WC_Order' ) ) {
			$this->restore_locale();
			return;
		}

		$this->object                          = $order;
		$this->recipient                       = $order->get_billing_email();
		$this->placeholders['{order_date}']    = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}']  = $order->get_order_number();
		$this->placeholders['{store_name}']    = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_NAME );
		$this->placeholders['{store_id}']      = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send(
				$this->get_recipient(),
				$this->get_subject(),
				$this->get_content(),
				$this->get_headers(),
				$this->get_attachments()
			);
		}

		$this->restore_locale();
	}

	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			[
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => false,
				'plain_text'    => false,
				'email'         => $this,
			],
			'',
			$this->template_base
		);
	}

	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			[
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => false,
				'plain_text'    => true,
				'email'         => $this,
			],
			'',
			$this->template_base
		);
	}
}
