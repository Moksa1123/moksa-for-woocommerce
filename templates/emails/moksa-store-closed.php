<?php
/**
 * moksa-store-closed HTML email — 門市關轉，催顧客重選。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

$moksafowo_store_name = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_NAME );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php /* translators: %s: customer first name */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'moksa-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<p><strong>
<?php
/* translators: %s: store name */
printf( esc_html__( 'The pickup store you chose (%s) is temporarily closed for renovation, relocation or a change of store number. Please choose another pickup store as soon as possible.', 'moksa-for-woocommerce' ), esc_html( $moksafowo_store_name ?: __( 'Unknown store', 'moksa-for-woocommerce' ) ) );
?>
</strong></p>

<p><?php esc_html_e( 'If no new store is chosen in time, the parcel will be returned to the distribution center. Please reply to this email or contact us to choose another store.', 'moksa-for-woocommerce' ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
/**
 * 商家在信件設定頁填的「額外內容」。核心範本都印這一段，這裡也要印。
 */
if ( ! empty( $additional_content ) ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
