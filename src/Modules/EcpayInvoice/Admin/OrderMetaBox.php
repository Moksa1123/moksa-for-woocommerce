<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayInvoice\Admin;

use MoksaWeb\Mowc\Modules\EcpayInvoice\Operations\Allowance;
use MoksaWeb\Mowc\Modules\EcpayInvoice\Operations\Invalid;
use MoksaWeb\Mowc\Modules\EcpayInvoice\Operations\Issue;
use MoksaWeb\Mowc\Modules\Shared\Admin\OrderInfoLayout;
use MoksaWeb\Mowc\Modules\Shared\Invoice\AdminIssueForm;
use MoksaWeb\Mowc\Modules\Shared\Invoice\InvoiceChannels;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox {

	private const NONCE_ACTION = 'moksafowo_ecpay_invoice_admin';
	private const CAPABILITY   = 'edit_shop_orders';

	public static function init(): void {
		OrderInfoLayout::boot();
		add_filter( 'moksafowo_order_info_cards', [ __CLASS__, 'add_card' ], 30, 2 );

		// WC Blocks merges location='order' fields into woocommerce_admin_shipping_fields,
		// which would duplicate our invoice section; remove them.
		add_filter( 'woocommerce_admin_shipping_fields', [ __CLASS__, 'hide_invoice_in_admin_shipping' ], 11 );

		add_action( 'wp_ajax_moksafowo_ecpay_invoice_save', [ __CLASS__, 'ajax_save' ] );
		add_action( 'wp_ajax_moksafowo_ecpay_invoice_issue', [ __CLASS__, 'ajax_issue' ] );
		add_action( 'wp_ajax_moksafowo_ecpay_invoice_invalid', [ __CLASS__, 'ajax_invalid' ] );
		add_action( 'wp_ajax_moksafowo_ecpay_invoice_allowance', [ __CLASS__, 'ajax_allowance' ] );
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
		$provider = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		if ( '' !== $provider && 'ecpay' !== $provider ) {
			return $cards;
		}
		$inv        = (string) $order->get_meta( Keys::ECPAY_INVOICE_NUMBER );
		$rand       = (string) $order->get_meta( Keys::ECPAY_INVOICE_RANDOM );
		$issued_at  = (string) $order->get_meta( Keys::ECPAY_INVOICE_ISSUED_AT );
		$invalid_at = (string) $order->get_meta( Keys::ECPAY_INVOICE_INVALID_AT );
		$allowance  = (string) $order->get_meta( Keys::ECPAY_INVOICE_ALLOWANCE_NO );
		$type       = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$ubn        = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
		$buyer_name = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
		$carrier_t  = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$carrier_n  = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );

		ob_start();
		echo '<div class="moksafowo-ecpay-invoice-meta" data-order-id="' . esc_attr( (string) $order->get_id() ) . '">';

		if ( '' !== $inv ) {
			echo '<p><strong>' . esc_html__( '發票號碼：', 'mo-ectools' ) . '</strong>' . esc_html( $inv ) . '</p>';
			if ( '' !== $issued_at ) {
				echo '<p><strong>' . esc_html__( '開立時間：', 'mo-ectools' ) . '</strong>' . esc_html( $issued_at ) . '</p>';
			}
			echo '<p><strong>' . esc_html__( '隨機碼：', 'mo-ectools' ) . '</strong>' . esc_html( $rand ) . '</p>';
			echo '<p><strong>' . esc_html__( '開立方式：', 'mo-ectools' ) . '</strong>' . esc_html__( '立即開立', 'mo-ectools' ) . '</p>';
			if ( '' !== $carrier_t && 'b2b' !== $type ) {
				echo '<p><strong>' . esc_html__( '開立類型：', 'mo-ectools' ) . '</strong>' . esc_html( self::carrier_label( $carrier_t ) ) . '</p>';
			} elseif ( 'b2b' === $type ) {
				echo '<p><strong>' . esc_html__( '開立類型：', 'mo-ectools' ) . '</strong>' . esc_html__( '公司統編', 'mo-ectools' ) . '</p>';
			} elseif ( 'b2c_donate' === $type ) {
				echo '<p><strong>' . esc_html__( '開立類型：', 'mo-ectools' ) . '</strong>' . esc_html__( '捐贈', 'mo-ectools' ) . '</p>';
				$love_code = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );
				if ( '' !== $love_code ) {
					$org_name = InvoiceChannels::donate_org_name( 'moksafowo_ecpay_invoice', $love_code );
					echo '<p><strong>' . esc_html__( '愛心碼：', 'mo-ectools' ) . '</strong>' . esc_html( $love_code );
					if ( '' !== $org_name ) {
						echo ' (' . esc_html( $org_name ) . ')';
					}
					echo '</p>';
				}
			}
			echo '<p><strong>' . esc_html__( '發票對象：', 'mo-ectools' ) . '</strong>' . esc_html( self::issue_label( $type ) ) . '</p>';
			if ( '' !== $ubn ) {
				echo '<p><strong>' . esc_html__( '統一編號：', 'mo-ectools' ) . '</strong>' . esc_html( $ubn ) . ( '' !== $buyer_name ? ' (' . esc_html( $buyer_name ) . ')' : '' ) . '</p>';
			}
			if ( '' !== $carrier_n ) {
				echo '<p><strong>' . esc_html__( '載具編號：', 'mo-ectools' ) . '</strong>' . esc_html( $carrier_n ) . '</p>';
			}
			if ( '' !== $invalid_at ) {
				echo '<p style="color:#c00;"><strong>' . esc_html__( '已作廢：', 'mo-ectools' ) . '</strong>' . esc_html( $invalid_at ) . '</p>';
				echo '<hr style="margin:.6em 0;border:0;border-top:1px solid #dcdcde;">';
				echo '<p style="margin:0 0 .4em;font-weight:600;">' . esc_html__( '重新開立發票', 'mo-ectools' ) . '</p>';
				self::render_issue_controls( $order );
			} else {
				echo '<p style="margin-top:.6em;">';
				echo '<button type="button" class="button moksafowo-ecpay-invoice-invalid">' . esc_html__( '作廢發票', 'mo-ectools' ) . '</button> ';
				echo '<button type="button" class="button moksafowo-ecpay-invoice-allowance">' . esc_html__( '開立折讓單', 'mo-ectools' ) . '</button>';
				echo '</p>';
				echo '<div class="moksafowo-inv-invalid-form" style="display:none;margin-top:.5em;">';
				echo '<input type="text" class="moksafowo-inv-invalid-reason" maxlength="20" style="display:block;width:100%;margin-bottom:.4em;" placeholder="' . esc_attr__( '作廢原因（最多 20 字）', 'mo-ectools' ) . '">';
				echo '<button type="button" class="button button-primary moksafowo-ecpay-invoice-invalid-confirm">' . esc_html__( '確認作廢', 'mo-ectools' ) . '</button> ';
				echo '<button type="button" class="button moksafowo-inv-invalid-cancel">' . esc_html__( '取消', 'mo-ectools' ) . '</button>';
				echo '</div>';
				// 折讓金額 — 內聯輸入（取代 JS prompt），按「開立折讓單」展開
				echo '<div class="moksafowo-inv-allowance-form" style="display:none;margin-top:.5em;">';
				echo '<input type="number" class="moksafowo-inv-allowance-amount" min="1" step="1" style="display:block;width:100%;margin-bottom:.4em;" placeholder="' . esc_attr__( '折讓金額（整數）', 'mo-ectools' ) . '">';
				echo '<button type="button" class="button button-primary moksafowo-ecpay-invoice-allowance-confirm">' . esc_html__( '確認折讓', 'mo-ectools' ) . '</button> ';
				echo '<button type="button" class="button moksafowo-inv-allowance-cancel">' . esc_html__( '取消', 'mo-ectools' ) . '</button>';
				echo '</div>';
			}
			if ( '' !== $allowance ) {
				$amt = (string) $order->get_meta( Keys::ECPAY_INVOICE_ALLOWANCE_AMT );
				echo '<p><strong>' . esc_html__( '折讓單號：', 'mo-ectools' ) . '</strong>' . esc_html( $allowance ) . ( '' !== $amt ? ' (' . esc_html__( '金額：', 'mo-ectools' ) . esc_html( $amt ) . ')' : '' ) . '</p>';
			}
			echo '<p class="moksafowo-inv-msg" style="margin:.4em 0 0;"></p>';
		} else {
			// 後台手動開立 — 可編輯欄位（選項受發票設定限制），預填顧客結帳值或設定預設
			self::render_issue_controls( $order );
			echo '<p class="moksafowo-inv-msg" style="margin:.4em 0 0;"></p>';
		}

		wp_nonce_field( self::NONCE_ACTION, 'moksafowo_ecpay_invoice_nonce' );
		echo '</div>';

		$cards[] = [
			'slot'  => 'invoice',
			'title' => __( '發票資訊', 'mo-ectools' ),
			'html'  => (string) ob_get_clean(),
		];
		return $cards;
	}

	/** 手動開立 / 重新開立的可編輯表單 + 更新 / 開立按鈕（編輯態與作廢後重開共用）。 */
	private static function render_issue_controls( \WC_Order $order ): void {
		AdminIssueForm::render( $order, 'moksafowo_ecpay_invoice' );
		echo '<p class="moksafowo-inv-dirty-hint" style="display:none;margin:.5em 0 0;color:#b26900;">' . esc_html__( '欄位已修改，請先按「更新」儲存並確認後再開立。', 'mo-ectools' ) . '</p>';
		echo '<p style="margin-top:.8em;">';
		echo '<button type="button" class="button moksafowo-ecpay-invoice-update">' . esc_html__( '更新', 'mo-ectools' ) . '</button> ';
		echo '<button type="button" class="button button-primary moksafowo-ecpay-invoice-issue" disabled>' . esc_html__( '開立發票', 'mo-ectools' ) . '</button>';
		echo '</p>';
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
		$handle  = 'moksafowo-ecpay-invoice-admin';
		$js_path = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/EcpayInvoice/assets/js/admin-meta-box.js';
		$ver     = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOKSAFOWO_VERSION;
		wp_register_script(
			$handle,
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/EcpayInvoice/assets/js/admin-meta-box.js',
			[ 'jquery' ],
			$ver,
			true
		);
		wp_localize_script(
			$handle,
			'moksafowo_ecpay_invoice_admin',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'i18n'     => [
					'updating'              => __( '更新中…', 'mo-ectools' ),
					'updated'               => __( '已更新，可開立發票。', 'mo-ectools' ),
					'update_fail'           => __( '更新失敗：', 'mo-ectools' ),
					'issuing'               => __( '開立中…', 'mo-ectools' ),
					'issue_ok'              => __( '發票已開立。', 'mo-ectools' ),
					'issue_fail'            => __( '開立失敗：', 'mo-ectools' ),
					'need_donate'           => __( '請選擇捐贈單位（或輸入愛心碼）。', 'mo-ectools' ),
					'need_ubn'              => __( '請輸入統一編號。', 'mo-ectools' ),
					'need_cnum'             => __( '請輸入載具編號。', 'mo-ectools' ),
					'cnum_mobile'           => __( '手機條碼（/ 開頭 + 7 碼，限 0-9 A-Z . + -）', 'mo-ectools' ),
					'cnum_cert'             => __( '自然人憑證（2 大寫字母 + 14 碼數字）', 'mo-ectools' ),
					'invalidating'          => __( '作廢中…', 'mo-ectools' ),
					'invalid_need_reason'   => __( '請輸入作廢原因。', 'mo-ectools' ),
					'invalid_ok'            => __( '發票已作廢。', 'mo-ectools' ),
					'invalid_fail'          => __( '作廢失敗：', 'mo-ectools' ),
					'allowancing'           => __( '折讓中…', 'mo-ectools' ),
					'allowance_need_amount' => __( '請輸入折讓金額。', 'mo-ectools' ),
					'allowance_ok'          => __( '折讓單已開立。', 'mo-ectools' ),
					'allowance_fail'        => __( '折讓失敗：', 'mo-ectools' ),
					'unknown_error'         => __( '未知錯誤，請稍後再試或查看記錄。', 'mo-ectools' ),
				],
			]
		);
		wp_enqueue_script( $handle );
	}

	/** 「更新」按鈕 — 只把後台手動挑的欄位存回 meta（不開立），前端隨後重整確認。 */
	public static function ajax_save(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '找不到訂單。', 'mo-ectools' ) ], 404 );
		}
		$err = AdminIssueForm::validate();
		if ( null !== $err ) {
			wp_send_json_error( [ 'message' => $err ] );
		}
		AdminIssueForm::save( $order, 'moksafowo_ecpay_invoice' );
		wp_send_json_success( [ 'message' => __( '已更新。', 'mo-ectools' ) ] );
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

		$err = AdminIssueForm::validate();
		if ( null !== $err ) {
			wp_send_json_error( [ 'message' => $err ] );
		}
		// 開立前再存一次（與「更新」一致），確保用的是畫面上的值。
		AdminIssueForm::save( $order, 'moksafowo_ecpay_invoice' );

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
			'b2b'         => __( '公司（統一編號）', 'mo-ectools' ),
			'b2c_donate'  => __( '捐贈', 'mo-ectools' ),
			'b2c_carrier' => __( '個人（載具）', 'mo-ectools' ),
			default       => $type,
		};
	}

	private static function carrier_label( string $c ): string {
		return match ( $c ) {
			'mobile' => __( '手機條碼', 'mo-ectools' ),
			'cert'   => __( '自然人憑證', 'mo-ectools' ),
			'paper'  => __( '紙本發票', 'mo-ectools' ),
			'member' => __( '會員載具', 'mo-ectools' ),
			default  => $c,
		};
	}
}
