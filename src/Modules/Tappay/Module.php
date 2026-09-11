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
			Gateways\Credit::GATEWAY_ID     => Gateways\Credit::class,
			// 電子錢包 —— 介面統一（TPDirect.<ns>.getPrime），共用 AbstractWalletGateway。
			Gateways\LinePay::GATEWAY_ID    => Gateways\LinePay::class,
			Gateways\JkoPay::GATEWAY_ID     => Gateways\JkoPay::class,
			Gateways\EasyWallet::GATEWAY_ID => Gateways\EasyWallet::class,
			Gateways\IPassMoney::GATEWAY_ID => Gateways\IPassMoney::class,
			Gateways\PxPayPlus::GATEWAY_ID  => Gateways\PxPayPlus::class,
			// 行動支付 —— 不導轉、要做裝置可用性偵測，共用 AbstractDeviceWalletGateway。
			Gateways\ApplePay::GATEWAY_ID   => Gateways\ApplePay::class,
			Gateways\GooglePay::GATEWAY_ID  => Gateways\GooglePay::class,
			Gateways\SamsungPay::GATEWAY_ID => Gateways\SamsungPay::class,
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

		$lines = [
			__( 'Status', 'moksa-for-woocommerce' ) => (string) ( $r['record_status'] ?? '' ),
			__( 'Amount', 'moksa-for-woocommerce' ) => (string) ( $r['amount'] ?? '' ),
			__( 'Transaction ID', 'moksa-for-woocommerce' ) => (string) ( $r['rec_trade_id'] ?? $rec ),
			__( 'Bank transaction ID', 'moksa-for-woocommerce' ) => (string) ( $r['bank_transaction_id'] ?? '' ),
		];

		// 再查一次對帳 —— query 回的是 TapPay 端的交易狀態，reconciliation 才是
		// TapPay 與銀行請退款後的實際結果，出帳爭議時要看的是後者。
		// 查不到不算失敗（當日資料 13:00 後才有，2020/10/16 前的交易也沒有）。
		$trade_id = (string) ( $r['rec_trade_id'] ?? $rec );
		if ( '' !== $trade_id ) {
			$recon = Api\Client::reconciliation( $trade_id );
			if ( ! empty( $recon['ok'] ) ) {
				$rd = (array) ( $recon['data'] ?? [] );
				$lines[ __( 'Bank settlement', 'moksa-for-woocommerce' ) ] = trim(
					(string) ( $rd['record_status'] ?? $rd['status'] ?? '' ) . ' ' .
					(string) ( $rd['bank_result_code'] ?? '' )
				);
			}
		}

		return [
			'ok'      => true,
			'message' => (string) ( $r['record_status'] ?? $r['status'] ?? 'OK' ),
			'lines'   => array_filter( $lines, static fn( string $v ): bool => '' !== $v ),
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
		add_action( 'wp_ajax_moksafowo_tappay_device_support', [ self::class, 'ajax_device_support' ] );
		add_action( 'wp_ajax_nopriv_moksafowo_tappay_device_support', [ self::class, 'ajax_device_support' ] );
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
		// 容器沒有尺寸 iframe 就看不到 —— CSS 跟 JS 一起走。
		$css_path = MOKSAFOWO_PLUGIN_DIR . 'assets/public/moksafowo-tappay-fields.css';
		wp_enqueue_style(
			'moksafowo-tappay-fields',
			MOKSAFOWO_PLUGIN_URL . 'assets/public/moksafowo-tappay-fields.css',
			[],
			file_exists( $css_path ) ? MOKSAFOWO_VERSION . '.' . filemtime( $css_path ) : MOKSAFOWO_VERSION
		);
		wp_enqueue_script( 'moksafowo-tappay-sdk' );
		wp_enqueue_script( 'moksafowo-tappay-fields' );

		// 電子錢包共用一支 —— 它們的 SDK 介面統一，靠容器上的 data 屬性分辨。
		$wallet_path = MOKSAFOWO_PLUGIN_DIR . 'assets/public/moksafowo-tappay-wallet.js';
		wp_register_script(
			'moksafowo-tappay-wallet',
			MOKSAFOWO_PLUGIN_URL . 'assets/public/moksafowo-tappay-wallet.js',
			[ 'jquery', 'moksafowo-tappay-sdk' ],
			file_exists( $wallet_path ) ? (string) filemtime( $wallet_path ) : MOKSAFOWO_VERSION,
			true
		);
		wp_localize_script(
			'moksafowo-tappay-wallet',
			'moksafowoTappayWalletSettings',
			[
				'appId'  => (int) Helper::app_id(),
				'appKey' => Helper::app_key(),
				'env'    => Helper::sdk_env(),
				'i18n'   => [
					'sdk_failed'   => __( 'Payment service is unavailable. Please try again later.', 'moksa-for-woocommerce' ),
					'not_enabled'  => __( 'This payment method is not available on this store.', 'moksa-for-woocommerce' ),
					'prime_failed' => __( 'Could not start the payment. Please try again.', 'moksa-for-woocommerce' ),
				],
			]
		);
		wp_enqueue_script( 'moksafowo-tappay-wallet' );

		// 行動支付（Apple / Google / Samsung）—— 需要裝置可用性偵測與各自的按鈕。
		$device_path = MOKSAFOWO_PLUGIN_DIR . 'assets/public/moksafowo-tappay-devicewallet.js';
		wp_register_script(
			'moksafowo-tappay-devicewallet',
			MOKSAFOWO_PLUGIN_URL . 'assets/public/moksafowo-tappay-devicewallet.js',
			[ 'jquery', 'moksafowo-tappay-sdk' ],
			file_exists( $device_path ) ? (string) filemtime( $device_path ) : MOKSAFOWO_VERSION,
			true
		);

		$gateways   = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
		$apple      = $gateways[ Gateways\ApplePay::GATEWAY_ID ] ?? null;
		$google     = $gateways[ Gateways\GooglePay::GATEWAY_ID ] ?? null;
		$cart_total = ( function_exists( 'WC' ) && WC()->cart ) ? (string) round( (float) WC()->cart->get_total( 'edit' ) ) : '0';

		wp_localize_script(
			'moksafowo-tappay-devicewallet',
			'moksafowoTappayDeviceSettings',
			[
				'ajax_url'           => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( 'moksafowo_tappay_device_support' ),
				'appId'              => (int) Helper::app_id(),
				'appKey'             => Helper::app_key(),
				'env'                => Helper::sdk_env(),
				'amount'             => $cart_total,
				'merchantLabel'      => (string) get_bloginfo( 'name' ),
				'applePayMerchantId' => $apple instanceof Gateways\ApplePay ? $apple->merchant_identifier() : '',
				'googleMerchantId'   => $google instanceof Gateways\GooglePay ? $google->google_merchant_id() : '',
				'googleMerchantName' => $google instanceof Gateways\GooglePay ? $google->merchant_name() : '',
				'i18n'               => [
					'prime_failed' => __( 'Could not start the payment. Please try again.', 'moksa-for-woocommerce' ),
					'use_button'   => __( 'Please pay with the button above.', 'moksa-for-woocommerce' ),
					'pay_apple'    => __( 'Pay with Apple Pay', 'moksa-for-woocommerce' ),
					'pay_samsung'  => __( 'Pay with Samsung Pay', 'moksa-for-woocommerce' ),
				],
			]
		);
		wp_enqueue_script( 'moksafowo-tappay-devicewallet' );
	}

	/**
	 * 前端回報裝置支不支援某個行動支付，存進顧客自己的 session。
	 *
	 * 只寫 session、不碰訂單 —— 符合 nopriv handler 的安全邊界（CLAUDE.md §4）。
	 * 值只用來決定結帳頁要不要顯示該付款方式，被竄改的最壞後果是顧客自己
	 * 看到一個按不下去的選項。
	 */
	public static function ajax_device_support(): void {
		check_ajax_referer( 'moksafowo_tappay_device_support', 'nonce' );

		$gateway = isset( $_POST['gateway'] ) ? sanitize_key( wp_unslash( $_POST['gateway'] ) ) : '';
		$allowed = [
			Gateways\ApplePay::GATEWAY_ID,
			Gateways\GooglePay::GATEWAY_ID,
			Gateways\SamsungPay::GATEWAY_ID,
		];
		if ( ! in_array( $gateway, $allowed, true ) ) {
			wp_send_json_error( [ 'message' => 'unknown gateway' ], 400 );
		}

		$supported = isset( $_POST['supported'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['supported'] ) );

		if ( function_exists( 'WC' ) ) {
			WC()->initialize_session();
			if ( WC()->session ) {
				$flags             = (array) WC()->session->get( 'moksafowo_tappay_device_support', [] );
				$flags[ $gateway ] = $supported;
				WC()->session->set( 'moksafowo_tappay_device_support', $flags );
			}
		}
		wp_send_json_success( [ 'gateway' => $gateway ] );
	}
}
