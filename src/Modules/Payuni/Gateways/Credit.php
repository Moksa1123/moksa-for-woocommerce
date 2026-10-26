<?php
namespace Moksafowo\Modules\Payuni\Gateways;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Compatibility\WpSubscription;
use Moksafowo\Modules\Payuni\Api\PaymentRequest;
use Moksafowo\Modules\Payuni\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

class Credit extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_credit';

	public function __construct() {

		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi credit card', 'moksa-for-woocommerce' );
		$this->method_description = __( 'Pay by credit card. The customer is redirected to the PAYUNi payment page.', 'moksa-for-woocommerce' );
		$this->supports           = array(
			'products',
			'refunds',
		);
		$this->init_form_fields();
		$this->init_settings();

		// 續扣走信用卡幕後 Token 交易；首期必須經 UPP 實際刷卡才拿得到 CreditHash，
		// 所以不支援顧客自行換卡（那要另一筆付款），只開放後台改 CreditHash。
		// 信用卡 Token 與幕後授權要向 PAYUNi 申請並綁 IP，商家打開設定才宣告支援，否則首期收得到、續扣全失敗。
		if ( Subscriptions::active() && 'yes' === get_option( 'moksafowo_payuni_payment_subscriptions_enabled', 'no' ) ) {
			$this->supports = array_merge(
				$this->supports,
				array(
					'subscriptions',
					'subscription_cancellation',
					'subscription_suspension',
					'subscription_reactivation',
					'subscription_amount_changes',
					'subscription_date_changes',
					'subscription_payment_method_change_admin',
					'multiple_subscriptions',
				)
			);
		}

		$this->title                      = $this->get_option( 'title' );
		$this->description                = $this->get_option( 'description' );
		$this->incomplete_payment_message = $this->get_option( 'incomplete_payment_message' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'moksafowo_payuni_payment_credit_transaction_arrgs' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = include MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Payuni/Settings/CreditSetting.php';
	}

	public function moksafowo_payuni_payment_credit_transaction_arrgs( $args, $order ) {
		$args = array_merge(
			$args,
			array(
				'Credit' => '1',
			)
		);
		// 訂閱：首期請持卡人約定信用卡（3=強制約定，顧客不能在付款頁取消），之後用 CreditHash 幕後續扣。
		// CreditToken 用每位會員專屬的隨機識別碼：用可預測的會員編號，兩個站共用同一個 PAYUNi 商店時會對到別人約定的卡。
		$member_id = Subscriptions::member_id( $order );
		// WPSubscription 允許訪客買訂閱：沒有會員帳號時每張訂單一組隨機識別碼，續扣只靠回傳的 CreditHash。
		if ( '' === $member_id && self::wps_enabled() && WpSubscription::order_has_subscription( $order ) ) {
			$member_id = 'mowp' . bin2hex( random_bytes( 12 ) );
		}
		if ( '' !== $member_id && self::renews_subscription( $order ) ) {
			$args = array_merge(
				$args,
				array(
					'CreditToken'     => $member_id,
					'UseTokenType'    => 3,
					'CreditTokenType' => 2,
				)
			);
		}
		return $args;
	}

	public function is_available() {
		// 首期 0 元（試用期）沒有 UPP 交易可以約定信用卡，拿不到續扣用的 CreditHash。
		if ( WC()->cart && 0 >= $this->get_order_total() ) {
			if ( class_exists( 'WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription() ) {
				return false;
			}
			if ( self::wps_enabled() && [] !== WpSubscription::cart_recurrings() ) {
				return false;
			}
		}
		return parent::is_available();
	}

	/** 這張訂單要約定信用卡、之後幕後續扣（WooCommerce Subscriptions 或 WPSubscription）。 */
	public static function renews_subscription( \WC_Order $order ): bool {
		if ( self::wps_enabled() && WpSubscription::order_has_subscription( $order ) ) {
			return true;
		}
		$gateway = WC()->payment_gateways() ? ( WC()->payment_gateways()->payment_gateways()[ self::GATEWAY_ID ] ?? null ) : null;
		return $gateway instanceof self && $gateway->supports( 'subscriptions' ) && Subscriptions::order_has_subscription( $order );
	}

	/** WPSubscription 的續扣跟 WooCommerce Subscriptions 共用同一個開關。 */
	public static function wps_enabled(): bool {
		return WpSubscription::active() && 'yes' === get_option( 'moksafowo_payuni_payment_subscriptions_enabled', 'no' );
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$request = new PaymentRequest();
		return $request->refund( $order_id, $amount, $reason );
	}

	public static function get_payment_order_metas() {
		return array(
			OrderMeta::CREDIT_AUTH_TYPE  => __( 'Authorization method', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_AUTH_DAY   => __( 'Authorization date', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_AUTH_TIME  => __( 'Authorized at', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_AUTH_CODE  => __( 'Bank authorization code', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_CARD_4NO   => __( 'Last four card digits', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_BANK       => __( 'Issuing bank', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_LOCATION   => __( 'Foreign card', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_ECI        => __( '3-D Secure ECI', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_RED_AMT    => __( 'Reward points redeemed', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_RED_NO     => __( 'Reward redemption ID', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_TOKEN_ID   => __( 'Token ID', 'moksa-for-woocommerce' ),
			OrderMeta::CREDIT_TOKEN_LIFE => __( 'Token expiry', 'moksa-for-woocommerce' ),
		);
	}
}
