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
		AddFee::init();
		CartTempLabel::init();
		if ( is_admin() ) {
			ProductTempField::init();
		}
		// CSV hooks 需在 WPCLI / cron / REST 也能 fire，脫離 admin guard
		ProductTempField::init_csv_hooks();
		add_filter( 'woocommerce_email_classes', [ __CLASS__, 'register_email_classes' ] );
		Emails\EmailTrackingSection::init();
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

	public static function register_email_classes( array $emails ): array {
		$emails['moksafowo_shipping_shipped']      = new Emails\EmailShipped();
		$emails['moksafowo_shipping_cvs_arrived']  = new Emails\EmailCvsArrived();
		$emails['moksafowo_shipping_store_closed'] = new Emails\EmailStoreClosed();
		return $emails;
	}
}
