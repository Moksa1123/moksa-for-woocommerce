<?php
namespace MoksaWeb\Mowc\Modules\Payuni\Gateways;

use MoksaWeb\Mowc\Modules\Payuni\Api\PaymentRequest;
use MoksaWeb\Mowc\Modules\Payuni\Credentials;
use MoksaWeb\Mowc\Modules\Payuni\PayuniPayment;
use MoksaWeb\Mowc\Modules\Payuni\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\Payuni\Utils\TradeStatus;
use MoksaWeb\Mowc\Modules\Payuni\Utils\AuthType;
use MoksaWeb\Mowc\Modules\Payuni\Utils\BankType;

defined( 'ABSPATH' ) || exit;

abstract class GatewayBase extends \WC_Payment_Gateway {

	protected $merchant_id;

	protected $hashkey;

	protected $hashiv;

	protected $testmode;

	protected $api_url;

	public $return_url;

	public $notify_url;

	public $incomplete_payment_message;

	protected $min_amount = 0;

	public function __construct() {

		$this->has_fields        = false;
		$this->order_button_text = __( '前往 PAYUNi 付款', 'mo-ectools' );
		$this->supports          = array(
			'products',
		);

		$this->testmode                   = Credentials::test_mode_enabled();
		$this->merchant_id                = strtoupper( Credentials::merchant_id() );
		$this->hashkey                    = Credentials::hashkey();
		$this->hashiv                     = Credentials::hashiv();
		$this->incomplete_payment_message = $this->get_option( 'incomplete_payment_message' );

		$this->api_url    = ( $this->testmode ) ? 'https://sandbox-api.payuni.com.tw/api/upp' : 'https://api.payuni.com.tw/api/upp';
		$this->notify_url = WC()->api_request_url( 'moksafowo_payuni_payment' );
		$this->return_url = WC()->api_request_url( 'moksafowo_payuni_return' );

		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'moksafowo_payuni_payment_detail_after_order_table' ), 10, 1 );
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'moksafowo_payuni_thankyou_order_unpaid_message' ), 10, 2 );
	}

	public function moksafowo_payuni_payment_detail_after_order_table( $order ) {

		if ( $order->get_payment_method() === $this->id ) {

			echo '<h2>' . esc_html__( 'PAYUNi 付款明細', 'mo-ectools' ) . '</h2>';

			$trade_no_key = PayuniPayment::get_order_meta_key( $order, OrderMeta::UNI_NO );
			if ( empty( $order->get_meta( $trade_no_key ) ) ) {
				echo '<div class="moksafowo_payuni_payment_notify_not_received">' . esc_html__( '付款明細尚未顯示，請稍候後重新整理頁面。', 'mo-ectools' ) . '</div>';
			}

			echo '<table class="shop_table payuni_payment_details"><tbody>';

			$order_metas = self::get_order_metas();

			foreach ( $order_metas as $key => $value ) {
				echo '<tr><td><strong>' . esc_html( $value ) . '</strong></td>';
				$order_meta_key = PayuniPayment::get_order_meta_key( $order, $key );
				if ( $order_meta_key === OrderMeta::CREDIT_AUTH_TYPE ) {
					echo '<td>' . esc_html( AuthType::get_type( $order->get_meta( $order_meta_key ) ) ) . ' (' . esc_html( $order->get_meta( $order_meta_key ) ) . ')</td></tr>';
				} elseif ( $order_meta_key === OrderMeta::AMT_BANK_TYPE ) {
					echo '<td>' . esc_html( $order->get_meta( $order_meta_key ) ) . ' (' . esc_html( BankType::get_name( $order->get_meta( $order_meta_key ) ) ) . ')</td></tr>';
				} elseif ( $order_meta_key === OrderMeta::TRADE_STATUS ) {
					$trade_status = $order->get_meta( $order_meta_key );
					if ( isset( $trade_status ) ) {
						echo '<td>' . esc_html( TradeStatus::get_name( $trade_status, $order->get_payment_method() ) ) . '</td></tr>';
					} else {
						echo '<td>' . esc_html( $trade_status ) . '</td></tr>';
					}
				} elseif ( $order_meta_key === OrderMeta::MESSAGE ) {
					echo '<td>' . esc_html( $order->get_meta( $order_meta_key ) ) . ' (' . esc_html( $order->get_meta( OrderMeta::STATUS ) ) . ')</td></tr>';
				} else {
					echo '<td>' . esc_html( $order->get_meta( $order_meta_key ) ) . '</td></tr>';
				}
			}

			echo '</tbody></table>';

			if ( PayuniPayment::$einvoice_enabled ) {
				echo '<h2>' . esc_html__( '電子發票明細', 'mo-ectools' ) . '</h2>';
				echo '<table class="shop_table payuni_payment_details"><tbody>';
				echo '<tr><td><strong>' . esc_html__( '發票號碼', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $order->get_meta( OrderMeta::EINVOICE_NO ) ) . '</td></tr>';
				echo '<tr><td><strong>' . esc_html__( '發票金額', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $order->get_meta( OrderMeta::EINVOICE_AMT ) ) . '</td></tr>';
				echo '<tr><td><strong>' . esc_html__( '開立時間', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $order->get_meta( OrderMeta::EINVOICE_TIME ) ) . '</td></tr>';

				$einvoice_type = $order->get_meta( OrderMeta::EINVOICE_TYPE );
				if ( $einvoice_type === 'C0401' ) {
					$einvoice_type_desc = _x( '開立', 'Issue Type', 'mo-ectools' );
				} elseif ( $einvoice_type === 'C0501' ) {
					$einvoice_type_desc = _x( '作廢', 'Issue Type', 'mo-ectools' );
				} else {
					$einvoice_type_desc = _x( '未知類型', 'Issue Type', 'mo-ectools' );
				}
				echo '<tr><td><strong>' . esc_html__( '發票類型', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $einvoice_type . ' (' . $einvoice_type_desc . ')' ) . '</td></tr>';

				$einvoice_info = $order->get_meta( OrderMeta::EINVOICE_INFO );
				if ( $einvoice_info === '3J0002' ) {
					$einvoice_info_desc = __( '手機載具', 'mo-ectools' );
				} elseif ( $einvoice_info === 'CQ0001' ) {
					$einvoice_info_desc = __( '自然人憑證', 'mo-ectools' );
				} elseif ( $einvoice_info === 'amego' ) {
					$einvoice_info_desc = __( 'Amego 會員', 'mo-ectools' );
				} elseif ( $einvoice_info === 'Donate' ) {
					$einvoice_info_desc = __( '愛心捐贈', 'mo-ectools' );
				} elseif ( $einvoice_info === 'Company' ) {
					$einvoice_info_desc = __( '公司戶', 'mo-ectools' );
				} else {
					$einvoice_info_desc = __( '未知載具', 'mo-ectools' );
				}
				echo '<tr><td><strong>' . esc_html__( '載具資訊', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $einvoice_info . ' (' . $einvoice_info_desc . ')' ) . '</td></tr>';

				$einvoice_status = $order->get_meta( OrderMeta::EINVOICE_STATUS );
				if ( $einvoice_status === '1' ) {
					$einvoice_status_desc = __( '已開立', 'mo-ectools' );
				} elseif ( $einvoice_status === '2' ) {
					$einvoice_status_desc = __( '開立失敗', 'mo-ectools' );
				} elseif ( $einvoice_status === '5' ) {
					$einvoice_status_desc = __( '已作廢', 'mo-ectools' );
				} else {
					$einvoice_status_desc = __( '未知狀態', 'mo-ectools' );
				}
				echo '<tr><td><strong>' . esc_html__( '開立狀態', 'mo-ectools' ) . '</strong></td><td>' . esc_html( $einvoice_status . ' (' . $einvoice_status_desc . ')' ) . '</td></tr>';
				echo '</tbody></table>';
			}// end einvoice enabled
		}
	}

	public function admin_options() {
		echo '<h3>' . esc_html( $this->get_method_title() ) . '</h3>';
		echo '<p>' . sprintf(
		/* translators: 1: Payment method title 2: PAYUNi URL */
			esc_html__( '%1$s 金流服務由 %2$s 提供', 'mo-ectools' ),
			esc_html( $this->get_method_title() ),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( 'https://www.payuni.com.tw/' ),
				esc_html__( 'PAYUNi', 'mo-ectools' )
			)
		) . '</p>';
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	public function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );
		// Return thankyou redirect.
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	public function receipt_page( $order ) {
		WC()->cart->empty_cart();
		$request = new PaymentRequest();
		$request->set_gateway( $this );
		$request->build_request_form( $order );
	}

	public function moksafowo_payuni_thankyou_order_unpaid_message( $text, $order ) {
		if ( $order ) {
			if ( $order->get_payment_method() !== $this->id ) {
				return $text;
			}

			$trade_status_key = PayuniPayment::get_order_meta_key( $order, OrderMeta::TRADE_STATUS );
			$trade_status     = $order->get_meta( $trade_status_key );

			PayuniPayment::log( 'imcompleete payment message: ' . $this->incomplete_payment_message );

			if ( 'pending' === $order->get_status() || TradeStatus::PAID !== $trade_status ) {
				if ( empty( $this->incomplete_payment_message ) ) {
					$text = '<span class="moksafowo-payuni-incomplete-payment-message">' . esc_html__( '已收到您的訂單，但付款尚未完成。', 'mo-ectools' ) . '</span>';
				} else {
					$text = '<span class="moksafowo-payuni-incomplete-payment-message">' . $this->incomplete_payment_message . '</span>';
				}
			}
		}

		return $text;
	}

	public function is_available() {
		$is_available = ( 'yes' === $this->enabled );

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->max_amount && $this->max_amount < $this->get_order_total() ) {
			$is_available = false;
		}

		if ( WC()->cart && 0 < $this->get_order_total() && $this->min_amount > $this->get_order_total() ) {
			$is_available = false;
		}

		// Display-mode gating: hide all per-method gateways in `single` mode and
		// hide Unified in `multi` mode. Unified::is_available() does its own
		// flip; individual gateways defer to this base check.
		if ( $is_available ) {
			$mode = get_option( 'moksafowo_payuni_display_mode', 'multi' );
			if ( 'single' === $mode && Unified::GATEWAY_ID !== $this->id ) {
				$is_available = false;
			} elseif ( 'multi' === $mode && Unified::GATEWAY_ID === $this->id ) {
				$is_available = false;
			}
		}

		return $is_available;
	}

	public static function get_order_metas() {
		return array_merge( PayuniPayment::$order_metas, static::get_payment_order_metas() );
	}

	public function get_method_title() {
		return $this->method_title;
	}

	public function get_merchant_id() {
		return $this->merchant_id;
	}

	public function get_hashkey() {
		return $this->hashkey;
	}

	public function get_hashiv() {
		return $this->hashiv;
	}

	public function get_api_url() {
		return $this->api_url;
	}

	public function get_items_infos( $order ) {
		$items  = $order->get_items();
		$item_s = '';
		foreach ( $items as $item ) {
			$item_s .= $item['name'] . 'X' . $item['quantity'];
			if ( end( $items )['name'] !== $item['name'] ) {
				$item_s .= ',';
			}
		}
		$resp = ( mb_strlen( $item_s ) > 200 ) ? mb_substr( $item_s, 0, 200 ) : $item_s;
		return $resp;
	}
}
