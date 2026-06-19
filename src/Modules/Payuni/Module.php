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
		return 'moksafowo-payuni-payment';
	}

	public function boot(): void {
		// Wrap credentials for at-rest encryption before PayuniPayment::init() so
		// the first get_option() inside PaymentRequest decrypts cleanly.
		foreach ( [
			'moksafowo_payuni_payment_hashkey',
			'moksafowo_payuni_payment_hashkey_test',
			'moksafowo_payuni_payment_hashiv',
			'moksafowo_payuni_payment_hashiv_test',
		] as $opt ) {
			Vault::wrap_option( $opt );
		}

		CredentialsMigrator::run_once();
		PayuniPayment::init();

		$ids = is_array( PayuniPayment::$allowed_payments ) ? array_keys( PayuniPayment::$allowed_payments ) : [];
		$ids = array_values(
			array_filter(
				$ids,
				static function ( $id ): bool {
					$id = (string) $id;
					return 'unified' !== $id
					&& 0 !== strpos( $id, 'moksafowo_payuni_installment_' );
				}
			)
		);
		\MoksaWeb\Mowc\Modules\Shared\Setup\GatewayAllowlistMigrator::seed_if_unseeded( 'payuni', $ids );

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
