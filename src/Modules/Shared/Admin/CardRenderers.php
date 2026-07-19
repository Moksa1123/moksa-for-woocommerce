<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Admin;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class CardRenderers {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		// 確保 metabox 永遠註冊 — 即使商家只啟用 PAYUNi / LinePay 沒啟用 ECPay
		// （ECPay\Admin\OrderMetaBox::init() 也會呼叫 boot()，但 idempotent 重複呼叫無害）
		OrderInfoLayout::boot();
		// priority 11 = payment（讓 ECPay 10 先跑，沒命中時才走這裡）
		add_filter( 'moksafowo_order_info_cards', [ __CLASS__, 'add_payment_card' ], 11, 2 );
		// priority 21 = shipping 由 Shipping\Admin\ShippingCardSection 自行 register（避免 Shared -> Shipping 反向 layering）
		// priority 31 = invoice（ECPay 30 先跑，沒命中時才走 ezPay 等其他 provider）
		add_filter( 'moksafowo_order_info_cards', [ __CLASS__, 'add_invoice_card' ], 31, 2 );
		// 共用的「複製貨號」clipboard JS — admin 訂單頁需要（PAYUNi / SmilePay tracking buttons 共用）
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_tracking_copy_script' ] );
	}

	public static function enqueue_tracking_copy_script( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		wp_enqueue_script( 'moksafowo-tracking-copy' );
	}

	public static function add_payment_card( array $cards, \WC_Order $order ): array {
		// 已經有 payment card 就跳過（ECPay OrderMetaBox priority 10 早跑）
		foreach ( $cards as $c ) {
			if ( ( $c['slot'] ?? '' ) === 'payment' ) {
				return $cards;
			}
		}

		$method = (string) $order->get_payment_method();
		$html   = '';
		if ( str_starts_with( $method, 'moksafowo_payuni_' ) ) {
			$html = self::render_payuni( $order );
		} elseif ( str_starts_with( $method, 'moksafowo_newebpay_' ) ) {
			$html = self::render_newebpay( $order );
		} elseif ( 'moksafowo-linepay' === $method ) {
			$html = self::render_linepay( $order );
		}

		if ( '' !== $html ) {
			$cards[] = [
				'slot'  => 'payment',
				'title' => __( '金流資訊', 'moksa-for-woocommerce' ),
				'html'  => $html . self::render_refund_block( $order ),
			];
		}
		return $cards;
	}

	public static function add_invoice_card( array $cards, \WC_Order $order ): array {
		foreach ( $cards as $c ) {
			if ( ( $c['slot'] ?? '' ) === 'invoice' ) {
				return $cards;
			}
		}
		$provider = (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::INVOICE_PROVIDER );
		$html     = '';
		if ( 'ezpay' === $provider ) {
			$html = self::render_ezpay_invoice( $order );
		} elseif ( 'smilepay' === $provider ) {
			$html = self::render_smilepay_invoice( $order );
		}
		if ( '' !== $html ) {
			$cards[] = [
				'slot'  => 'invoice',
				'title' => __( '發票資訊', 'moksa-for-woocommerce' ),
				'html'  => $html,
			];
		}
		return $cards;
	}

	private static function render_payuni( \WC_Order $order ): string {
		$mer_trade_no = (string) $order->get_meta( Keys::PAYUNI_ORDER_NO );
		$trade_no     = (string) $order->get_meta( Keys::PAYUNI_TRADE_NO );
		$pay_type     = (int) $order->get_meta( Keys::PAYUNI_PAYMENT_TYPE );
		$paid_at      = (string) $order->get_meta( Keys::PAYUNI_PAID_AT );
		$card4no      = (string) $order->get_meta( Keys::PAYUNI_CREDIT_CARD4NO );
		$bank         = (string) $order->get_meta( Keys::PAYUNI_CREDIT_BANK );
		$auth_code    = (string) $order->get_meta( Keys::PAYUNI_CREDIT_AUTH_CODE );
		$inst         = (string) $order->get_meta( Keys::PAYUNI_CREDIT_INST );

		$method_title = (string) $order->get_payment_method_title();

		// 沒任何 PAYUNi 交易資料 → 顯示「尚未付款」明確訊息（不 fallback 到 placeholder）
		if ( '' === $mer_trade_no && '' === $trade_no ) {
			ob_start();
			echo '<p><strong>' . esc_html__( '付款方式：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $method_title ?: 'PAYUNi' ) . '</p>';
			echo '<p style="color:#646970;font-size:12px;">' . esc_html__( '尚未付款 — 等待顧客完成 PAYUNi 付款流程。', 'moksa-for-woocommerce' ) . '</p>';
			return (string) ob_get_clean();
		}

		ob_start();
		echo '<p><strong>' . esc_html__( '付款方式：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $method_title ?: 'PAYUNi' ) . '</p>';
		if ( '' !== $trade_no ) {
			echo '<p><strong>' . esc_html__( 'PAYUNi 交易編號：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $trade_no ) . '</p>';
		}
		if ( '' !== $mer_trade_no ) {
			echo '<p><strong>' . esc_html__( '商家交易編號：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $mer_trade_no ) . '</p>';
		}
		if ( '' !== $card4no ) {
			echo '<p><strong>' . esc_html__( '卡末四碼：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $card4no );
			if ( '' !== $bank ) {
				echo ' <span style="color:#646970;font-size:11px;">(' . esc_html( $bank ) . ')</span>';
			}
			echo '</p>';
		}
		if ( '' !== $auth_code ) {
			echo '<p><strong>' . esc_html__( '授權碼：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $auth_code ) . '</p>';
		}
		if ( '' !== $inst && '0' !== $inst ) {
			echo '<p><strong>' . esc_html__( '分期：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $inst ) . esc_html__( ' 期', 'moksa-for-woocommerce' ) . '</p>';
		}
		if ( '' !== $paid_at ) {
			echo '<p><strong>' . esc_html__( '付款時間：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $paid_at ) . '</p>';
		}
		return (string) ob_get_clean();
	}

	private static function render_newebpay( \WC_Order $order ): string {
		$mtn      = (string) $order->get_meta( Keys::NEWEBPAY_MERCHANT_ORDER_NO );
		$trade_no = (string) $order->get_meta( Keys::NEWEBPAY_TRADE_NO );
		$pay_type = (string) $order->get_meta( Keys::NEWEBPAY_PAYMENT_TYPE );
		$pay_time = (string) $order->get_meta( Keys::NEWEBPAY_PAY_TIME );
		$card4no  = (string) $order->get_meta( Keys::NEWEBPAY_CARD_LAST4 );

		$method_title = (string) $order->get_payment_method_title();

		// 沒任何 NewebPay 交易資料 → 顯示「尚未付款」
		if ( '' === $mtn && '' === $trade_no ) {
			ob_start();
			echo '<p><strong>' . esc_html__( '付款方式：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $method_title ?: 'NewebPay' ) . '</p>';
			echo '<p style="color:#646970;font-size:12px;">' . esc_html__( '尚未付款 — 等待顧客在藍新付款頁完成付款。', 'moksa-for-woocommerce' ) . '</p>';
			return (string) ob_get_clean();
		}

		ob_start();
		echo '<p><strong>' . esc_html__( '付款方式：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::newebpay_pay_type_label( $pay_type ) ?: ( $method_title ?: 'NewebPay' ) ) . '</p>';
		if ( '' !== $trade_no ) {
			echo '<p><strong>' . esc_html__( '藍新交易編號：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $trade_no ) . '</p>';
		}
		if ( '' !== $mtn ) {
			echo '<p><strong>' . esc_html__( '商家交易編號：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $mtn ) . '</p>';
		}
		if ( '' !== $card4no ) {
			echo '<p><strong>' . esc_html__( '卡末四碼：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $card4no ) . '</p>';
		}
		if ( '' !== $pay_time ) {
			echo '<p><strong>' . esc_html__( '付款時間：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $pay_time ) . '</p>';
		}
		// 各付款類型專屬：CVS 代碼 / VACC 帳號 / Barcode 條碼
		if ( 'CVS' === $pay_type ) {
			$code   = (string) $order->get_meta( Keys::NEWEBPAY_CVS_CODE_NO );
			$expire = (string) $order->get_meta( Keys::NEWEBPAY_CVS_EXPIRE_DATE );
			if ( '' !== $code ) {
				echo '<p><strong>' . esc_html__( '繳費代碼：', 'moksa-for-woocommerce' ) . '</strong><span style="font-family:monospace;">' . esc_html( $code ) . '</span></p>';
			}
			if ( '' !== $expire ) {
				echo '<p><strong>' . esc_html__( '繳費期限：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $expire ) . '</p>';
			}
		} elseif ( 'VACC' === $pay_type ) {
			$bank = (string) $order->get_meta( Keys::NEWEBPAY_ATM_BANK_CODE );
			$acct = (string) $order->get_meta( Keys::NEWEBPAY_ATM_CODE_NO );
			if ( '' !== $acct ) {
				echo '<p><strong>' . esc_html__( '虛擬帳號：', 'moksa-for-woocommerce' ) . '</strong>';
				if ( '' !== $bank ) {
					echo esc_html__( '銀行 ', 'moksa-for-woocommerce' ) . esc_html( $bank ) . ' - ';
				}
				echo '<span style="font-family:monospace;">' . esc_html( $acct ) . '</span></p>';
			}
		} elseif ( 'BARCODE' === $pay_type ) {
			$barcode_keys = [
				1 => Keys::NEWEBPAY_BARCODE_1,
				2 => Keys::NEWEBPAY_BARCODE_2,
				3 => Keys::NEWEBPAY_BARCODE_3,
			];
			for ( $i = 1; $i <= 3; $i++ ) {
				$bc = (string) $order->get_meta( $barcode_keys[ $i ] );
				if ( '' !== $bc ) {
					/* translators: %d: barcode index */
					echo '<p><strong>' . esc_html( sprintf( __( '條碼 %d：', 'moksa-for-woocommerce' ), $i ) ) . '</strong><span style="font-family:monospace;">' . esc_html( $bc ) . '</span></p>';
				}
			}
		}

		return (string) ob_get_clean() . self::render_refund_block( $order );
	}

	private static function newebpay_pay_type_label( string $type ): string {
		return \Moksafowo\Modules\Newebpay\PaymentTypeCatalog::label( $type, '' );
	}

	private static function render_linepay( \WC_Order $order ): string {
		// Linepay 是 fork 模組，meta 用 `_linepay_*`（非 `_moksafowo_linepay_*`）— 已知技術債，
		// 此卡片直接讀 fork 實際寫入的 key，否則永遠顯示「尚未付款」。
		$tx        = (string) $order->get_meta( '_moksafowo_linepay_reserved_transaction_id' );
		$order_id  = '' !== $tx ? (string) $order->get_id() : '';
		$status    = (string) $order->get_meta( '_moksafowo_linepay_payment_status' );
		$pay_type  = '';
		$auth_amt  = (string) $order->get_meta( '_moksafowo_linepay_transaction_balanced_amount' );
		$auth_at   = '';
		$refund_tx = (string) $order->get_meta( '_moksafowo_linepay_refund_transaction_id' );

		// 沒任何 LinePay 交易資料 → 顯示「尚未付款」（含 checkout-draft 訂單）
		if ( '' === $tx && '' === $status ) {
			ob_start();
			echo '<p><strong>' . esc_html__( '付款方式：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html__( 'LINE Pay', 'moksa-for-woocommerce' ) . '</p>';
			echo '<p style="color:#646970;font-size:12px;">' . esc_html__( '尚未付款 — 等待顧客在 LINE Pay 完成付款。', 'moksa-for-woocommerce' ) . '</p>';
			return (string) ob_get_clean();
		}

		$status_label = self::linepay_status_label( $status );

		ob_start();
		echo '<p><strong>' . esc_html__( '付款方式：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html__( 'LINE Pay', 'moksa-for-woocommerce' ) . '</p>';
		if ( '' !== $tx ) {
			echo '<p><strong>' . esc_html__( 'LINE Pay 交易編號：', 'moksa-for-woocommerce' ) . '</strong><span style="font-family:monospace;">' . esc_html( $tx ) . '</span></p>';
		}
		if ( '' !== $order_id ) {
			echo '<p><strong>' . esc_html__( '商家交易編號：', 'moksa-for-woocommerce' ) . '</strong><span style="font-family:monospace;">' . esc_html( $order_id ) . '</span></p>';
		}
		if ( '' !== $status_label ) {
			echo '<p><strong>' . esc_html__( '交易狀態：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $status_label ) . '</p>';
		}
		if ( '' !== $pay_type && 'NORMAL' !== $pay_type ) {
			echo '<p><strong>' . esc_html__( '付款類型：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $pay_type ) . '</p>';
		}
		if ( '' !== $auth_amt ) {
			echo '<p><strong>' . esc_html__( '授權金額：', 'moksa-for-woocommerce' ) . '</strong>NT$' . esc_html( (string) (int) $auth_amt ) . '</p>';
		}
		if ( '' !== $auth_at ) {
			echo '<p><strong>' . esc_html__( '授權時間：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $auth_at ) . '</p>';
		}
		if ( '' !== $refund_tx ) {
			echo '<p style="color:#646970;font-size:11px;"><strong>' . esc_html__( '退款交易編號：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $refund_tx ) . '</p>';
		}
		return (string) ob_get_clean();
	}

	private static function render_ezpay_invoice( \WC_Order $order ): string {
		$inv        = (string) $order->get_meta( Keys::EZPAY_INVOICE_NUMBER );
		$rand       = (string) $order->get_meta( Keys::EZPAY_RANDOM_NUM );
		$issued_at  = (string) $order->get_meta( Keys::EZPAY_CREATE_TIME );
		$invalid_at = (string) $order->get_meta( Keys::EZPAY_INVALID_AT );
		$type       = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$ubn        = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
		$buyer_name = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
		$carrier_t  = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$carrier_n  = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );

		// 沒任何發票 meta（連 type 都沒）— 不顯示卡（讓 placeholder 接手）
		if ( '' === $inv && '' === $type ) {
			return '';
		}

		ob_start();

		if ( '' !== $inv ) {
			if ( 'zero' === $inv ) {
				echo '<p style="color:#646970;font-size:12px;">' . esc_html__( '訂單金額為 0，未開立發票。', 'moksa-for-woocommerce' ) . '</p>';
			} elseif ( 'negative' === $inv ) {
				echo '<p style="color:#d63638;font-size:12px;">' . esc_html__( '訂單金額為負，無法開立發票。', 'moksa-for-woocommerce' ) . '</p>';
			} else {
				echo '<p><strong>' . esc_html__( '發票號碼：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $inv ) . '</p>';
				if ( '' !== $issued_at ) {
					echo '<p><strong>' . esc_html__( '開立時間：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $issued_at ) . '</p>';
				}
				if ( '' !== $rand ) {
					echo '<p><strong>' . esc_html__( '隨機碼：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $rand ) . '</p>';
				}
				echo '<p><strong>' . esc_html__( '發票服務：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html__( 'ezPay', 'moksa-for-woocommerce' ) . '</p>';
				if ( '' !== $type ) {
					echo '<p><strong>' . esc_html__( '發票類型：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::invoice_type_label( $type ) ) . '</p>';
				}
				if ( '' !== $ubn ) {
					echo '<p><strong>' . esc_html__( '統一編號：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $ubn ) . ( '' !== $buyer_name ? ' (' . esc_html( $buyer_name ) . ')' : '' ) . '</p>';
				}
				if ( '' !== $carrier_n ) {
					echo '<p><strong>' . esc_html__( '載具編號：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $carrier_n ) . '</p>';
				}
				if ( '' !== $invalid_at ) {
					echo '<p style="color:#d63638;"><strong>' . esc_html__( '已作廢：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $invalid_at ) . '</p>';
				}
			}
		} else {
			// 還沒開立發票，顯示已選的類型 + 「待開立」
			echo '<p><strong>' . esc_html__( '發票服務：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html__( 'ezPay', 'moksa-for-woocommerce' ) . '</p>';
			echo '<p><strong>' . esc_html__( '發票類型：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::invoice_type_label( $type ) ) . '</p>';
			if ( '' !== $carrier_t && 'b2b' !== $type ) {
				echo '<p><strong>' . esc_html__( '載具：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::ezpay_carrier_label( $carrier_t ) ) . '</p>';
			}
			echo '<p style="color:#646970;font-size:12px;">' . esc_html__( '尚未開立，可在此手動開立發票。', 'moksa-for-woocommerce' ) . '</p>';
		}

		return (string) ob_get_clean();
	}

	private static function invoice_type_label( string $type ): string {
		return [
			'b2c_carrier' => __( '個人（含載具）', 'moksa-for-woocommerce' ),
			'b2b'         => __( '公司（三聯式）', 'moksa-for-woocommerce' ),
			'b2c_donate'  => __( '捐贈', 'moksa-for-woocommerce' ),
		][ $type ] ?? $type;
	}

	private static function ezpay_carrier_label( string $carrier ): string {
		return [
			'member' => __( 'ezPay 會員載具', 'moksa-for-woocommerce' ),
			'mobile' => __( '手機條碼', 'moksa-for-woocommerce' ),
			'cert'   => __( '自然人憑證', 'moksa-for-woocommerce' ),
			'paper'  => __( '紙本', 'moksa-for-woocommerce' ),
		][ $carrier ] ?? $carrier;
	}

	private static function render_smilepay_invoice( \WC_Order $order ): string {
		$inv        = (string) $order->get_meta( Keys::SMILEPAY_INVOICE_NUMBER );
		$rand       = (string) $order->get_meta( Keys::SMILEPAY_INVOICE_RANDOM );
		$issued_at  = (string) $order->get_meta( Keys::SMILEPAY_INVOICE_DATE );
		$invalid_at = (string) $order->get_meta( Keys::SMILEPAY_INVOICE_INVALID_AT );
		$type       = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$ubn        = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
		$buyer_name = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
		$carrier_t  = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$carrier_n  = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );

		if ( '' === $inv && '' === $type ) {
			return '';
		}

		ob_start();

		if ( '' !== $inv ) {
			if ( 'zero' === $inv ) {
				echo '<p style="color:#646970;font-size:12px;">' . esc_html__( '訂單金額為 0，未開立發票。', 'moksa-for-woocommerce' ) . '</p>';
			} elseif ( 'negative' === $inv ) {
				echo '<p style="color:#d63638;font-size:12px;">' . esc_html__( '訂單金額為負，無法開立發票。', 'moksa-for-woocommerce' ) . '</p>';
			} else {
				echo '<p><strong>' . esc_html__( '發票號碼：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $inv ) . '</p>';
				if ( '' !== $issued_at ) {
					echo '<p><strong>' . esc_html__( '開立時間：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $issued_at ) . '</p>';
				}
				if ( '' !== $rand ) {
					echo '<p><strong>' . esc_html__( '隨機碼：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $rand ) . '</p>';
				}
				echo '<p><strong>' . esc_html__( '發票服務：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html__( 'SmilePay', 'moksa-for-woocommerce' ) . '</p>';
				if ( '' !== $type ) {
					echo '<p><strong>' . esc_html__( '發票類型：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::invoice_type_label( $type ) ) . '</p>';
				}
				if ( '' !== $ubn ) {
					echo '<p><strong>' . esc_html__( '統一編號：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $ubn ) . ( '' !== $buyer_name ? ' (' . esc_html( $buyer_name ) . ')' : '' ) . '</p>';
				}
				if ( '' !== $carrier_n ) {
					echo '<p><strong>' . esc_html__( '載具編號：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $carrier_n ) . '</p>';
				}
				if ( '' !== $invalid_at ) {
					echo '<p style="color:#d63638;"><strong>' . esc_html__( '已作廢：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $invalid_at ) . '</p>';
				}
			}
		} else {
			echo '<p><strong>' . esc_html__( '發票服務：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html__( 'SmilePay', 'moksa-for-woocommerce' ) . '</p>';
			echo '<p><strong>' . esc_html__( '發票類型：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::invoice_type_label( $type ) ) . '</p>';
			if ( '' !== $carrier_t && 'b2b' !== $type ) {
				echo '<p><strong>' . esc_html__( '載具：', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::smilepay_carrier_label( $carrier_t ) ) . '</p>';
			}
			echo '<p style="color:#646970;font-size:12px;">' . esc_html__( '尚未開立，可在此手動開立發票。', 'moksa-for-woocommerce' ) . '</p>';
		}

		return (string) ob_get_clean();
	}

	private static function smilepay_carrier_label( string $carrier ): string {
		return [
			'member' => __( 'SmilePay 會員載具', 'moksa-for-woocommerce' ),
			'mobile' => __( '手機條碼', 'moksa-for-woocommerce' ),
			'cert'   => __( '自然人憑證', 'moksa-for-woocommerce' ),
			'paper'  => __( '紙本', 'moksa-for-woocommerce' ),
		][ $carrier ] ?? $carrier;
	}

	private static function render_refund_block( \WC_Order $order ): string {
		$refunds = $order->get_refunds();
		if ( empty( $refunds ) ) {
			return '';
		}
		$total     = (float) $order->get_total( 'edit' );
		$refunded  = (float) $order->get_total_refunded();
		$net       = $total - $refunded;
		$is_full   = abs( $net ) < 0.01;
		$net_color = $is_full ? '#d63638' : '#dba617';

		// Batch fetch refund authors — 訂單編輯頁 callback 對每張卡（payment/invoice）都會
		// fire render_refund_block，N refunds × M 卡 內原本 N×M 次 get_userdata；
		// 此處 collect ids → 一次 get_users → keyed map，foreach 內 O(1) lookup
		$author_ids = [];
		foreach ( $refunds as $refund ) {
			if ( ! $refund instanceof \WC_Order_Refund ) {
				continue;
			}
			$id = (int) ( $refund->get_meta( '_refunded_by' ) ?: 0 );
			if ( $id > 0 ) {
				$author_ids[ $id ] = true;
			}
		}
		$authors_by_id = [];
		if ( ! empty( $author_ids ) ) {
			$users = get_users(
				[
					'include' => array_keys( $author_ids ),
					'fields'  => [ 'ID', 'display_name' ],
				]
			);
			foreach ( $users as $u ) {
				$authors_by_id[ (int) $u->ID ] = $u;
			}
		}

		ob_start();
		echo '<div style="margin-top:10px;padding-top:8px;border-top:1px dashed #c0c0c0;">';
		echo '<p style="margin:0 0 4px;color:#646970;font-size:11px;text-transform:uppercase;letter-spacing:.4px;">' . esc_html__( '退款紀錄', 'moksa-for-woocommerce' ) . '</p>';
		foreach ( $refunds as $refund ) {
			if ( ! $refund instanceof \WC_Order_Refund ) {
				continue;
			}
			$amt    = (float) $refund->get_amount();
			$date   = $refund->get_date_created() ? $refund->get_date_created()->format( 'Y-m-d H:i' ) : '';
			$reason = (string) $refund->get_reason();
			$author = (int) ( $refund->get_meta( '_refunded_by' ) ?: 0 );
			$user   = $author > 0 ? ( $authors_by_id[ $author ] ?? null ) : null;
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
		return (string) ob_get_clean();
	}

	private static function linepay_status_label( string $raw ): string {
		return [
			'reserved'  => __( '已預授權（待請款）', 'moksa-for-woocommerce' ),
			'authed'    => __( '已授權（待請款）', 'moksa-for-woocommerce' ),
			'confirmed' => __( '已請款', 'moksa-for-woocommerce' ),
			'cancelled' => __( '已取消', 'moksa-for-woocommerce' ),
			'refunded'  => __( '已退款', 'moksa-for-woocommerce' ),
			'failed'    => __( '失敗', 'moksa-for-woocommerce' ),
		][ $raw ] ?? $raw;
	}
}
