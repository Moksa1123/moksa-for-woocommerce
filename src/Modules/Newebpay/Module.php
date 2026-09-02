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
		return __( 'NewebPay payments — credit card, credit card instalments, ATM, WebATM, convenience store code and barcode', 'moksa-for-woocommerce' );
	}

	public function name(): string {
		return __( 'NewebPay payments', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'Credit card, ATM, convenience store, mobile payments, instalments and pay later', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( 'Credit card', 'moksa-for-woocommerce' ),
			__( 'Credit card instalments', 'moksa-for-woocommerce' ),
			__( 'ATM', 'moksa-for-woocommerce' ),
			__( 'WebATM', 'moksa-for-woocommerce' ),
			__( 'Convenience store code', 'moksa-for-woocommerce' ),
			__( 'Convenience store barcode', 'moksa-for-woocommerce' ),
			__( 'Apple Pay', 'moksa-for-woocommerce' ),
			__( 'Google Pay', 'moksa-for-woocommerce' ),
			__( 'Samsung Pay', 'moksa-for-woocommerce' ),
			__( 'LINE Pay', 'moksa-for-woocommerce' ),
			__( 'E.SUN Wallet', 'moksa-for-woocommerce' ),
			__( 'Taiwan Pay', 'moksa-for-woocommerce' ),
			__( 'TWQR', 'moksa-for-woocommerce' ),
			__( 'Alipay', 'moksa-for-woocommerce' ),
			__( 'WeChat Pay', 'moksa-for-woocommerce' ),
			__( 'AFTEE buy now, pay later', 'moksa-for-woocommerce' ),
			__( 'UnionPay', 'moksa-for-woocommerce' ),
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

	/**
	 * 向藍新查這筆訂單的權威付款狀態。只讀不寫金流狀態 —— 要不要據此改訂單狀態
	 * 是商家的決定，這裡只把事實寫進備註讓他判斷。
	 *
	 * @return array{ok:bool,message:string,lines:array<string,string>}
	 */
	public static function query_payment_status( \WC_Order $order ): array {
		$mtn = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::NEWEBPAY_MERCHANT_ORDER_NO );
		if ( '' === $mtn ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no NewebPay transaction to look up yet.', 'moksa-for-woocommerce' ),
				'lines'   => [],
			];
		}

		$res = Api\PaymentRequest::query( $mtn, (int) round( (float) $order->get_total() ) );
		if ( empty( $res['ok'] ) ) {
			return [
				'ok'      => false,
				'message' => (string) ( $res['message'] ?? '' ),
				'lines'   => [],
			];
		}

		$d     = (array) ( $res['data'] ?? [] );
		$lines = array_filter(
			[
				__( 'Trade status', 'moksa-for-woocommerce' ) => (string) ( $d['TradeStatus'] ?? '' ),
				__( 'Payment type', 'moksa-for-woocommerce' ) => (string) ( $d['PaymentType'] ?? '' ),
				__( 'Amount', 'moksa-for-woocommerce' )  => (string) ( $d['Amt'] ?? '' ),
				__( 'Paid at', 'moksa-for-woocommerce' ) => (string) ( $d['PayTime'] ?? '' ),
				__( 'Transaction ID', 'moksa-for-woocommerce' ) => (string) ( $d['TradeNo'] ?? '' ),
			],
			static fn( string $v ): bool => '' !== $v
		);

			return [
				'ok'      => true,
				'message' => (string) ( $d['TradeStatus'] ?? 'OK' ),
				'lines'   => $lines,
			];
	}

	protected function register_webhooks(): void {
		add_action( 'woocommerce_api_moksafowo_newebpay_payment', [ Api\IpnHandler::class, 'handle' ] );
	}

	protected function boot_extras(): void {
		add_filter( 'woocommerce_order_get_payment_method_title', [ __CLASS__, 'rebrand_legacy_payment_title' ], 10, 2 );

		// 後台「查詢付款狀態」按鈕 —— 走共用層，UI 與權限只有一份。
		add_filter(
			'moksafowo_payment_query_handlers',
			static function ( array $h ): array {
				$h['moksafowo_newebpay_'] = [ __CLASS__, 'query_payment_status' ];
				return $h;
			}
		);

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
