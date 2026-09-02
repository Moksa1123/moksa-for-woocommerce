<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Pchomepay;

use Moksafowo\Modules\Shared\AbstractGatewayModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractGatewayModule {

	public function slug(): string {
		return 'pchomepay';
	}

	public function label(): string {
		return __( 'PChomePay — credit card, Pi Wallet, ATM, convenience store code and store pickup', 'moksa-for-woocommerce' );
	}

	public function name(): string {
		return __( 'PChomePay', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'Credit card, Pi Wallet, ATM, convenience store code and pickup with payment', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( 'Credit card', 'moksa-for-woocommerce' ),
			__( 'Pi Wallet', 'moksa-for-woocommerce' ),
			__( 'ATM virtual account', 'moksa-for-woocommerce' ),
			__( 'Convenience store code payment', 'moksa-for-woocommerce' ),
			__( '7-ELEVEN pickup and pay', 'moksa-for-woocommerce' ),
			__( 'FamilyMart pickup and pay', 'moksa-for-woocommerce' ),
			__( 'Hi-Life pickup and pay', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'pchomepay';
	}

	public static function gateway_map(): array {
		return [
			Gateways\Card::GATEWAY_ID      => Gateways\Card::class,
			Gateways\Pi::GATEWAY_ID        => Gateways\Pi::class,
			Gateways\Atm::GATEWAY_ID       => Gateways\Atm::class,
			Gateways\Barcode::GATEWAY_ID   => Gateways\Barcode::class,
			Gateways\Cvs711::GATEWAY_ID    => Gateways\Cvs711::class,
			Gateways\CvsFamily::GATEWAY_ID => Gateways\CvsFamily::class,
			Gateways\CvsHilife::GATEWAY_ID => Gateways\CvsHilife::class,
		];
	}

	protected static function blocks_method_class(): string {
		return Blocks\PchomepayBlocksMethod::class;
	}

	protected function register_webhooks(): void {
		add_action( 'woocommerce_api_moksafowo_pchomepay_payment', [ Api\IpnHandler::class, 'handle' ] );
	}

	/**
	 * 向支付連查這筆訂單的權威付款狀態。webhook 本來就用同一支 API 做二次確認，
	 * 這裡只是讓商家能自己按一下。
	 *
	 * @return array{ok:bool,message:string,lines:array<string,string>}
	 */
	public static function query_payment_status( \WC_Order $order ): array {
		$oid = (string) $order->get_meta( Moksafowo\Order\Meta\Keys::PCHOMEPAY_ORDER_ID );
		if ( '' === $oid ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no PChomePay transaction to look up yet.', 'moksa-for-woocommerce' ),
				'lines'   => [],
			];
		}
		$res = Api\Helper::api_get_payment( $oid );
		if ( empty( $res['ok'] ) ) {
			return [
				'ok'      => false,
				'message' => (string) ( $res['code'] ?? __( 'PChomePay could not be reached.', 'moksa-for-woocommerce' ) ),
				'lines'   => [],
			];
		}
		$d = (array) ( $res['data'] ?? [] );
		return [
			'ok'      => true,
			'message' => (string) ( $d['status'] ?? 'OK' ),
			'lines'   => array_filter(
				[
					__( 'Status', 'moksa-for-woocommerce' )         => (string) ( $d['status'] ?? '' ),
					__( 'Payment type', 'moksa-for-woocommerce' )   => (string) ( $d['payment_type'] ?? '' ),
					__( 'Amount', 'moksa-for-woocommerce' )         => (string) ( $d['trade_amount'] ?? '' ),
					__( 'Paid at', 'moksa-for-woocommerce' )        => (string) ( $d['pay_date'] ?? '' ),
					__( 'Transaction ID', 'moksa-for-woocommerce' ) => $oid,
				],
				static fn( string $v ): bool => '' !== $v
			),
		];
	}

	protected function boot_extras(): void {
		add_filter(
			'moksafowo_payment_query_handlers',
			static function ( array $h ): array {
				$h['moksafowo_pchomepay_'] = [ __CLASS__, 'query_payment_status' ];
				return $h;
			}
		);

		// 顧客端取號繳費資訊（ATM 虛擬帳號 / 超商代碼 / 條碼）。
		Frontend\CustomerPaymentInfo::init();
	}
}
