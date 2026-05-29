<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayInvoice\Admin;

use MoksaWeb\Mowc\Modules\EcpayInvoice\Operations\Allowance;
use MoksaWeb\Mowc\Modules\EcpayInvoice\Operations\Invalid;
use MoksaWeb\Mowc\Modules\EcpayInvoice\Operations\Issue;
use MoksaWeb\Mowc\Modules\Shared\Admin\OrderInfoLayout;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox {

	private const NONCE_ACTION = 'mo_ecpay_invoice_admin';
	private const CAPABILITY   = 'edit_shop_orders';

	public static function init(): void {
		OrderInfoLayout::boot();
		// 三欄 footer：priority 30 = 發票（右）— 順序「金流(10) 物流(20) 發票(30)」
		add_filter( 'mo_order_info_cards', [ __CLASS__, 'add_card' ], 30, 2 );

		// WC Blocks (CheckoutFieldsAdmin::admin_order_fields) 會把 location='order'
		// 的 additional checkout fields 自動 merge 進 woocommerce_admin_shipping_fields，
		// 結果「發票類型 / 載具類型」在運送地址下方又印一次（跟我們的 inline 區重複）。
		// 我們有專屬 ECPay 發票區，不需要 WC 預設那條 list — 拿掉。
		add_filter( 'woocommerce_admin_shipping_fields', [ __CLASS__, 'hide_invoice_in_admin_shipping' ], 11 );

		add_action( 'wp_ajax_mo_ecpay_invoice_issue', [ __CLASS__, 'ajax_issue' ] );
		add_action( 'wp_ajax_mo_ecpay_invoice_invalid', [ __CLASS__, 'ajax_invalid' ] );
		add_action( 'wp_ajax_mo_ecpay_invoice_allowance', [ __CLASS__, 'ajax_allowance' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function hide_invoice_in_admin_shipping( array $fields ): array {
		foreach ( array_keys( $fields ) as $key ) {
			if ( str_contains( (string) $key, 'mowp/invoice' ) ) {
				unset( $fields[ $key ] );
			}
		}
		return $fields;
	}

	public static function add_card( array $cards, \WC_Order $order ): array {
		// 若訂單明確標示由其他 provider 處理（ezpay / smilepay），不搶 slot
		$provider = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		if ( '' !== $provider && 'ecpay' !== $provider ) {
			return $cards;
		}
		$inv         = (string) $order->get_meta( Keys::ECPAY_INVOICE_NUMBER );
		$rand        = (string) $order->get_meta( Keys::ECPAY_INVOICE_RANDOM );
		$issued_at   = (string) $order->get_meta( Keys::ECPAY_INVOICE_ISSUED_AT );
		$invalid_at  = (string) $order->get_meta( Keys::ECPAY_INVOICE_INVALID_AT );
		$allowance   = (string) $order->get_meta( Keys::ECPAY_INVOICE_ALLOWANCE_NO );
		$type        = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$ubn         = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
		$buyer_name  = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
		$carrier_t   = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$carrier_n   = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );

		// 沒任何發票相關 meta（連 type 都沒）— 不顯示卡片
		if ( '' === $inv && '' === $type ) {
			return $cards;
		}

		ob_start();
		echo '<div class="mo-ecpay-invoice-meta" data-order-id="' . esc_attr( (string) $order->get_id() ) . '">';

		if ( '' !== $inv ) {
			echo '<p><strong>' . esc_html__( '發票號碼：', 'mo-ectools' ) . '</strong>' . esc_html( $inv ) . '</p>';
			if ( '' !== $issued_at ) {
				echo '<p><strong>' . esc_html__( '開立時間：', 'mo-ectools' ) . '</strong>' . esc_html( $issued_at ) . '</p>';
			}
			echo '<p><strong>' . esc_html__( '隨機碼：', 'mo-ectools' ) . '</strong>' . esc_html( $rand ) . '</p>';
			echo '<p><strong>' . esc_html__( '開立方式：', 'mo-ectools' ) . '</strong>' . esc_html__( '一般開立發票', 'mo-ectools' ) . '</p>';
			if ( '' !== $carrier_t && 'b2b' !== $type ) {
				echo '<p><strong>' . esc_html__( '開立類型：', 'mo-ectools' ) . '</strong>' . esc_html( self::carrier_label( $carrier_t ) ) . '</p>';
			} elseif ( 'b2b' === $type ) {
				echo '<p><strong>' . esc_html__( '開立類型：', 'mo-ectools' ) . '</strong>' . esc_html__( '三聯式發票', 'mo-ectools' ) . '</p>';
			} elseif ( 'b2c_donate' === $type ) {
				echo '<p><strong>' . esc_html__( '開立類型：', 'mo-ectools' ) . '</strong>' . esc_html__( '捐贈', 'mo-ectools' ) . '</p>';
			}
			echo '<p><strong>' . esc_html__( '發票開立：', 'mo-ectools' ) . '</strong>' . esc_html( self::issue_label( $type ) ) . '</p>';
			if ( '' !== $ubn ) {
				echo '<p><strong>' . esc_html__( '統一編號：', 'mo-ectools' ) . '</strong>' . esc_html( $ubn ) . ( '' !== $buyer_name ? ' (' . esc_html( $buyer_name ) . ')' : '' ) . '</p>';
			}
			if ( '' !== $carrier_n ) {
				echo '<p><strong>' . esc_html__( '載具編號：', 'mo-ectools' ) . '</strong>' . esc_html( $carrier_n ) . '</p>';
			}
			if ( '' !== $invalid_at ) {
				echo '<p style="color:#c00;"><strong>' . esc_html__( '已作廢：', 'mo-ectools' ) . '</strong>' . esc_html( $invalid_at ) . '</p>';
			} else {
				echo '<p style="margin-top:.6em;">';
				echo '<button type="button" class="button mo-ecpay-invoice-invalid">' . esc_html__( '作廢發票', 'mo-ectools' ) . '</button> ';
				echo '<button type="button" class="button mo-ecpay-invoice-allowance">' . esc_html__( '開立折讓單', 'mo-ectools' ) . '</button>';
				echo '</p>';
			}
			if ( '' !== $allowance ) {
				$amt = (string) $order->get_meta( Keys::ECPAY_INVOICE_ALLOWANCE_AMT );
				echo '<p><strong>' . esc_html__( '折讓單號：', 'mo-ectools' ) . '</strong>' . esc_html( $allowance ) . ( '' !== $amt ? ' (' . esc_html__( '金額：', 'mo-ectools' ) . esc_html( $amt ) . ')' : '' ) . '</p>';
			}
		} else {
			if ( '' !== $type ) {
				echo '<p><strong>' . esc_html__( '發票開立：', 'mo-ectools' ) . '</strong>' . esc_html( self::issue_label( $type ) ) . '</p>';
			}
			if ( '' !== $carrier_t && 'b2b' !== $type ) {
				echo '<p><strong>' . esc_html__( '開立類型：', 'mo-ectools' ) . '</strong>' . esc_html( self::carrier_label( $carrier_t ) ) . '</p>';
			}
			echo '<p style="margin-top:.6em;"><button type="button" class="button button-primary mo-ecpay-invoice-issue">' . esc_html__( '開立發票', 'mo-ectools' ) . '</button></p>';
		}

		wp_nonce_field( self::NONCE_ACTION, 'mo_ecpay_invoice_nonce' );
		echo '</div>';

		$cards[] = [
			'slot'  => 'invoice',
			'title' => __( '發票資訊', 'mo-ectools' ),
			'html'  => (string) ob_get_clean(),
		];
		return $cards;
	}

	private static function issue_label( string $type ): string {
		switch ( $type ) {
			case 'b2c_carrier':
			case 'b2c_paper':
				return __( '個人', 'mo-ectools' );
			case 'b2b':
				return __( '公司', 'mo-ectools' );
			case 'b2c_donate':
				return __( '捐贈', 'mo-ectools' );
		}
		return $type;
	}

	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		$handle = 'mo-ecpay-invoice-admin';
		wp_register_script(
			$handle,
			MOWC_PLUGIN_URL . 'src/Modules/EcpayInvoice/assets/js/admin-meta-box.js',
			[ 'jquery' ],
			MOWC_VERSION,
			true
		);
		wp_localize_script( $handle, 'mo_ecpay_invoice_admin', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'i18n'     => [
				'issuing'        => __( '開立中…', 'mo-ectools' ),
				'issue_ok'       => __( '發票已開立。', 'mo-ectools' ),
				'issue_fail'     => __( '開立失敗：', 'mo-ectools' ),
				'invalid_prompt' => __( '請輸入作廢原因（最多 20 字）：', 'mo-ectools' ),
				'invalid_ok'     => __( '發票已作廢。', 'mo-ectools' ),
				'invalid_fail'   => __( '作廢失敗：', 'mo-ectools' ),
				'allowance_prompt' => __( '請輸入折讓金額（整數）：', 'mo-ectools' ),
				'allowance_ok'   => __( '折讓單已開立。', 'mo-ectools' ),
				'allowance_fail' => __( '折讓失敗：', 'mo-ectools' ),
				'confirm_invalid'   => __( '確定要作廢這張發票？此動作不可復原。', 'mo-ectools' ),
				'confirm_allowance' => __( '確定要開立折讓單？此動作不可復原。', 'mo-ectools' ),
				'unknown_error'     => __( '未知錯誤，請稍後再試或查看記錄。', 'mo-ectools' ),
			],
		] );
		wp_enqueue_script( $handle );
	}

	public static function ajax_issue(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '找不到訂單。', 'mo-ectools' ) ], 404 );
		}

		$result = Issue::run( $order );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public static function ajax_invalid(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '找不到訂單。', 'mo-ectools' ) ], 404 );
		}

		$result = Invalid::run( $order, $reason );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public static function ajax_allowance(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$amount   = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '找不到訂單。', 'mo-ectools' ) ], 404 );
		}

		$result = Allowance::run( $order, $amount );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	private static function type_label( string $type ): string {
		return match ( $type ) {
			'b2b'         => __( '公司（三聯式）', 'mo-ectools' ),
			'b2c_donate'  => __( '捐贈', 'mo-ectools' ),
			'b2c_carrier' => __( '個人（載具）', 'mo-ectools' ),
			default       => $type,
		};
	}

	private static function carrier_label( string $c ): string {
		return match ( $c ) {
			'mobile' => __( '手機條碼', 'mo-ectools' ),
			'cert'   => __( '自然人憑證', 'mo-ectools' ),
			'paper'  => __( '紙本', 'mo-ectools' ),
			'member' => __( '會員載具', 'mo-ectools' ),
			default  => $c,
		};
	}
}
