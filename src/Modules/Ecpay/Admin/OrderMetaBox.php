<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Admin;

use Moksafowo\Modules\Shared\Admin\OrderInfoLayout;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox {

	public static function init(): void {
		OrderInfoLayout::boot();
		add_filter( 'moksafowo_order_info_cards', [ __CLASS__, 'add_card' ], 10, 2 );
	}

	public static function add_card( array $cards, \WC_Order $order ): array {
		$method = (string) $order->get_payment_method();
		if ( ! str_starts_with( $method, 'moksafowo_ecpay_' ) ) {
			return $cards;
		}

		$trade_no = (string) $order->get_meta( Keys::ECPAY_TRADE_NO );
		$mtn      = (string) $order->get_meta( Keys::ECPAY_MERCHANT_TRADE_NO );
		if ( '' === $trade_no && '' === $mtn ) {
			return $cards;
		}

		$pay_type   = (string) $order->get_meta( Keys::ECPAY_PAYMENT_TYPE );
		$pay_date   = (string) $order->get_meta( Keys::ECPAY_PAYMENT_DATE );
		$rtn_code   = (string) $order->get_meta( Keys::ECPAY_RTN_CODE );
		$card_last4 = (string) $order->get_meta( Keys::ECPAY_CARD_LAST4 );

		ob_start();
		echo '<p><strong>' . esc_html__( '付款方式：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::pay_type_label( $pay_type ) ) . '</p>';
		if ( '' !== $trade_no ) {
			echo '<p><strong>' . esc_html__( '綠界交易編號：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $trade_no ) . '</p>';
		}
		if ( '' !== $mtn ) {
			echo '<p><strong>' . esc_html__( '商家交易編號：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $mtn ) . '</p>';
		}
		if ( '' !== $card_last4 ) {
			echo '<p><strong>' . esc_html__( '卡末四碼：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $card_last4 ) . '</p>';
		}
		if ( '' !== $pay_date ) {
			echo '<p><strong>' . esc_html__( '付款時間：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $pay_date ) . '</p>';
		}

		$atm_acct = (string) $order->get_meta( Keys::ECPAY_ATM_V_ACCOUNT );
		$cvs_no   = (string) $order->get_meta( Keys::ECPAY_CVS_PAYMENT_NO );
		$barcode1 = (string) $order->get_meta( Keys::ECPAY_BARCODE_1 );
		if ( '' !== $atm_acct ) {
			$bank   = (string) $order->get_meta( Keys::ECPAY_ATM_BANK_CODE );
			$expire = (string) $order->get_meta( Keys::ECPAY_ATM_EXPIRE_DATE );
			echo '<p><strong>' . esc_html__( '虛擬帳號：', 'moksa-for-woocommerce' ) . '</strong>';
			if ( '' !== $bank ) {
				echo esc_html__( '銀行 ', 'moksa-for-woocommerce' ) . esc_html( $bank ) . ' - ';
			}
			echo '<span style="font-family:monospace;">' . esc_html( $atm_acct ) . '</span></p>';
			if ( '' !== $expire ) {
				echo '<p><strong>' . esc_html__( '繳費期限：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $expire ) . '</p>';
			}
		} elseif ( '' !== $cvs_no ) {
			$expire = (string) $order->get_meta( Keys::ECPAY_CVS_EXPIRE_DATE );
			echo '<p><strong>' . esc_html__( '繳費代碼：', 'moksa-for-woocommerce' ) . '</strong><span style="font-family:monospace;">' . esc_html( $cvs_no ) . '</span></p>';
			if ( '' !== $expire ) {
				echo '<p><strong>' . esc_html__( '繳費期限：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $expire ) . '</p>';
			}
		} elseif ( '' !== $barcode1 ) {
			$expire       = (string) $order->get_meta( Keys::ECPAY_BARCODE_EXPIRE_DATE );
			$barcode_keys = [
				1 => Keys::ECPAY_BARCODE_1,
				2 => Keys::ECPAY_BARCODE_2,
				3 => Keys::ECPAY_BARCODE_3,
			];
			for ( $i = 1; $i <= 3; $i++ ) {
				$bc = (string) $order->get_meta( $barcode_keys[ $i ] );
				if ( '' !== $bc ) {
					/* translators: %d: barcode index */
					echo '<p><strong>' . esc_html( sprintf( __( '條碼 %d：', 'moksa-for-woocommerce' ), $i ) ) . '</strong><span style="font-family:monospace;">' . esc_html( $bc ) . '</span></p>';
				}
			}
			if ( '' !== $expire ) {
				echo '<p><strong>' . esc_html__( '繳費期限：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $expire ) . '</p>';
			}
		}

		if ( '' !== $rtn_code && '1' !== $rtn_code ) {
			echo '<p style="color:#646970;font-size:11px;"><strong>狀態代碼：</strong>' . esc_html( $rtn_code ) . '</p>';
		}

		$refunds = $order->get_refunds();
		if ( ! empty( $refunds ) ) {
			$total     = (float) $order->get_total( 'edit' );  // 不含已退費，原始金額
			$refunded  = (float) $order->get_total_refunded();
			$net       = $total - $refunded;
			$is_full   = abs( $net ) < 0.01;
			$net_color = $is_full ? '#d63638' : '#dba617';
			echo '<div style="margin-top:10px;padding-top:8px;border-top:1px dashed #c0c0c0;">';
			echo '<p style="margin:0 0 4px;color:#646970;font-size:11px;text-transform:uppercase;letter-spacing:.4px;">' . esc_html__( '退款紀錄', 'moksa-for-woocommerce' ) . '</p>';
			foreach ( $refunds as $refund ) {
				if ( ! $refund instanceof \WC_Order_Refund ) {
					continue;
				}
				$amt    = (float) $refund->get_amount();
				$date   = $refund->get_date_created() ? $refund->get_date_created()->format( 'Y-m-d H:i' ) : '';
				$reason = (string) $refund->get_reason();
				$author = (string) ( $refund->get_meta( '_refunded_by' ) ?: '' );
				$user   = $author ? get_userdata( (int) $author ) : null;
				echo '<p style="margin:.2em 0;font-size:12px;">';
				echo '<strong style="color:#d63638;">−NT$' . esc_html( (string) (int) $amt ) . '</strong>';
				if ( '' !== $date ) {
					echo ' <span style="color:#646970;">' . esc_html( $date ) . '</span>';
				}
				if ( $user ) {
					echo ' <span style="color:#646970;">— ' . esc_html( $user->display_name ) . '</span>';
				}
				if ( '' !== $reason ) {
					echo '<br><span style="color:#646970;font-size:11px;">' . esc_html( $reason ) . '</span>';
				}
				echo '</p>';
			}
			echo '<p style="margin:6px 0 0;padding-top:6px;border-top:1px solid #e0e0e0;font-size:12px;">';
			echo '<strong>' . esc_html__( '訂單淨額：', 'moksa-for-woocommerce' ) . '</strong>';
			echo '<span style="color:' . esc_attr( $net_color ) . ';font-weight:600;">NT$' . esc_html( (string) (int) $net ) . '</span>';
			if ( $is_full ) {
				echo ' <span style="color:#d63638;font-size:11px;">(' . esc_html__( '全額退款', 'moksa-for-woocommerce' ) . ')</span>';
			}
			echo '</p>';
			echo '</div>';
		}

		echo wp_kses( CreditLifecycleBox::lifecycle_html( $order ), OrderInfoLayout::card_allowlist() );

		$cards[] = [
			'slot'  => 'payment',
			'title' => __( '金流資訊', 'moksa-for-woocommerce' ),
			'html'  => (string) ob_get_clean(),
		];
		return $cards;
	}

	public static function pay_type_label( string $raw ): string {
		return \Moksafowo\Modules\Ecpay\PaymentTypeCatalog::label( $raw );
	}
}
