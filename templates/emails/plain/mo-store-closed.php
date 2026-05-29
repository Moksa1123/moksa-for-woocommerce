<?php
/**
 * mo-store-closed plain-text email.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_store_name = (string) $order->get_meta( '_mo_shipping_cvs_store_name' );

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: %s: customer first name */
echo sprintf( esc_html__( '%s 您好，', 'mo-ectools' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
echo sprintf(
	/* translators: %s: convenience store name */
	esc_html__( '您原先選擇的取件門市「%s」目前暫停服務（門市裝修／搬遷／改店號），請儘速重新選擇可配達的取件門市。', 'mo-ectools' ),
	esc_html( $mo_store_name ?: __( '未知門市', 'mo-ectools' ) )
) . "\n\n";
echo esc_html__( '逾期未重選，包裹將退回物流中心。請至「我的帳戶 > 訂單」進入此訂單頁面點擊重選門市，或聯絡客服協助。', 'mo-ectools' ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n----------------------------------------\n\n";
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_strip_all_tags returns plain text safe for plaintext email body.
echo "\n\n" . wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
