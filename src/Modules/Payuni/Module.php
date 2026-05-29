<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Payuni;

use MoksaWeb\Mowc\Crypto\Vault;
use MoksaWeb\Mowc\Modules\AbstractModule;
use MoksaWeb\Mowc\Modules\Payuni\Blocks\PayuniBlocksMethod;
use MoksaWeb\Mowc\Modules\Payuni\Setup\CredentialsMigrator;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'payuni';
	}

	public function label(): string {
		return __( 'PAYUNi 統一金流 — 13 種付款（信用卡/ATM/超商代碼/愛金卡/街口/Aftee/銀聯/紅利/Apple/Google/Samsung Pay/LINE Pay/分期）+ 電子發票，可切換多入口/單一入口呈現', 'mo-ectools' );
	}

	public function category(): string {
		return 'payment';
	}

	public function name(): string {
		return __( 'PAYUNi 統一金流', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '13 種付款工具 + 電子發票', 'mo-ectools' );
	}

	public function methods(): array {
		// 13 個 PAYUNi 收銀台付款方式。chip 用對顧客直覺的中文名稱：
		// - 「超商代碼」明確指「在超商繳費」（金流），避免被誤認為超商取貨（物流）
		// - 「ATM 轉帳」明確指「虛擬帳號轉帳」
		// - 「街口」「愛金卡」「銀聯」「紅利」加後綴清楚對應
		// 電子發票（Amego）是 PAYUNi 的附加功能而不是付款方式，僅在
		// tagline 提及，不列為 chip。
		return [
			__( '信用卡', 'mo-ectools' ),
			__( 'ATM 轉帳', 'mo-ectools' ),
			__( '超商代碼', 'mo-ectools' ),
			__( '愛金卡', 'mo-ectools' ),
			__( '街口支付', 'mo-ectools' ),
			__( 'Aftee 後付', 'mo-ectools' ),
			__( '銀聯卡', 'mo-ectools' ),
			__( '信用卡紅利', 'mo-ectools' ),
			__( 'Apple Pay', 'mo-ectools' ),
			__( 'Google Pay', 'mo-ectools' ),
			__( 'Samsung Pay', 'mo-ectools' ),
			__( 'LINE Pay', 'mo-ectools' ),
			__( '信用卡分期', 'mo-ectools' ),
		];
	}

	public function settings_section(): string {
		return 'payuni-payment';
	}

	public function boot(): void {
		// At-rest encryption for PAYUNi credentials. Wrap BEFORE PayuniPayment::init()
		// so the very first get_option() inside PaymentRequest decrypts cleanly.
		// Existing plain-text values keep working; the next admin save migrates.
		// Phase B（v0.5.63）：同時 wrap 新舊兩組 key — settings page 寫進 mo_payuni_*
		// 新 key 走 wrap encrypt-at-rest；legacy payuni_payment_* 仍 wrap 確保既有
		// site 的 ciphertext decrypt 仍正常（Credentials helper fallback 讀 legacy）。
		foreach ( [
			// New canonical mo_* keys (Phase B SettingsTab 寫入點)
			'mo_payuni_payment_hashkey',
			'mo_payuni_payment_hashkey_test',
			'mo_payuni_payment_hashiv',
			'mo_payuni_payment_hashiv_test',
			// Legacy keys (Phase A fallback；既有 site credentials 仍保留)
			'payuni_payment_hashkey',
			'payuni_payment_hashkey_test',
			'payuni_payment_hashiv',
			'payuni_payment_hashiv_test',
		] as $opt ) {
			Vault::wrap_option( $opt );
		}

		// Phase B 一次性 migration: copy legacy → new (不刪 legacy 當 backup)
		CredentialsMigrator::run_once();

		// PayuniPayment::init() registers gateways, AJAX, settings, assets at the
		// right WC hooks. No need for us to wire each piece individually here.
		PayuniPayment::init();

		// seed enabled_methods（升級保留現用，新站預設空 = 全不註冊）。
		// init() 之後 $allowed_payments 已是完整 gateway id → class map。
		$ids = is_array( PayuniPayment::$allowed_payments ) ? array_keys( PayuniPayment::$allowed_payments ) : [];
		// Unified 由 display_mode=single 控制、Installment 由獨立設定控制，皆不入 allowlist。
		$ids = array_values( array_filter( $ids, static function ( $id ): bool {
			$id = (string) $id;
			return 'unified' !== $id
				&& 0 !== strpos( $id, 'mo_payuni_installment_' );
		} ) );
		\MoksaWeb\Mowc\Modules\Shared\Setup\GatewayAllowlistMigrator::seed_if_unseeded( 'payuni', $ids );

		// Register directly on Blocks' registration action — wrapping it in
		// `woocommerce_blocks_loaded` mis-orders the hooks and the adapters
		// never land in the registry.
		add_action( 'woocommerce_blocks_payment_method_type_registration', [ self::class, 'register_block_methods' ] );
	}

	public static function register_block_methods( $registry ): void {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		$gateways = is_array( PayuniPayment::$allowed_payments ) ? PayuniPayment::$allowed_payments : [];

		foreach ( array_keys( $gateways ) as $gateway_id ) {
			if ( ! PayuniPayment::should_register_gateway( (string) $gateway_id ) ) {
				continue;
			}
			$registry->register( new PayuniBlocksMethod( (string) $gateway_id ) );
		}
	}
}
