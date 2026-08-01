<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\CustomerService;

use Moksafowo\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 前台客服 — 顧客自助查單(訂單編號 + 帳單電話末三碼二次驗證)。
 *
 * P1:自助查訂單狀態 / 物流 / 發票 / 付款(全唯讀去敏),前台浮動窗 + 頁面顯示規則。
 * 完全面向未登入訪客,安全第一;不重用後台店家權限的 ability。
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'customer_service';
	}

	public function label(): string {
		return __( 'Storefront support — customers look up their own orders with an order number and the last three digits of their phone', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'tools';
	}

	public function name(): string {
		return __( 'Storefront support', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'Customers check their own order status, shipment and invoice, protected by a second verification step', 'moksa-for-woocommerce' );
	}

	public function boot(): void {
		// 模組卡片（moksafowo_customer_service_enabled）是唯一的開關，見 AiAssistant\Module::boot()。
		Schema::maybe_install();
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function enqueue(): void {
		if ( is_admin() || ! self::should_show() ) {
			return;
		}

		$css_rel = 'src/Modules/CustomerService/assets/css/cs-widget.css';
		$js_rel  = 'src/Modules/CustomerService/assets/js/cs-widget.js';
		$css     = MOKSAFOWO_PLUGIN_DIR . $css_rel;
		$js      = MOKSAFOWO_PLUGIN_DIR . $js_rel;

		wp_enqueue_style(
			'moksafowo-cs-widget',
			MOKSAFOWO_PLUGIN_URL . $css_rel,
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : MOKSAFOWO_VERSION
		);
		wp_enqueue_script(
			'moksafowo-cs-widget',
			MOKSAFOWO_PLUGIN_URL . $js_rel,
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : MOKSAFOWO_VERSION,
			true
		);
		wp_localize_script(
			'moksafowo-cs-widget',
			'moksafowoCs',
			array(
				'rest'  => esc_url_raw( rest_url( Rest::NS . '/cs' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'title' => (string) get_option( 'moksafowo_customer_service_title', __( 'Order lookup', 'moksa-for-woocommerce' ) ),
				'i18n'  => array(
					'bubble'      => __( 'Order lookup', 'moksa-for-woocommerce' ),
					'order_label' => __( 'Order number', 'moksa-for-woocommerce' ),
					'phone_label' => __( 'Last three digits of the billing phone', 'moksa-for-woocommerce' ),
					'submit'      => __( 'Look up', 'moksa-for-woocommerce' ),
					'querying'    => __( 'Looking that up…', 'moksa-for-woocommerce' ),
					'again'       => __( 'Look up another order', 'moksa-for-woocommerce' ),
					'close'       => __( 'Close', 'moksa-for-woocommerce' ),
					'paid'        => __( 'Paid', 'moksa-for-woocommerce' ),
					'unpaid'      => __( 'Unpaid', 'moksa-for-woocommerce' ),
					'status'      => __( 'Order status', 'moksa-for-woocommerce' ),
					'total'       => __( 'Order total', 'moksa-for-woocommerce' ),
					'payment'     => __( 'Payment method', 'moksa-for-woocommerce' ),
					'atm'         => __( 'ATM virtual account', 'moksa-for-woocommerce' ),
					'cvs'         => __( 'Convenience store payment code', 'moksa-for-woocommerce' ),
					'shipping'    => __( 'Shipping method', 'moksa-for-woocommerce' ),
					'ship_no'     => __( 'Tracking number', 'moksa-for-woocommerce' ),
					'invoice'     => __( 'E-invoice number', 'moksa-for-woocommerce' ),
					'items'       => __( 'Items', 'moksa-for-woocommerce' ),
					'hint'        => __( 'Enter your order number and the last three digits of your billing phone to check on your order.', 'moksa-for-woocommerce' ),
					'contact'     => __( 'Contact us', 'moksa-for-woocommerce' ),
					'back'        => __( '← Back to the order', 'moksa-for-woocommerce' ),
					'send'        => __( 'Send', 'moksa-for-woocommerce' ),
					'msg_ph'      => __( 'Write a message…', 'moksa-for-woocommerce' ),
					'you'         => __( 'You', 'moksa-for-woocommerce' ),
					'staff'       => __( 'Support', 'moksa-for-woocommerce' ),
					'ai_label'    => __( 'AI support', 'moksa-for-woocommerce' ),
					'no_msg'      => __( 'Any questions? Send a message and we will get back to you as soon as we can.', 'moksa-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * 頁面顯示規則:全部 / 僅指定頁面 / 排除指定頁面(以頁面 ID 清單)。
	 */
	private static function should_show(): bool {
		$mode = (string) get_option( 'moksafowo_customer_service_display_mode', 'all' );
		if ( 'all' === $mode ) {
			return true;
		}
		$list    = array_filter( array_map( 'absint', explode( ',', (string) get_option( 'moksafowo_customer_service_pages', '' ) ) ) );
		$current = (int) get_queried_object_id();
		$in_list = $current > 0 && in_array( $current, $list, true );
		return 'include' === $mode ? $in_list : ! $in_list;
	}
}
