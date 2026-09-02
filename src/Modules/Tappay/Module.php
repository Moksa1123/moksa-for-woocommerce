<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Tappay;

use Moksafowo\Modules\Shared\AbstractGatewayModule;
use Moksafowo\Modules\Tappay\Api\Helper;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractGatewayModule {

	public function slug(): string {
		return 'tappay';
	}

	public function label(): string {
		return __( 'TapPay — card payments taken on your own site, with 3-D Secure', 'moksa-for-woocommerce' );
	}

	public function name(): string {
		return __( 'TapPay', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( 'Secure card payments without leaving your site', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( 'Credit card', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'tappay';
	}

	public static function gateway_map(): array {
		return [
			Gateways\Credit::GATEWAY_ID => Gateways\Credit::class,
		];
	}

	protected static function blocks_method_class(): string {
		return Blocks\TappayBlocksMethod::class;
	}

	protected static function uses_allowlist(): bool {
		return false;
	}

	protected function register_webhooks(): void {
		add_action( 'woocommerce_api_moksafowo_tappay_notify', [ Api\IpnHandler::class, 'handle_notify' ] );
		add_action( 'woocommerce_api_moksafowo_tappay_result', [ Api\IpnHandler::class, 'handle_result' ] );
	}

	/**
	 * 向 TapPay 查這筆交易的權威狀態。優先用 rec_trade_id（交易主鍵），
	 * 沒有就退回用我們送出去的 order_number 查。
	 *
	 * @return array{ok:bool,message:string,lines:array<string,string>}
	 */
	public static function query_payment_status( \WC_Order $order ): array {
		$rec = (string) $order->get_meta( Moksafowo\Order\Meta\Keys::TAPPAY_REC_TRADE_ID );
		$res = '' !== $rec
			? Api\Client::query_by_rec_trade_id( $rec )
			: Api\Client::query_by_order_number( Api\Helper::build_order_number( $order ) );

		if ( empty( $res['ok'] ) ) {
			return [
				'ok'      => false,
				'message' => (string) ( $res['message'] ?? __( 'TapPay could not be reached.', 'moksa-for-woocommerce' ) ),
				'lines'   => [],
			];
		}

		$records = (array) ( $res['data']['trade_records'] ?? [] );
		$r       = is_array( $records[0] ?? null ) ? $records[0] : [];
		if ( ! $r ) {
			return [
				'ok'      => false,
				'message' => __( 'TapPay has no record of this transaction yet.', 'moksa-for-woocommerce' ),
				'lines'   => [],
			];
		}

		return [
			'ok'      => true,
			'message' => (string) ( $r['record_status'] ?? $r['status'] ?? 'OK' ),
			'lines'   => array_filter(
				[
					__( 'Status', 'moksa-for-woocommerce' )         => (string) ( $r['record_status'] ?? '' ),
					__( 'Amount', 'moksa-for-woocommerce' )         => (string) ( $r['amount'] ?? '' ),
					__( 'Transaction ID', 'moksa-for-woocommerce' ) => (string) ( $r['rec_trade_id'] ?? $rec ),
					__( 'Bank transaction ID', 'moksa-for-woocommerce' ) => (string) ( $r['bank_transaction_id'] ?? '' ),
				],
				static fn( string $v ): bool => '' !== $v
			),
		];
	}

	protected function boot_extras(): void {
		add_filter(
			'moksafowo_payment_query_handlers',
			static function ( array $h ): array {
				$h['moksafowo_tappay'] = [ __CLASS__, 'query_payment_status' ];
				return $h;
			}
		);
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_checkout_assets' ], 20 );
	}

	public static function enqueue_checkout_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( ! Helper::has_credentials() ) {
			return;
		}
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) {
			return;
		}

		wp_register_script( 'moksafowo-tappay-sdk', Helper::SDK_URL, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion,WordPress.WP.EnqueuedResourceParameters.NotInFooter

		$path    = MOKSAFOWO_PLUGIN_DIR . 'assets/public/moksafowo-tappay-fields.js';
		$version = file_exists( $path ) ? MOKSAFOWO_VERSION . '.' . filemtime( $path ) : MOKSAFOWO_VERSION;
		wp_register_script(
			'moksafowo-tappay-fields',
			MOKSAFOWO_PLUGIN_URL . 'assets/public/moksafowo-tappay-fields.js',
			[ 'jquery', 'moksafowo-tappay-sdk' ],
			$version,
			true
		);
		wp_localize_script(
			'moksafowo-tappay-fields',
			'moksafowoTappaySettings',
			[
				'gatewayId' => Gateways\Credit::GATEWAY_ID,
				'appId'     => (int) Helper::app_id(),
				'appKey'    => Helper::app_key(),
				'env'       => Helper::sdk_env(),
				'i18n'      => [
					'incomplete' => __( 'Please fill in all the card details.', 'moksa-for-woocommerce' ),
					'primeError' => __( 'No payment token was returned. Please check the card number.', 'moksa-for-woocommerce' ),
				],
			]
		);
		wp_enqueue_script( 'moksafowo-tappay-sdk' );
		wp_enqueue_script( 'moksafowo-tappay-fields' );
	}
}
