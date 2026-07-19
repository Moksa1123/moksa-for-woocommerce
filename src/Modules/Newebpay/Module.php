<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay;

use Moksafowo\Modules\Shared\AbstractGatewayModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractGatewayModule {

	public function slug(): string {
		return 'newebpay';
	}

	public function label(): string {
		return __( '藍新金流 — 信用卡 / 信用卡分期 / ATM / WebATM / 超商代碼 / 超商條碼', 'moksa-for-woocommerce' );
	}

	public function name(): string {
		return __( '藍新金流', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( '信用卡 / ATM / 超商 / 行動支付 / 分期後付', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( '信用卡', 'moksa-for-woocommerce' ),
			__( '信用卡分期', 'moksa-for-woocommerce' ),
			__( 'ATM', 'moksa-for-woocommerce' ),
			__( 'WebATM', 'moksa-for-woocommerce' ),
			__( '超商代碼', 'moksa-for-woocommerce' ),
			__( '超商條碼', 'moksa-for-woocommerce' ),
			__( 'Apple Pay', 'moksa-for-woocommerce' ),
			__( 'Google Pay', 'moksa-for-woocommerce' ),
			__( 'Samsung Pay', 'moksa-for-woocommerce' ),
			__( 'LINE Pay', 'moksa-for-woocommerce' ),
			__( '玉山 Wallet', 'moksa-for-woocommerce' ),
			__( '台灣 Pay', 'moksa-for-woocommerce' ),
			__( 'TWQR', 'moksa-for-woocommerce' ),
			__( '支付寶', 'moksa-for-woocommerce' ),
			__( '微信支付', 'moksa-for-woocommerce' ),
			__( 'AFTEE 無卡分期', 'moksa-for-woocommerce' ),
			__( '銀聯卡', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'newebpay';
	}

	public static function gateway_map(): array {
		return [
			Gateways\Unified::GATEWAY_ID            => Gateways\Unified::class,
			'moksafowo_newebpay_credit'             => Gateways\Credit::class,
			'moksafowo_newebpay_credit_installment' => Gateways\CreditInstallment::class,
			'moksafowo_newebpay_atm'                => Gateways\Atm::class,
			'moksafowo_newebpay_webatm'             => Gateways\Webatm::class,
			'moksafowo_newebpay_cvs'                => Gateways\Cvs::class,
			'moksafowo_newebpay_barcode'            => Gateways\Barcode::class,
			'moksafowo_newebpay_applepay'           => Gateways\ApplePay::class,
			'moksafowo_newebpay_googlepay'          => Gateways\GooglePay::class,
			'moksafowo_newebpay_samsungpay'         => Gateways\SamsungPay::class,
			'moksafowo_newebpay_linepay'            => Gateways\LinePay::class,
			'moksafowo_newebpay_esunwallet'         => Gateways\EsunWallet::class,
			'moksafowo_newebpay_taiwanpay'          => Gateways\TaiwanPay::class,
			'moksafowo_newebpay_twqr'               => Gateways\Twqr::class,
			'moksafowo_newebpay_alipay'             => Gateways\Alipay::class,
			'moksafowo_newebpay_wechatpay'          => Gateways\WeChatPay::class,
			'moksafowo_newebpay_aftee'              => Gateways\Aftee::class,
			'moksafowo_newebpay_unionpay'           => Gateways\UnionPay::class,
		];
	}

	protected static function blocks_method_class(): string {
		return Blocks\NewebpayBlocksMethod::class;
	}

	protected static function unified_gateway_id(): ?string {
		return Gateways\Unified::GATEWAY_ID;
	}

	protected function register_webhooks(): void {
		add_action( 'woocommerce_api_moksafowo_newebpay_payment', [ Api\IpnHandler::class, 'handle' ] );
	}

	protected function boot_extras(): void {
		add_filter( 'woocommerce_order_get_payment_method_title', [ __CLASS__, 'rebrand_legacy_payment_title' ], 10, 2 );

		Frontend\CustomerPaymentInfo::init();

		// NewebpayShipping fallback: shipping module uses payment credentials when no shipping-specific credentials set.
		add_filter( 'moksafowo_newebpay_shipping_sandbox_fallback', static fn() => Api\Helper::is_sandbox() );
		add_filter( 'moksafowo_newebpay_shipping_merchant_id_fallback', static fn() => Api\Helper::merchant_id() );
		add_filter( 'moksafowo_newebpay_shipping_hash_key_fallback', static fn() => Api\Helper::hash_key() );
		add_filter( 'moksafowo_newebpay_shipping_hash_iv_fallback', static fn() => Api\Helper::hash_iv() );
		add_filter( 'moksafowo_newebpay_shipping_parse_order_id', [ Api\Helper::class, 'parse_order_id' ], 10, 2 );
	}

	public static function rebrand_legacy_payment_title( string $title, $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return $title;
		}
		$method = (string) $order->get_payment_method();
		if ( Gateways\Unified::GATEWAY_ID !== $method ) {
			return $title;
		}
		$pay_type = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_PAYMENT_TYPE );
		if ( '' === $pay_type ) {
			return $title;
		}
		return $title . '（' . PaymentTypeCatalog::label( $pay_type, $pay_type ) . '）';
	}
}
