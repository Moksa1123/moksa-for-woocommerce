<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Admin;

use Moksafowo\Modules\Ecpay\Api\Helper;
use Moksafowo\Modules\Ecpay\Gateways\AbstractEcpayGateway;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class CreditLifecycleBox {

	private const NONCE_ACTION = 'moksafowo_ecpay_credit_lifecycle';

	public static function init(): void {
		add_action( 'wp_ajax_moksafowo_ecpay_credit_query', [ __CLASS__, 'ajax_query' ] );
		add_action( 'wp_ajax_moksafowo_ecpay_credit_action', [ __CLASS__, 'ajax_action' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function lifecycle_html( \WC_Order $order ): string {
		if ( ! self::is_credit_order( $order ) ) {
			return '';
		}
		$nonce = wp_create_nonce( self::NONCE_ACTION );
		ob_start();
		?>
		<div class="moksafowo-ecpay-credit-lifecycle" style="margin-top:10px;padding-top:8px;border-top:1px dashed #c0c0c0;"
			data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-total="<?php echo esc_attr( (string) (int) round( (float) $order->get_total() ) ); ?>">
			<p style="margin:0 0 4px;color:#646970;font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
				<?php esc_html_e( 'Card transaction actions', 'moksa-for-woocommerce' ); ?>
			</p>
			<p class="moksafowo-ecpay-credit-lifecycle__placeholder" style="margin:0;color:#646970;font-size:12px;">
				<?php esc_html_e( 'Use the button below to fetch the latest transaction status…', 'moksa-for-woocommerce' ); ?>
			</p>
			<p style="margin:8px 0 0;">
				<button type="button" class="button button-secondary moksafowo-ecpay-credit-lifecycle__refresh">
					<?php esc_html_e( 'Check transaction status', 'moksa-for-woocommerce' ); ?>
				</button>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function ajax_query(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! self::is_credit_order( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found, or it was not paid by card.', 'moksa-for-woocommerce' ) ] );
		}

		$result = Helper::query_credit_trade( $order );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [ 'html' => self::build_panel_html( $order, $result ) ] );
	}

	public static function ajax_action(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$action   = isset( $_POST['credit_action'] ) ? sanitize_text_field( wp_unslash( $_POST['credit_action'] ) ) : '';
		$amount   = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;
		if ( ! in_array( $action, [ 'N', 'C', 'R' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid action.', 'moksa-for-woocommerce' ) ] );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! self::is_credit_order( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found, or it was not paid by card.', 'moksa-for-woocommerce' ) ] );
		}

		$run_amount = ( 'R' === $action ) ? max( 1, $amount ) : (int) round( (float) $order->get_total() );
		$result     = Helper::credit_action( $order, $action, $run_amount );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
				/* translators: 1: action label, 2: error msg */
					__( 'ECPay %1$s failed: %2$s', 'moksa-for-woocommerce' ),
					self::action_label( $action ),
					$result->get_error_message()
				)
			);
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$rtn_code = (int) ( $result['RtnCode'] ?? 0 );
		$rtn_msg  = (string) ( $result['RtnMsg'] ?? '' );
		if ( 1 !== $rtn_code ) {
			$msg = sprintf(
				/* translators: 1: action label, 2: ECPay msg, 3: code */
				__( 'ECPay %1$s failed: %2$s (code %3$d)', 'moksa-for-woocommerce' ),
				self::action_label( $action ),
				$rtn_msg,
				$rtn_code
			);
			$order->add_order_note( $msg );
			wp_send_json_error( [ 'message' => $msg ] );
		}

		$order->add_order_note(
			sprintf(
			/* translators: 1: action label, 2: amount */
				__( 'ECPay %1$s succeeded (NT$%2$d).', 'moksa-for-woocommerce' ),
				self::action_label( $action ),
				$run_amount
			)
		);

		$query = Helper::query_credit_trade( $order );
		if ( is_wp_error( $query ) ) {
			wp_send_json_success( [ 'html' => '<p style="color:#00a32a;">' . esc_html( __( 'The action succeeded, but the latest status could not be fetched. Please refresh the page.', 'moksa-for-woocommerce' ) ) . '</p>' ] );
		}
		wp_send_json_success( [ 'html' => self::build_panel_html( $order, $query ) ] );
	}

	public static function enqueue( string $hook ): void {
		$ok_screens = [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ];
		if ( ! in_array( $hook, $ok_screens, true ) ) {
			return;
		}
		$path = MOKSAFOWO_PLUGIN_DIR . 'assets/admin/ecpay-credit-lifecycle.js';
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOWO_VERSION;
		wp_enqueue_script(
			'moksafowo-ecpay-credit-lifecycle',
			MOKSAFOWO_PLUGIN_URL . 'assets/admin/ecpay-credit-lifecycle.js',
			[ 'jquery' ],
			$ver,
			true
		);
		wp_localize_script(
			'moksafowo-ecpay-credit-lifecycle',
			'moksafowoEcpayCreditLifecycle',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => [
					'querying'       => __( 'Looking that up…', 'moksa-for-woocommerce' ),
					'cancelConfirm'  => __( 'Cancel this authorization? The hold on the customer\'s card is released and this cannot be undone.', 'moksa-for-woocommerce' ),
					'closureConfirm' => __( 'Capture this payment? The customer\'s card will actually be charged.', 'moksa-for-woocommerce' ),
					'refundConfirm'  => __( 'Issue a refund? Amount:', 'moksa-for-woocommerce' ),
					'refundAmtErr'   => __( 'The refund amount must be greater than 0.', 'moksa-for-woocommerce' ),
					'genericErr'     => __( 'The action failed:', 'moksa-for-woocommerce' ),
				],
			]
		);
	}

	private static function build_panel_html( \WC_Order $order, array $info ): string {
		$rtn       = is_array( $info['RtnValue'] ?? null ) ? $info['RtnValue'] : [];
		$status    = (string) ( $rtn['Status'] ?? '' );
		$amount    = (int) ( $rtn['Amount'] ?? 0 );
		$cls_amt   = (int) ( $rtn['ClsAmt'] ?? 0 );
		$auth_time = (string) ( $rtn['AuthTime'] ?? '' );
		$history   = is_array( $info['CloseData'] ?? null ) ? $info['CloseData'] : [];

		$total      = (int) round( (float) $order->get_total() );
		$can_refund = max( 0, $cls_amt - 0 );

		$status_label = self::status_label( $status );
		$status_color = self::status_color( $status );

		ob_start();
		?>
		<div class="moksafowo-ecpay-credit-lifecycle__info">
			<p style="margin:0 0 6px;">
				<strong><?php esc_html_e( 'Transaction status', 'moksa-for-woocommerce' ); ?>：</strong>
				<span style="color:<?php echo esc_attr( $status_color ); ?>;font-weight:600;">
					<?php echo esc_html( $status_label ); ?>
				</span>
			</p>
			<p style="margin:0 0 6px;">
				<strong><?php esc_html_e( 'Authorized amount', 'moksa-for-woocommerce' ); ?>：</strong>NT$<?php echo esc_html( (string) $amount ); ?>
			</p>
			<p style="margin:0 0 6px;">
				<strong><?php esc_html_e( 'Captured amount', 'moksa-for-woocommerce' ); ?>：</strong>NT$<?php echo esc_html( (string) $cls_amt ); ?>
			</p>
			<?php if ( '' !== $auth_time ) : ?>
				<p style="margin:0 0 6px;">
					<strong><?php esc_html_e( 'Authorized at', 'moksa-for-woocommerce' ); ?>：</strong><?php echo esc_html( $auth_time ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $history ) ) : ?>
				<details style="margin:8px 0;">
					<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Transaction history', 'moksa-for-woocommerce' ); ?> (<?php echo count( $history ); ?>)</summary>
					<ul style="margin:6px 0 0 0;padding-left:18px;font-size:12px;">
						<?php foreach ( $history as $h ) : ?>
							<li style="margin-bottom:4px;">
								<?php echo esc_html( self::status_label( (string) ( $h['Status'] ?? '' ) ) ); ?>
								— NT$<?php echo esc_html( (string) ( $h['Amount'] ?? '' ) ); ?>
								<?php if ( ! empty( $h['DateTime'] ) ) : ?>
									<br><span style="color:#646970;"><?php echo esc_html( (string) $h['DateTime'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>

		<div class="moksafowo-ecpay-credit-lifecycle__actions" style="margin-top:12px;border-top:1px solid #e0e0e0;padding-top:10px;">
			<?php if ( 'Authorized' === $status ) : ?>
				<p style="margin:0 0 6px;color:#646970;font-size:11px;">
					<?php esc_html_e( 'This payment is authorized but not captured. Capture it to charge the customer, or cancel the authorization to release the hold.', 'moksa-for-woocommerce' ); ?>
				</p>
				<button type="button" class="button moksafowo-ecpay-credit-lifecycle__action" data-action="N">
					<?php esc_html_e( 'Cancel authorization', 'moksa-for-woocommerce' ); ?>
				</button>
				&nbsp;
				<button type="button" class="button button-primary moksafowo-ecpay-credit-lifecycle__action" data-action="C">
					<?php
					/* translators: %d: 請款金額 */
					echo esc_html( sprintf( __( 'Capture NT$%d', 'moksa-for-woocommerce' ), $total ) );
					?>
				</button>
			<?php elseif ( in_array( $status, [ 'Captured', 'To be captured' ], true ) ) : ?>
				<p style="margin:0 0 6px;color:#646970;font-size:11px;">
					<?php
					/* translators: %d: 已請款金額 */
					echo esc_html( sprintf( __( 'NT$%d captured. You can refund part or all of it.', 'moksa-for-woocommerce' ), $cls_amt ) );
					?>
				</p>
				<input type="number" min="1" max="<?php echo esc_attr( (string) $cls_amt ); ?>"
					value="<?php echo esc_attr( (string) $cls_amt ); ?>"
	class="small-text moksafowo-ecpay-credit-lifecycle__amount"
					style="width:80px;">
				<button type="button" class="button button-primary moksafowo-ecpay-credit-lifecycle__action" data-action="R">
					<?php esc_html_e( 'Refund', 'moksa-for-woocommerce' ); ?>
				</button>
			<?php else : ?>
				<p style="margin:0;color:#646970;font-size:11px;">
					<?php esc_html_e( 'No actions are available in this state.', 'moksa-for-woocommerce' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<p style="margin:10px 0 0;text-align:right;">
			<button type="button" class="button-link moksafowo-ecpay-credit-lifecycle__refresh" style="font-size:11px;">
				<?php esc_html_e( 'Check again', 'moksa-for-woocommerce' ); ?>
			</button>
		</p>
		<?php
		return (string) ob_get_clean();
	}

	private static function is_credit_order( \WC_Order $order ): bool {
		$method = (string) $order->get_payment_method();
		if ( ! str_starts_with( $method, 'moksafowo_ecpay_' ) ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ $method ] ?? null;
		if ( ! $gateway instanceof AbstractEcpayGateway ) {
			return false;
		}
		$pay_type = (string) $order->get_meta( Keys::ECPAY_PAYMENT_TYPE );
		if ( '' !== $pay_type && ! str_starts_with( $pay_type, 'Credit' ) ) {
			return false;
		}
		$trade_no = (string) $order->get_meta( Keys::ECPAY_TRADE_NO );
		return '' !== $trade_no;
	}

	private static function action_label( string $action ): string {
		return [
			'N' => __( 'Cancel authorization', 'moksa-for-woocommerce' ),
			'C' => __( 'Capture', 'moksa-for-woocommerce' ),
			'R' => __( 'Refund', 'moksa-for-woocommerce' ),
		][ $action ] ?? $action;
	}

	private static function status_label( string $raw ): string {
		$map = [
			'Authorized'     => __( 'Authorized (not captured)', 'moksa-for-woocommerce' ),
			'Captured'       => __( 'Captured', 'moksa-for-woocommerce' ),
			'To be captured' => __( 'Capture in progress', 'moksa-for-woocommerce' ),
			'Refunded'       => __( 'Refunded', 'moksa-for-woocommerce' ),
			'Cancelled'      => __( 'Authorization cancelled', 'moksa-for-woocommerce' ),
			'Failed'         => __( 'Failed', 'moksa-for-woocommerce' ),
		];
		return $map[ $raw ] ?? ( '' === $raw ? __( 'Unknown', 'moksa-for-woocommerce' ) : $raw );
	}

	private static function status_color( string $raw ): string {
		return [
			'Authorized'     => '#dba617',
			'Captured'       => '#00a32a',
			'To be captured' => '#dba617',
			'Refunded'       => '#646970',
			'Cancelled'      => '#646970',
			'Failed'         => '#d63638',
		][ $raw ] ?? '#1d2327';
	}
}
