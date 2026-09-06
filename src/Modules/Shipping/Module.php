<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping;

use Moksafowo\Modules\Shipping\Admin\BatchPrintAdminUI;
use Moksafowo\Modules\Shipping\Admin\CvsStoreEditor;
use Moksafowo\Modules\Shipping\Admin\ShippingCardSection;
use Moksafowo\Modules\Shipping\Frontend\CartTempLabel;
use Moksafowo\Modules\Shipping\Shortcodes\AddFee;
use Moksafowo\Modules\Shipping\Statuses\Registrar;
use Moksafowo\Modules\Shipping\Temp\ProductTempField;

defined( 'ABSPATH' ) || exit;

final class Module {

	public static function boot(): void {
		Registrar::init();
		Webhook\StatusReconciler::init();
		if ( \Moksafowo\Settings\AdvancedSections::is_on( \Moksafowo\Settings\AdvancedSections::SHIPPING_COMMON ) ) {
			BatchPrintAdminUI::init();
		}
		ShippingCardSection::init();
		AddFee::init();
		CartTempLabel::init();
		if ( is_admin() ) {
			ProductTempField::init();
			CvsStoreEditor::init();
		}
		// CSV hooks 需在 WPCLI / cron / REST 也能 fire，脫離 admin guard
		ProductTempField::init_csv_hooks();
		add_filter( 'woocommerce_email_classes', [ __CLASS__, 'register_email_classes' ] );
		// WC 11 的區塊信件編輯器只認一份白名單，第三方信件沒加進去就沒有
		// 「編輯 / 預覽 / 測試信」，商家等於不能自訂內容。
		add_filter( 'woocommerce_transactional_emails_for_block_editor', [ __CLASS__, 'register_block_editor_emails' ] );
		Emails\EmailTrackingSection::init();
		Frontend\CvsStoreRequired::init();
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_frontend_assets' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'register_admin_assets' ] );
	}

	public static function register_frontend_assets(): void {
		wp_register_style(
			'moksafowo-shipping-card',
			MOKSAFOWO_PLUGIN_URL . 'assets/public/moksafowo-shipping-card.css',
			[],
			MOKSAFOWO_VERSION
		);
		wp_register_script(
			'moksafowo-tracking-copy',
			MOKSAFOWO_PLUGIN_URL . 'assets/public/moksafowo-tracking-copy.js',
			[],
			MOKSAFOWO_VERSION,
			true
		);
	}

	public static function register_admin_assets(): void {
		wp_register_script(
			'moksafowo-tracking-copy',
			MOKSAFOWO_PLUGIN_URL . 'assets/public/moksafowo-tracking-copy.js',
			[],
			MOKSAFOWO_VERSION,
			true
		);
	}

	/**
	 * 把本外掛的信件加進區塊信件編輯器的白名單。
	 *
	 * 這裡放的是 WC_Email::$id（不是 register_email_classes() 用的陣列 key）——
	 * WooCommerce 的白名單是照 id 比對的。
	 *
	 * @param array<int,string> $emails 核心的信件 id 清單。
	 * @return array<int,string>
	 */
	public static function register_block_editor_emails( array $emails ): array {
		// 不要在這裡重新 new 一次信件類別 —— AbstractShippingEmail 的建構子會
		// add_action 到通知 hook 上，多做一份實例就會讓同一封信寄兩次。
		return array_merge(
			$emails,
			[ 'customer_moksa_shipped', 'customer_moksa_cvs_arrived', 'customer_moksa_store_closed' ]
		);
	}

	public static function register_email_classes( array $emails ): array {
		$emails['moksafowo_shipping_shipped']      = new Emails\EmailShipped();
		$emails['moksafowo_shipping_cvs_arrived']  = new Emails\EmailCvsArrived();
		$emails['moksafowo_shipping_store_closed'] = new Emails\EmailStoreClosed();
		return $emails;
	}
}
