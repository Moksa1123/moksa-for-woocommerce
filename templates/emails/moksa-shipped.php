<?php
/**
 * moksa-shipped HTML email — 已出貨通知。
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
/* translators: %s: customer first name */
printf( esc_html__( 'Hi %s,', 'moksa-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) );
?></p>

<p><?php esc_html_e( 'Your order has shipped and is on its way. You can view the tracking number and shipping status in your account.', 'moksa-for-woocommerce' ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
do_action( 'moksafowo_shipping_email_tracking_info', $order, false );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * 商家在信件設定頁填的「額外內容」。核心範本都印這一段，我們原本漏了，
 * 導致商家編輯了卻什麼都不會出現。
 */
if ( ! empty( $additional_content ) ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
