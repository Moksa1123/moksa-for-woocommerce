<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice\Admin;

use Moksafowo\Modules\EcpayInvoice\Operations\Allowance;
use Moksafowo\Modules\EcpayInvoice\Operations\Invalid;
use Moksafowo\Modules\EcpayInvoice\Operations\Issue;
use Moksafowo\Modules\Shared\Admin\OrderInfoLayout;
use Moksafowo\Modules\Shared\Invoice\AdminIssueForm;
use Moksafowo\Modules\Shared\Invoice\InvoiceChannels;
use Moksafowo\Order\Meta\Keys;

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
			if ( str_contains( (string) $key, 'moksafowo/invoice' ) ) {
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
			echo '<p><strong>' . esc_html__( 'Invoice number:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $inv ) . '</p>';
			if ( '' !== $issued_at ) {
				echo '<p><strong>' . esc_html__( 'Issued at:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $issued_at ) . '</p>';
			}
			echo '<p><strong>' . esc_html__( 'Random code:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $rand ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Issued by:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html__( 'Issue immediately', 'moksa-for-woocommerce' ) . '</p>';
			if ( '' !== $carrier_t && 'b2b' !== $type ) {
				echo '<p><strong>' . esc_html__( 'Invoice type:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::carrier_label( $carrier_t ) ) . '</p>';
			} elseif ( 'b2b' === $type ) {
				echo '<p><strong>' . esc_html__( 'Invoice type:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html__( 'Company tax ID', 'moksa-for-woocommerce' ) . '</p>';
			} elseif ( 'b2c_donate' === $type ) {
				echo '<p><strong>' . esc_html__( 'Invoice type:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html__( 'Donation', 'moksa-for-woocommerce' ) . '</p>';
				$love_code = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );
				if ( '' !== $love_code ) {
					$org_name = InvoiceChannels::donate_org_name( 'moksafowo_ecpay_invoice', $love_code );
					echo '<p><strong>' . esc_html__( 'Love code:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $love_code );
					if ( '' !== $org_name ) {
						echo ' (' . esc_html( $org_name ) . ')';
					}
					echo '</p>';
				}
			}
			echo '<p><strong>' . esc_html__( 'Issued to:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::issue_label( $type ) ) . '</p>';
			if ( '' !== $ubn ) {
				echo '<p><strong>' . esc_html__( 'Tax ID:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $ubn ) . ( '' !== $buyer_name ? ' (' . esc_html( $buyer_name ) . ')' : '' ) . '</p>';
			}
			if ( '' !== $carrier_n ) {
				echo '<p><strong>' . esc_html__( 'Carrier number:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $carrier_n ) . '</p>';
			}
			if ( '' !== $invalid_at ) {
				echo '<p style="color:#c00;"><strong>' . esc_html__( 'Voided:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $invalid_at ) . '</p>';
				echo '<hr style="margin:.6em 0;border:0;border-top:1px solid #dcdcde;">';
				echo '<p style="margin:0 0 .4em;font-weight:600;">' . esc_html__( 'Reissue invoice', 'moksa-for-woocommerce' ) . '</p>';
				self::render_issue_controls( $order );
			} else {
				echo '<p style="margin-top:.6em;">';
				echo '<button type="button" class="button moksafowo-ecpay-invoice-invalid">' . esc_html__( 'Void invoice', 'moksa-for-woocommerce' ) . '</button> ';
				echo '<button type="button" class="button moksafowo-ecpay-invoice-allowance">' . esc_html__( 'Issue allowance', 'moksa-for-woocommerce' ) . '</button>';
				echo '</p>';
				echo '<div class="moksafowo-inv-invalid-form" style="display:none;margin-top:.5em;">';
				echo '<input type="text" class="moksafowo-inv-invalid-reason" maxlength="20" style="display:block;width:100%;margin-bottom:.4em;" placeholder="' . esc_attr__( 'Reason for voiding (up to 20 characters)', 'moksa-for-woocommerce' ) . '">';
				echo '<button type="button" class="button button-primary moksafowo-ecpay-invoice-invalid-confirm">' . esc_html__( 'Confirm void', 'moksa-for-woocommerce' ) . '</button> ';
				echo '<button type="button" class="button moksafowo-inv-invalid-cancel">' . esc_html__( 'Cancel', 'moksa-for-woocommerce' ) . '</button>';
				echo '</div>';
				// 折讓金額 — 內聯輸入（取代 JS prompt），按「開立折讓單」展開
				echo '<div class="moksafowo-inv-allowance-form" style="display:none;margin-top:.5em;">';
				echo '<input type="number" class="moksafowo-inv-allowance-amount" min="1" step="1" style="display:block;width:100%;margin-bottom:.4em;" placeholder="' . esc_attr__( 'Allowance amount (whole numbers only)', 'moksa-for-woocommerce' ) . '">';
				echo '<button type="button" class="button button-primary moksafowo-ecpay-invoice-allowance-confirm">' . esc_html__( 'Confirm allowance', 'moksa-for-woocommerce' ) . '</button> ';
				echo '<button type="button" class="button moksafowo-inv-allowance-cancel">' . esc_html__( 'Cancel', 'moksa-for-woocommerce' ) . '</button>';
				echo '</div>';
			}
			if ( '' !== $allowance ) {
				$amt = (string) $order->get_meta( Keys::ECPAY_INVOICE_ALLOWANCE_AMT );
				echo '<p><strong>' . esc_html__( 'Allowance number:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $allowance ) . ( '' !== $amt ? ' (' . esc_html__( 'Amount:', 'moksa-for-woocommerce' ) . esc_html( $amt ) . ')' : '' ) . '</p>';
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
			'title' => __( 'Invoice details', 'moksa-for-woocommerce' ),
			'html'  => (string) ob_get_clean(),
		];
		return $cards;
	}

	/** 手動開立 / 重新開立的可編輯表單 + 更新 / 開立按鈕（編輯態與作廢後重開共用）。 */
	private static function render_issue_controls( \WC_Order $order ): void {
		AdminIssueForm::render( $order, 'moksafowo_ecpay_invoice' );
		echo '<p class="moksafowo-inv-dirty-hint" style="display:none;margin:.5em 0 0;color:#b26900;">' . esc_html__( 'The fields have changed. Save them with Update before issuing the invoice.', 'moksa-for-woocommerce' ) . '</p>';
		echo '<p style="margin-top:.8em;">';
		echo '<button type="button" class="button moksafowo-ecpay-invoice-update">' . esc_html__( 'Update', 'moksa-for-woocommerce' ) . '</button> ';
		echo '<button type="button" class="button button-primary moksafowo-ecpay-invoice-issue" disabled>' . esc_html__( 'Issue invoice', 'moksa-for-woocommerce' ) . '</button>';
		echo '</p>';
	}

	private static function issue_label( string $type ): string {
		switch ( $type ) {
			case 'b2c_carrier':
			case 'b2c_paper':
				return __( 'Individual', 'moksa-for-woocommerce' );
			case 'b2b':
				return __( 'Company', 'moksa-for-woocommerce' );
			case 'b2c_donate':
				return __( 'Donation', 'moksa-for-woocommerce' );
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
					'updating'              => __( 'Updating…', 'moksa-for-woocommerce' ),
					'updated'               => __( 'Updated. The invoice can now be issued.', 'moksa-for-woocommerce' ),
					'update_fail'           => __( 'Could not update:', 'moksa-for-woocommerce' ),
					'issuing'               => __( 'Issuing…', 'moksa-for-woocommerce' ),
					'issue_ok'              => __( 'The invoice was issued.', 'moksa-for-woocommerce' ),
					'issue_fail'            => __( 'Could not issue:', 'moksa-for-woocommerce' ),
					'need_donate'           => __( 'Please choose a charity, or enter a love code.', 'moksa-for-woocommerce' ),
					'need_ubn'              => __( 'Please enter a tax ID.', 'moksa-for-woocommerce' ),
					'need_cnum'             => __( 'Please enter a carrier number.', 'moksa-for-woocommerce' ),
					'cnum_mobile'           => __( 'Mobile barcode (/ followed by 7 characters from 0-9, A-Z, ., + and -)', 'moksa-for-woocommerce' ),
					'cnum_cert'             => __( 'Citizen Digital Certificate (2 uppercase letters followed by 14 digits)', 'moksa-for-woocommerce' ),
					'invalidating'          => __( 'Voiding…', 'moksa-for-woocommerce' ),
					'invalid_need_reason'   => __( 'Please enter a reason for voiding.', 'moksa-for-woocommerce' ),
					'invalid_ok'            => __( 'The invoice was voided.', 'moksa-for-woocommerce' ),
					'invalid_fail'          => __( 'Could not void:', 'moksa-for-woocommerce' ),
					'allowancing'           => __( 'Issuing the allowance…', 'moksa-for-woocommerce' ),
					'allowance_need_amount' => __( 'Please enter an allowance amount.', 'moksa-for-woocommerce' ),
					'allowance_ok'          => __( 'The allowance was issued.', 'moksa-for-woocommerce' ),
					'allowance_fail'        => __( 'Could not issue the allowance:', 'moksa-for-woocommerce' ),
					'unknown_error'         => __( 'Something went wrong. Please try again later, or check the logs.', 'moksa-for-woocommerce' ),
				],
			]
		);
		wp_enqueue_script( $handle );
	}

	/** 「更新」按鈕 — 只把後台手動挑的欄位存回 meta（不開立），前端隨後重整確認。 */
	public static function ajax_save(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}
		$err = AdminIssueForm::validate();
		if ( null !== $err ) {
			wp_send_json_error( [ 'message' => $err ] );
		}
		AdminIssueForm::save( $order, 'moksafowo_ecpay_invoice' );
		wp_send_json_success( [ 'message' => __( 'Updated.', 'moksa-for-woocommerce' ) ] );
	}

	public static function ajax_issue(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
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
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}

		$result = Invalid::run( $order, $reason );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public static function ajax_allowance(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$amount   = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}

		$result = Allowance::run( $order, $amount );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	private static function type_label( string $type ): string {
		return match ( $type ) {
			'b2b'         => __( 'Company (tax ID)', 'moksa-for-woocommerce' ),
			'b2c_donate'  => __( 'Donation', 'moksa-for-woocommerce' ),
			'b2c_carrier' => __( 'Individual (carrier)', 'moksa-for-woocommerce' ),
			default       => $type,
		};
	}

	private static function carrier_label( string $c ): string {
		return match ( $c ) {
			'mobile' => __( 'Mobile barcode', 'moksa-for-woocommerce' ),
			'cert'   => __( 'Citizen Digital Certificate', 'moksa-for-woocommerce' ),
			'paper'  => __( 'Paper invoice', 'moksa-for-woocommerce' ),
			'member' => __( 'Member carrier', 'moksa-for-woocommerce' ),
			default  => $c,
		};
	}
}
