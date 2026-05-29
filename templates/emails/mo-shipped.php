<?php
/**
 * mo-shipped HTML email — 已出貨通知。
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
/* translators: %s: customer first name */
printf( esc_html__( '%s 您好，', 'mo-ectools' ), esc_html( $order->get_billing_first_name() ) );
?></p>

<p><?php esc_html_e( '您的訂單已出貨，物流商正在處理運送中。可至會員中心查看出貨單編號與物流狀態。', 'mo-ectools' ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
do_action( 'mo_shipping_email_tracking_info', $order, false );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_footer', $email );
