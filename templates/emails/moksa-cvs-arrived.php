<?php
/**
 * moksa-cvs-arrived HTML email — 包裹到店待取通知。
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

$moksafowo_store_name    = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_NAME );
$moksafowo_store_id      = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_ID );
$moksafowo_store_address = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_ADDRESS );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
/* translators: %s: customer first name */
printf( esc_html__( 'Hi %s,', 'moksa-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) );
?>
</p>

<p><strong><?php esc_html_e( 'Your parcel has arrived at the pickup store. Please bring your ID and collect it within 7 days.', 'moksa-for-woocommerce' ); ?></strong></p>

<?php if ( $moksafowo_store_name || $moksafowo_store_id || $moksafowo_store_address ) : ?>
<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin-bottom:16px;">
	<?php if ( $moksafowo_store_name ) : ?>
		<tr><th align="left"><?php esc_html_e( 'Pickup store', 'moksa-for-woocommerce' ); ?></th><td><?php echo esc_html( $moksafowo_store_name ); ?></td></tr>
	<?php endif; ?>
	<?php if ( $moksafowo_store_id ) : ?>
		<tr><th align="left"><?php esc_html_e( 'Store number', 'moksa-for-woocommerce' ); ?></th><td><?php echo esc_html( $moksafowo_store_id ); ?></td></tr>
	<?php endif; ?>
	<?php if ( $moksafowo_store_address ) : ?>
		<tr><th align="left"><?php esc_html_e( 'Store address', 'moksa-for-woocommerce' ); ?></th><td><?php echo esc_html( $moksafowo_store_address ); ?></td></tr>
	<?php endif; ?>
</table>
<?php endif; ?>

<p><?php esc_html_e( 'Parcels not collected in time are returned to the distribution center, and a return shipping fee may apply.', 'moksa-for-woocommerce' ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
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
