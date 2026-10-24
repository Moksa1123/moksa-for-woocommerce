<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Gateways;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Modules\Newebpay\Api\Helper;
use Moksafowo\Modules\Newebpay\Api\Period;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 藍新信用卡定期定額（NDNP-1.0.8）：給 WooCommerce Subscriptions 用，扣款由藍新依委託排程執行。
 *
 * 首期以「立即執行委託金額授權」（PeriodStartType=2）建立委託，這筆授權就是首期款；
 * 之後每期結果由 PeriodHandler 收到通知時建立續訂單。委託的金額與扣款日在建立時固定，
 * 所以只接受「單一訂閱、無試用期與開辦費、首期金額等於每期金額」而且週期對得上藍新格式的訂單。
 */
final class CreditPeriod extends AbstractNewebpayGateway {

	public const GATEWAY_ID = 'moksafowo_newebpay_credit_period';

	/** 委託最多 99 期（PER10024）。 */
	private const MAX_TIMES = 99;

	public function __construct() {
		$this->id = self::GATEWAY_ID;
		parent::__construct();
	}

	protected function build_method_title(): string {
		return __( 'NewebPay recurring credit card', 'moksa-for-woocommerce' );
	}

	protected function build_method_description(): string {
		return __( 'For WooCommerce Subscriptions. NewebPay charges the card automatically on each billing date. Works for one subscription per order with no free trial or sign-up fee, billed every N days (2 or more), weekly, monthly or yearly. NewebPay must enable recurring payments for your store.', 'moksa-for-woocommerce' );
	}

	protected function gateway_supports(): array {
		$supports = [ 'products', 'refunds' ];
		if ( Subscriptions::active() ) {
			$supports = array_merge( $supports, [ 'subscriptions', 'gateway_scheduled_payments', 'subscription_cancellation' ] );
			// 暫停／恢復要呼叫修改委託狀態（NPA-B051），藍新審核開通後才能用；沒開通時暫停了藍新照扣，所以不開放。
			if ( 'yes' === $this->get_option( 'mandate_changes', 'no' ) ) {
				$supports = array_merge( $supports, [ 'subscription_suspension', 'subscription_reactivation' ] );
			}
		}
		return $supports;
	}

	protected function build_form_fields(): array {
		return array_merge(
			parent::build_form_fields(),
			[
				'mandate_changes' => [
					'title'       => __( 'Mandate status changes', 'moksa-for-woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'NewebPay has enabled mandate status changes for my store', 'moksa-for-woocommerce' ),
					'description' => __( 'Lets subscriptions be paused and resumed. Only tick this after NewebPay approves it, otherwise a paused subscription keeps being charged. Cancelling always tries to stop the mandate; if that fails you get an email and must stop it in the NewebPay dashboard.', 'moksa-for-woocommerce' ),
					'default'     => 'no',
				],
			]
		);
	}

	protected function register_receipt_action(): void {
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'render_period_form' ] );
	}

	protected function payment_type_flags(): array {
		return [];
	}

	public function is_available(): bool {
		if ( ! Subscriptions::active() || ! parent::is_available() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			return $order instanceof \WC_Order && null !== self::schedule_for_order( $order );
		}
		return self::cart_is_eligible();
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( esc_html__( 'The order could not be found', 'moksa-for-woocommerce' ) );
		}
		if ( null === self::schedule_for_order( $order ) ) {
			wc_add_notice( __( 'NewebPay recurring payments only work for one subscription billed every N days (2 or more), weekly, monthly or yearly, with no free trial or sign-up fee. Please choose another payment method.', 'moksa-for-woocommerce' ), 'error' );
			return [
				'result'   => 'failure',
				'redirect' => '',
			];
		}
		$mtn = Helper::generate_merchant_order_no( (int) $order_id );
		Helper::remember_mtn( $order, $mtn );
		$order->save();
		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}

	public function render_period_form( $order_id ): void {
		$order    = wc_get_order( $order_id );
		$schedule = $order instanceof \WC_Order ? self::schedule_for_order( $order ) : null;
		if ( ! $order instanceof \WC_Order || $order->is_paid() || null === $schedule ) {
			return;
		}

		$mtn = (string) $order->get_meta( Keys::NEWEBPAY_MERCHANT_ORDER_NO );
		if ( '' === $mtn ) {
			$mtn = Helper::generate_merchant_order_no( $order->get_id() );
			Helper::remember_mtn( $order, $mtn );
		}
		$order->update_meta_data( Keys::NEWEBPAY_PERIOD_ORDER_NO, $mtn );
		$order->save();

		$form = Period::create_form(
			[
				'LangType'        => 'zh-Tw',
				'MerOrderNo'      => $mtn,
				'ProdDesc'        => self::prod_desc( $order ),
				'PeriodAmt'       => (int) ceil( (float) $order->get_total() ),
				'PeriodType'      => $schedule['type'],
				'PeriodPoint'     => $schedule['point'],
				'PeriodStartType' => 2,
				'PeriodTimes'     => (string) $schedule['times'],
				'ReturnURL'       => add_query_arg(
					[
						'order_id' => $order->get_id(),
						'key'      => $order->get_order_key(),
					],
					home_url( '/wc-api/moksafowo_newebpay_period_return' )
				),
				'NotifyURL'       => home_url( '/wc-api/moksafowo_newebpay_period_notify' ),
				'BackURL'         => $order->get_checkout_payment_url(),
				'PayerEmail'      => (string) $order->get_billing_email(),
				'EmailModify'     => 0,
				'PaymentInfo'     => 'N',
				'OrderInfo'       => 'N',
			]
		);

		Helper::log(
			'period create redirect',
			[
				'order_id' => $order->get_id(),
				'mtn'      => $mtn,
				'type'     => $schedule['type'],
				'point'    => $schedule['point'],
				'times'    => $schedule['times'],
			]
		);
		?>
		<form method="post" id="moksafowo-newebpay-period-form" action="<?php echo esc_url( Period::create_url() ); ?>">
			<?php foreach ( $form as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="button alt"><?php esc_html_e( 'Pay with NewebPay', 'moksa-for-woocommerce' ); ?></button>
		</form>
		<?php
		wp_print_inline_script_tag( 'document.getElementById("moksafowo-newebpay-period-form").submit();' );
	}

	/**
	 * 訂單對應的藍新委託排程；不符合定期定額條件時回 null。
	 *
	 * @return array{type:string,point:string,times:int}|null
	 */
	public static function schedule_for_order( \WC_Order $order ): ?array {
		$subscriptions = Subscriptions::subscriptions_for( $order );
		if ( 1 !== count( $subscriptions ) ) {
			return null;
		}
		$sub = $subscriptions[0];
		// 委託金額固定：首期（這張訂單）要等於每期金額，也不能有試用期。
		if ( abs( (float) $sub->get_total() - (float) $order->get_total() ) > 0.001 || 0 !== (int) $sub->get_time( 'trial_end' ) ) {
			return null;
		}
		$start = (int) $sub->get_time( 'start' );
		$end   = (int) $sub->get_time( 'end' );
		return self::map_schedule( (string) $sub->get_billing_period(), (int) $sub->get_billing_interval(), $start > 0 ? $start : time(), $end );
	}

	/**
	 * WooCommerce 的計費週期換成藍新的 PeriodType／PeriodPoint（4.3.1）。
	 * 藍新每月、每年只能「每 1 個」週期，每 N 天最少 2 天；對不上的回 null。
	 *
	 * @return array{type:string,point:string,times:int}|null
	 */
	public static function map_schedule( string $period, int $interval, int $start, int $end ): ?array {
		$interval = max( 1, $interval );
		switch ( $period ) {
			case 'day':
				if ( $interval < 2 || $interval > 999 ) {
					return null;
				}
				$type  = 'D';
				$point = (string) $interval;
				break;
			case 'week':
				if ( 1 === $interval ) {
					$type  = 'W';
					$point = wp_date( 'N', $start );
				} elseif ( $interval * 7 <= 999 ) {
					$type  = 'D';
					$point = (string) ( $interval * 7 );
				} else {
					return null;
				}
				break;
			case 'month':
				if ( 1 !== $interval ) {
					return null;
				}
				$type  = 'M';
				$point = wp_date( 'd', $start );
				break;
			case 'year':
				if ( 1 !== $interval ) {
					return null;
				}
				$type  = 'Y';
				$point = wp_date( 'md', $start );
				break;
			default:
				return null;
		}

		// 沒有結束日的訂閱以上限 99 期建立；藍新另有「卡片到期日早於期數時自動截到到期日」的規則，
		// 期數扣完後 PeriodHandler::mandate_finished 會把訂閱轉保留。
		$times = self::MAX_TIMES;
		if ( $end > $start && function_exists( 'wcs_estimate_periods_between' ) ) {
			$times = (int) ceil( (int) wcs_estimate_periods_between( $start, $end, $period ) / $interval );
			// 只扣一期的訂閱不需要委託；超過 99 期藍新不收（PER10024）。
			if ( $times < 2 || $times > self::MAX_TIMES ) {
				return null;
			}
		}
		return [
			'type'  => $type,
			'point' => (string) $point,
			'times' => $times,
		];
	}

	/**
	 * 結帳頁：全部是同一週期的訂閱商品（WCS 合併成一張訂閱）、沒有試用期與開辦費、本次金額等於每期金額，且週期對得上。
	 * 混一般商品或不同週期時首期金額與每期不同、或需要兩張委託，藍新做不到。
	 */
	private static function cart_is_eligible(): bool {
		$cart = WC()->cart;
		if ( ! $cart || ! class_exists( 'WC_Subscriptions_Cart' ) || ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return false;
		}
		if ( ! \WC_Subscriptions_Cart::cart_contains_subscription() || \WC_Subscriptions_Cart::cart_contains_free_trial() ) {
			return false;
		}
		// WCS 建立訂閱時會把 WC()->cart 暫時換成續訂購物車再查可用付款方式；這裡判定不可用，
		// 訂閱就不會記下付款方式而變成手動續訂。續訂購物車沒有首期可比，金額由 schedule_for_order 把關。
		if ( empty( $cart->recurring_cart_key ) ) {
			$recurring = is_array( $cart->recurring_carts ?? null ) ? $cart->recurring_carts : [];
			if ( 1 !== count( $recurring ) ) {
				return false;
			}
			$recurring_cart = reset( $recurring );
			if ( abs( (float) $recurring_cart->get_total( 'edit' ) - (float) $cart->get_total( 'edit' ) ) > 0.001 ) {
				return false;
			}
		}
		$first = null;
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product || ! \WC_Subscriptions_Product::is_subscription( $product ) || (float) \WC_Subscriptions_Product::get_sign_up_fee( $product ) > 0 ) {
				return false;
			}
			$first ??= $product;
		}
		if ( null === $first ) {
			return false;
		}
		$length = (int) \WC_Subscriptions_Product::get_length( $first );
		$period = (string) \WC_Subscriptions_Product::get_period( $first );
		$start  = time();
		$end    = $length > 0 ? (int) strtotime( '+' . $length . ' ' . $period, $start ) : 0;
		return null !== self::map_schedule( $period, (int) \WC_Subscriptions_Product::get_interval( $first ), $start, $end );
	}

	/** 產品名稱只能有中英數、空格、底線，其餘符號轉成空格（4.3.1、PER10029）。 */
	private static function prod_desc( \WC_Order $order ): string {
		$names = [];
		foreach ( $order->get_items() as $item ) {
			$names[] = (string) $item->get_name();
		}
		$desc = (string) preg_replace( '/[^\p{Han}A-Za-z0-9 _]+/u', ' ', implode( ' ', $names ) );
		$desc = trim( (string) preg_replace( '/\s+/', ' ', $desc ) );
		return '' !== $desc ? mb_substr( $desc, 0, 100 ) : 'Subscription';
	}
}
