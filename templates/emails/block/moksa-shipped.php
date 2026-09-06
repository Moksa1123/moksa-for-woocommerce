<?php
/**
 * 已出貨通知 — 區塊信件編輯器的初始內容。
 *
 * WooCommerce 11 的區塊信件編輯器用這份當商家「第一次打開編輯器」時的內容；
 * 存檔後就改以資料庫裡的 woo_email 文章為準，這個檔案只剩新站台的預設值。
 *
 * wp:woocommerce/email-content 是鎖定的佔位符，會被 WooCommerce 換成訂單明細、
 * 顧客資料與本外掛的物流追蹤區塊（見 EmailTrackingSection）。商家可以自由編輯
 * 它前後的段落，那正是舊介面做不到、這次要補上的「自訂內容」。
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
<p> <?php echo esc_html__( 'Your order has shipped and is on its way. The tracking number and shipping status are below.', 'moksa-for-woocommerce' ); ?> </p>
<!-- /wp:paragraph -->

<!-- wp:woocommerce/email-content {"lock":{"move":false,"remove":true}} -->
<div class="wp-block-woocommerce-email-content"> <?php echo esc_html( BlockEmailRenderer::WOO_EMAIL_CONTENT_PLACEHOLDER ); ?> </div>
<!-- /wp:woocommerce/email-content -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center"><?php
	/* translators: %s: store admin email */
	printf( esc_html__( 'If you have any questions about this order, contact us at %s.', 'moksa-for-woocommerce' ), '<!--[woocommerce/store-email]-->' );
?></p>
<!-- /wp:paragraph -->
