<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping;

use MoksaWeb\Mowc\Modules\Shipping\Admin\BatchPrintAdminUI;
use MoksaWeb\Mowc\Modules\Shipping\Admin\ShippingCardSection;
use MoksaWeb\Mowc\Modules\Shipping\Frontend\CartTempLabel;
use MoksaWeb\Mowc\Modules\Shipping\Shortcodes\AddFee;
use MoksaWeb\Mowc\Modules\Shipping\Statuses\Registrar;
use MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTempField;

defined( 'ABSPATH' ) || exit;

final class Module {

	public static function boot(): void {
		Registrar::init();
		BatchPrintAdminUI::init();
		ShippingCardSection::init();
		// [mo_addfee] / [ry_addfee] shortcode — 給 cost formula 用
		AddFee::init();
		// Cart / 結帳頁顯示溫層拆解（透明顧客運費怎來的）
		CartTempLabel::init();
		// 商品溫層欄位（Simple / Variation）— admin UI 才需註冊
		if ( is_admin() ) {
			ProductTempField::init();
		}
		// CSV import / export hooks — 需在 WPCLI / cron / REST 也能 fire，所以脫離 admin guard
		ProductTempField::init_csv_hooks();
		add_filter( 'woocommerce_email_classes', [ __CLASS__, 'register_email_classes' ] );
		// Email 模板裡 fire 的「貨態查詢」section（mo_shipping_email_tracking_info action）
		Emails\EmailTrackingSection::init();
		// 共用 frontend assets — 各 Provider CustomerOrderView 用 wp_enqueue_*('mo-shipping-card' / 'mo-tracking-copy')
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_frontend_assets' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'register_admin_assets' ] );
	}

	public static function register_frontend_assets(): void {
		wp_register_style(
			'mo-shipping-card',
			MOWC_PLUGIN_URL . 'assets/public/mo-shipping-card.css',
			[],
			MOWC_VERSION
		);
		wp_register_script(
			'mo-tracking-copy',
			MOWC_PLUGIN_URL . 'assets/public/mo-tracking-copy.js',
			[],
			MOWC_VERSION,
			true
		);
	}

	public static function register_admin_assets(): void {
		wp_register_script(
			'mo-tracking-copy',
			MOWC_PLUGIN_URL . 'assets/public/mo-tracking-copy.js',
			[],
			MOWC_VERSION,
			true
		);
	}

	public static function register_email_classes( array $emails ): array {
		$emails['mo_shipping_shipped']      = new Emails\EmailShipped();
		$emails['mo_shipping_cvs_arrived']  = new Emails\EmailCvsArrived();
		$emails['mo_shipping_store_closed'] = new Emails\EmailStoreClosed();
		return $emails;
	}
}
