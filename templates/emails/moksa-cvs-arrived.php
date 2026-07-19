<?php
/**
 * moksa-cvs-arrived HTML email — 包裹到店待取通知。
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_store_name    = (string) $order->get_meta( '_moksafowo_shipping_cvs_store_name' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_store_id      = (string) $order->get_meta( '_moksafowo_shipping_cvs_store_id' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_store_address = (string) $order->get_meta( '_moksafowo_shipping_cvs_store_address' );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
/* translators: %s: customer first name */
printf( esc_html__( '%s 您好，', 'moksa-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) );
?></p>

<p><strong><?php esc_html_e( '您的包裹已送達取件門市，請於 7 天內持證件至門市取貨。', 'moksa-for-woocommerce' ); ?></strong></p>

<?php if ( $mo_store_name || $mo_store_id || $mo_store_address ) : ?>
<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin-bottom:16px;">
	<?php if ( $mo_store_name ) : ?>
		<tr><th align="left"><?php esc_html_e( '取件門市', 'moksa-for-woocommerce' ); ?></th><td><?php echo esc_html( $mo_store_name ); ?></td></tr>
	<?php endif; ?>
	<?php if ( $mo_store_id ) : ?>
		<tr><th align="left"><?php esc_html_e( '門市代號', 'moksa-for-woocommerce' ); ?></th><td><?php echo esc_html( $mo_store_id ); ?></td></tr>
	<?php endif; ?>
	<?php if ( $mo_store_address ) : ?>
		<tr><th align="left"><?php esc_html_e( '門市地址', 'moksa-for-woocommerce' ); ?></th><td><?php echo esc_html( $mo_store_address ); ?></td></tr>
	<?php endif; ?>
</table>
<?php endif; ?>

<p><?php esc_html_e( '逾期未取件，包裹將退回物流中心，並可能產生退運費用。', 'moksa-for-woocommerce' ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
do_action( 'moksafowo_shipping_email_tracking_info', $order, false );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_footer', $email );
