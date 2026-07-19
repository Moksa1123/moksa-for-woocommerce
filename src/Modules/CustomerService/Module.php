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
		return __( '前台客服 — 顧客自助查單(訂單號 + 電話末三碼驗證)', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'tools';
	}

	public function name(): string {
		return __( '前台客服', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( '顧客自助查訂單狀態 / 物流 / 發票(二次驗證)', 'moksa-for-woocommerce' );
	}

	public function boot(): void {
		if ( 'yes' !== get_option( 'moksafowo_ai_enabled', 'no' ) ) {
			return;
		}
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
				'title' => (string) get_option( 'moksafowo_customer_service_title', __( '訂單查詢', 'moksa-for-woocommerce' ) ),
				'i18n'  => array(
					'bubble'      => __( '訂單查詢', 'moksa-for-woocommerce' ),
					'order_label' => __( '訂單編號', 'moksa-for-woocommerce' ),
					'phone_label' => __( '帳單電話末三碼', 'moksa-for-woocommerce' ),
					'submit'      => __( '查詢', 'moksa-for-woocommerce' ),
					'querying'    => __( '查詢中…', 'moksa-for-woocommerce' ),
					'again'       => __( '查其他訂單', 'moksa-for-woocommerce' ),
					'close'       => __( '關閉', 'moksa-for-woocommerce' ),
					'paid'        => __( '已付款', 'moksa-for-woocommerce' ),
					'unpaid'      => __( '未付款', 'moksa-for-woocommerce' ),
					'status'      => __( '訂單狀態', 'moksa-for-woocommerce' ),
					'total'       => __( '訂單金額', 'moksa-for-woocommerce' ),
					'payment'     => __( '付款方式', 'moksa-for-woocommerce' ),
					'atm'         => __( 'ATM 轉帳虛擬帳號', 'moksa-for-woocommerce' ),
					'cvs'         => __( '超商繳費代碼', 'moksa-for-woocommerce' ),
					'shipping'    => __( '運送方式', 'moksa-for-woocommerce' ),
					'ship_no'     => __( '物流單號', 'moksa-for-woocommerce' ),
					'invoice'     => __( '電子發票號碼', 'moksa-for-woocommerce' ),
					'items'       => __( '商品', 'moksa-for-woocommerce' ),
					'hint'        => __( '輸入訂單編號與帳單電話末三碼即可查詢訂單進度。', 'moksa-for-woocommerce' ),
					'contact'     => __( '聯絡客服', 'moksa-for-woocommerce' ),
					'back'        => __( '← 返回訂單', 'moksa-for-woocommerce' ),
					'send'        => __( '送出', 'moksa-for-woocommerce' ),
					'msg_ph'      => __( '輸入訊息…', 'moksa-for-woocommerce' ),
					'you'         => __( '您', 'moksa-for-woocommerce' ),
					'staff'       => __( '客服', 'moksa-for-woocommerce' ),
					'ai_label'    => __( 'AI 客服', 'moksa-for-woocommerce' ),
					'no_msg'      => __( '有問題嗎?輸入訊息,我們會盡快回覆。', 'moksa-for-woocommerce' ),
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
