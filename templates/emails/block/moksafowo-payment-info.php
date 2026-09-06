<?php
/**
 * 取號繳費通知 — 區塊信件編輯器的初始內容。
 *
 * wp:woocommerce/email-content 會被換成 PaymentInfoEmail::get_block_editor_email_template_content()
 * 產生的取號表格與訂單明細。商家可以自由編輯它前後的段落。
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package Moksafowo\Templates\Emails\Block
 */

use Automattic\WooCommerce\Internal\EmailEditor\BlockEmailRenderer;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Squiz.PHP.EmbeddedPhp.ContentBeforeOpen -- 避免產生多餘空行。
// phpcs:disable Squiz.PHP.EmbeddedPhp.ContentAfterEnd -- 避免產生多餘空行。
?>

<!-- wp:paragraph -->
<p><?php
	/* translators: %s: customer first name */
	printf( esc_html__( 'Hi %s,', 'moksa-for-woocommerce' ), '<!--[woocommerce/customer-first-name]-->' );
?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p> <?php echo esc_html__( 'Your order has been placed. Please use the payment details below before the deadline.', 'moksa-for-woocommerce' ); ?> </p>
<!-- /wp:paragraph -->

<!-- wp:woocommerce/email-content {"lock":{"move":false,"remove":true}} -->
<div class="wp-block-woocommerce-email-content"> <?php echo esc_html( BlockEmailRenderer::WOO_EMAIL_CONTENT_PLACEHOLDER ); ?> </div>
<!-- /wp:woocommerce/email-content -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center"><?php
	/* translators: %s: store admin email */
	printf( esc_html__( 'If you have any questions about this payment, contact us at %s.', 'moksa-for-woocommerce' ), '<!--[woocommerce/store-email]-->' );
?></p>
<!-- /wp:paragraph -->
