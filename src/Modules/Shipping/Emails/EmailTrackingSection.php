<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Emails;

defined( 'ABSPATH' ) || exit;

final class EmailTrackingSection {

	public static function init(): void {
		add_action( 'moksafowo_shipping_email_tracking_info', [ __CLASS__, 'render' ], 10, 2 );
		// 區塊信件編輯器不跑我們的 PHP 範本，它渲染的是 WooCommerce 共用的
		// emails/block/general-block-email.php，物流追蹤要改掛在它留的擴充點上，
		// 否則新介面寄出的信會少掉貨態與物流編號。
		add_action( 'woocommerce_email_general_block_content', [ __CLASS__, 'render_for_block_email' ], 10, 3 );
	}

	/**
	 * @param bool      $sent_to_admin 是否寄給管理員。
	 * @param bool      $plain_text    是否為純文字。
	 * @param \WC_Email $email         信件物件。
	 */
	public static function render_for_block_email( $sent_to_admin, $plain_text, $email ): void {
		if ( ! $email instanceof AbstractShippingEmail ) {
			return;
		}
		self::render( $email->object ?? null, (bool) $plain_text );
	}

	public static function render( $order, bool $plain_text = false ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$entries = self::collect_entries( $order );
		if ( empty( $entries ) ) {
			return;
		}

		if ( $plain_text ) {
			self::render_plain( $entries );
		} else {
			self::render_html( $entries );
		}
	}


	private static function collect_entries( \WC_Order $order ): array {
		$method_id = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$method_id = (string) $m->get_method_id();
			break;
		}
		if ( '' === $method_id ) {
			return [];
		}

		$entries = apply_filters( 'moksafowo_shipping_tracking_entries', [], $order, $method_id );
		return is_array( $entries ) ? $entries : [];
	}

	private static function render_html( array $entries ): void {
		echo '<h2 style="color:#1f2937;font-size:18px;margin:24px 0 10px;">' . esc_html__( 'Track shipment', 'moksa-for-woocommerce' ) . '</h2>';
		echo '<table cellspacing="0" cellpadding="8" border="1" style="width:100%;border-collapse:collapse;border-color:#e5e7eb;margin-bottom:16px;">';
		echo '<thead><tr style="background:#f8fafc;">';
		echo '<th align="left">' . esc_html__( 'Carrier', 'moksa-for-woocommerce' ) . '</th>';
		echo '<th align="left">' . esc_html__( 'Tracking number', 'moksa-for-woocommerce' ) . '</th>';
		echo '<th align="left">' . esc_html__( 'Tracking link', 'moksa-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $entries as $e ) {
			$is_direct = 'direct' === $e['mode'];
			$carrier   = '' !== $e['temp_label'] ? $e['carrier'] . '（' . $e['temp_label'] . '）' : $e['carrier'];
			$link_text = $is_direct
				? __( 'Track now', 'moksa-for-woocommerce' )
				: __( 'Open tracking page', 'moksa-for-woocommerce' );
			echo '<tr>';
			echo '<td>' . esc_html( $carrier ) . '</td>';
			echo '<td style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;">' . esc_html( $e['tracking_no'] ) . '</td>';
			echo '<td><a href="' . esc_url( $e['url'] ) . '" target="_blank" rel="noopener noreferrer" style="color:#1d4ed8;">' . esc_html( $link_text ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p style="font-size:12px;color:#64748b;margin:-8px 0 16px;">' . esc_html__( 'Note: T-Cat supports one-click tracking. For other carriers, copy the tracking number and paste it on the carrier’s own website.', 'moksa-for-woocommerce' ) . '</p>';
	}

	private static function render_plain( array $entries ): void {
		echo "\n= " . esc_html__( 'Track shipment', 'moksa-for-woocommerce' ) . " =\n\n";
		foreach ( $entries as $e ) {
			$carrier = '' !== $e['temp_label'] ? $e['carrier'] . '（' . $e['temp_label'] . '）' : $e['carrier'];
			echo esc_html( $carrier ) . " — \n";
			if ( '' !== $e['tracking_no'] ) {
				echo '  ' . esc_html__( 'Tracking number:', 'moksa-for-woocommerce' ) . esc_html( $e['tracking_no'] ) . "\n";
			}
			echo '  ' . esc_html__( 'Track:', 'moksa-for-woocommerce' ) . esc_url( $e['url'] ) . "\n\n";
		}
	}
}
