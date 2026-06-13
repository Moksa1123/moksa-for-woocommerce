<?php
/**
 * moksa-store-closed HTML email — 門市關轉，催顧客重選。
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_store_name = (string) $order->get_meta( '_moksafowo_shipping_cvs_store_name' );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php /* translators: %s: customer first name */ ?>
<p><?php printf( esc_html__( '%s 您好，', 'mo-ectools' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<p><strong><?php
/* translators: %s: store name */
printf( esc_html__( '您原先選擇的取件門市「%s」目前暫停服務（門市裝修／搬遷／改店號），請儘速重新選擇可配達的取件門市。', 'mo-ectools' ), esc_html( $mo_store_name ?: __( '未知門市', 'mo-ectools' ) ) );
?></strong></p>

<p><?php esc_html_e( '逾期未重選，包裹將退回物流中心。請至「我的帳戶 > 訂單」進入此訂單頁面點擊重選門市，或聯絡客服協助。', 'mo-ectools' ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_footer', $email );
