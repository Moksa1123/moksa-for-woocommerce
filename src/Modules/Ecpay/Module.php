<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Ecpay;

use MoksaWeb\Mowc\Modules\Shared\AbstractGatewayModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractGatewayModule {

	public function slug(): string {
		return 'ecpay';
	}

	public function label(): string {
		return __( '綠界金流 — 信用卡 / ATM / 超商代碼 / 超商條碼 / 網路 ATM', 'mo-ectools' );
	}

	public function name(): string {
		return __( '綠界金流', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '信用卡 / ATM / 超商 / 條碼 / 網路 ATM', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( '信用卡', 'mo-ectools' ),
			__( '信用卡分期', 'mo-ectools' ),
			__( 'ATM 轉帳', 'mo-ectools' ),
			__( '超商代碼', 'mo-ectools' ),
			__( '超商條碼', 'mo-ectools' ),
			__( 'WebATM', 'mo-ectools' ),
			__( 'Apple Pay', 'mo-ectools' ),
			__( 'TWQR', 'mo-ectools' ),
			__( '無卡分期（裕富 / 中租）', 'mo-ectools' ),
			__( '微信支付', 'mo-ectools' ),
			__( '街口支付', 'mo-ectools' ),
			__( '一卡通', 'mo-ectools' ),
		];
	}

	public function settings_section(): string {
		return 'ecpay';
	}

	public static function gateway_map(): array {
		return [
			Gateways\Unified::GATEWAY_ID => Gateways\Unified::class,
			'mo_ecpay_credit'            => Gateways\Credit::class,
			'mo_ecpay_credit_3'          => Gateways\CreditInstallment3::class,
			'mo_ecpay_credit_6'          => Gateways\CreditInstallment6::class,
			'mo_ecpay_credit_12'         => Gateways\CreditInstallment12::class,
			'mo_ecpay_credit_18'         => Gateways\CreditInstallment18::class,
			'mo_ecpay_credit_24'         => Gateways\CreditInstallment24::class,
			'mo_ecpay_atm'               => Gateways\Atm::class,
			'mo_ecpay_cvs'               => Gateways\Cvs::class,
			'mo_ecpay_barcode'           => Gateways\Barcode::class,
			'mo_ecpay_webatm'            => Gateways\Webatm::class,
			'mo_ecpay_applepay'          => Gateways\ApplePay::class,
			'mo_ecpay_twqr'              => Gateways\Twqr::class,
			'mo_ecpay_bnpl'              => Gateways\Bnpl::class,
			'mo_ecpay_weixin'            => Gateways\Weixin::class,
			'mo_ecpay_jkopay'            => Gateways\Jkopay::class,
			'mo_ecpay_ipass'             => Gateways\Ipass::class,
		];
	}

	protected static function blocks_method_class(): string {
		return Blocks\EcpayBlocksMethod::class;
	}

	protected static function unified_gateway_id(): ?string {
		return Gateways\Unified::GATEWAY_ID;
	}

	protected function register_webhooks(): void {
		add_action( 'woocommerce_api_mo_ecpay_payment', [ Api\IpnHandler::class, 'handle' ] );
	}

	protected function boot_extras(): void {
		add_filter( 'woocommerce_order_get_payment_method_title', [ __CLASS__, 'rebrand_legacy_payment_title' ], 10, 2 );

		// 顧客端取號繳費資訊（ATM/超商代碼/條碼）— thankyou + my-account/view-order。
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
			$pay_type = (string) $order->get_meta( \MoksaWeb\Mowc\Order\Meta\Keys::ECPAY_PAYMENT_TYPE );
			if ( '' !== $pay_type ) {
				$sub = Admin\OrderMetaBox::pay_type_label( $pay_type );
				$sub = preg_replace( '/ — (.+)$/u', '（$1）', $sub );
				return $base . ' — ' . $sub;
			}
		}

		return $base;
	}
}
