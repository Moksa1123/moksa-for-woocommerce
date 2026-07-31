<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Payuni;

use Moksafowo\Crypto\Vault;
use Moksafowo\Modules\AbstractModule;
use Moksafowo\Modules\Payuni\Blocks\PayuniBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'payuni';
	}

	public function label(): string {
		return __( 'PAYUNi — 13 payment methods (credit card, ATM, convenience store code, iCash Pay, JKOPAY, AFTEE, UnionPay, reward points, Apple Pay, Google Pay, Samsung Pay, LINE Pay and instalments) plus e-invoicing, shown either as separate methods or a single entry point', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'payment';
	}

	public function name(): string {
		return __( 'PAYUNi', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( '13 payment methods plus e-invoicing', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( 'Credit card', 'moksa-for-woocommerce' ),
			__( 'ATM transfer', 'moksa-for-woocommerce' ),
			__( 'Convenience store code', 'moksa-for-woocommerce' ),
			__( 'iCash Pay', 'moksa-for-woocommerce' ),
			__( 'JKOPAY', 'moksa-for-woocommerce' ),
			__( 'AFTEE pay later', 'moksa-for-woocommerce' ),
			__( 'UnionPay', 'moksa-for-woocommerce' ),
			__( 'Credit card reward points', 'moksa-for-woocommerce' ),
			__( 'Apple Pay', 'moksa-for-woocommerce' ),
			__( 'Google Pay', 'moksa-for-woocommerce' ),
			__( 'Samsung Pay', 'moksa-for-woocommerce' ),
			__( 'LINE Pay', 'moksa-for-woocommerce' ),
			__( 'Credit card instalments', 'moksa-for-woocommerce' ),
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
		\Moksafowo\Modules\Shared\Setup\GatewayAllowlistMigrator::seed_if_unseeded( 'payuni', $ids );

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
