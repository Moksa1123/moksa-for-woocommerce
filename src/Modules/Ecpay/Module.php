<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay;

use Moksafowo\Modules\Shared\AbstractGatewayModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractGatewayModule {

	public function slug(): string {
		return 'ecpay';
	}

	public function label(): string {
		return __( 'ECPay payments — credit card, ATM, convenience store code, barcode and WebATM', 'moksa-for-woocommerce' );
	}

	public function name(): string {
		return __( 'ECPay payments', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'Credit card, ATM, convenience store, barcode and WebATM', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( 'Credit card', 'moksa-for-woocommerce' ),
			__( 'Credit card instalments', 'moksa-for-woocommerce' ),
			__( 'ATM transfer', 'moksa-for-woocommerce' ),
			__( 'Convenience store code', 'moksa-for-woocommerce' ),
			__( 'Convenience store barcode', 'moksa-for-woocommerce' ),
			__( 'WebATM', 'moksa-for-woocommerce' ),
			__( 'Apple Pay', 'moksa-for-woocommerce' ),
			__( 'TWQR', 'moksa-for-woocommerce' ),
			__( 'Buy now, pay later (Yufu Digital / Zingala)', 'moksa-for-woocommerce' ),
			__( 'WeChat Pay', 'moksa-for-woocommerce' ),
			__( 'JKOPAY', 'moksa-for-woocommerce' ),
			__( 'iPASS', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'ecpay';
	}

	public static function gateway_map(): array {
		return [
			Gateways\Unified::GATEWAY_ID => Gateways\Unified::class,
			'moksafowo_ecpay_credit'     => Gateways\Credit::class,
			'moksafowo_ecpay_credit_3'   => Gateways\CreditInstallment3::class,
			'moksafowo_ecpay_credit_6'   => Gateways\CreditInstallment6::class,
			'moksafowo_ecpay_credit_12'  => Gateways\CreditInstallment12::class,
			'moksafowo_ecpay_credit_18'  => Gateways\CreditInstallment18::class,
			'moksafowo_ecpay_credit_24'  => Gateways\CreditInstallment24::class,
			'moksafowo_ecpay_atm'        => Gateways\Atm::class,
			'moksafowo_ecpay_cvs'        => Gateways\Cvs::class,
			'moksafowo_ecpay_barcode'    => Gateways\Barcode::class,
			'moksafowo_ecpay_webatm'     => Gateways\Webatm::class,
			'moksafowo_ecpay_applepay'   => Gateways\ApplePay::class,
			'moksafowo_ecpay_twqr'       => Gateways\Twqr::class,
			'moksafowo_ecpay_bnpl'       => Gateways\Bnpl::class,
			'moksafowo_ecpay_weixin'     => Gateways\Weixin::class,
			'moksafowo_ecpay_jkopay'     => Gateways\Jkopay::class,
			'moksafowo_ecpay_ipass'      => Gateways\Ipass::class,
		];
	}

	protected static function blocks_method_class(): string {
		return Blocks\EcpayBlocksMethod::class;
	}

	protected static function unified_gateway_id(): ?string {
		return Gateways\Unified::GATEWAY_ID;
	}

	protected function register_webhooks(): void {
		add_action( 'woocommerce_api_moksafowo_ecpay_payment', [ Api\IpnHandler::class, 'handle' ] );
	}

	protected function boot_extras(): void {
		add_filter( 'woocommerce_order_get_payment_method_title', [ __CLASS__, 'rebrand_legacy_payment_title' ], 10, 2 );

		Frontend\CustomerPaymentInfo::init();

		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
			Admin\CreditLifecycleBox::init();
		}
	}

	public static function rebrand_legacy_payment_title( string $title, $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return $title;
		}
		$method = (string) $order->get_payment_method();
		if ( ! isset( self::gateway_map()[ $method ] ) ) {
			return $title;
		}

		$base      = $title;
		$is_legacy = str_starts_with( $title, 'ECPay' );
		if ( $is_legacy ) {
			$class = self::gateway_map()[ $method ];
			if ( class_exists( $class ) ) {
				try {
					$gateway = new $class();
					$base    = (string) ( $gateway->title ?? '' );
					if ( '' === $base ) {
						$base = $title;
					}
				} catch ( \Throwable $e ) {
					$base = $title;
				}
			}
		}

		if ( Gateways\Unified::GATEWAY_ID === $method ) {
			$pay_type = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::ECPAY_PAYMENT_TYPE );
			if ( '' !== $pay_type ) {
				$sub = Admin\OrderMetaBox::pay_type_label( $pay_type );
				$sub = preg_replace( '/ — (.+)$/u', '（$1）', $sub );
				return $base . ' — ' . $sub;
			}
		}

		return $base;
	}
}
