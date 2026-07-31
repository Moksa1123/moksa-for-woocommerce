<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Invoice;

use Moksafowo\Modules\Shared\Admin\OrderInfoLayout;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;


abstract class AbstractAdminMetaBox {

	private const CAPABILITY = 'edit_shop_orders';

	abstract protected static function provider_key(): string;          // 'ezpay', 'smilepay', 'paynow', 'amego'

	abstract protected static function provider_label(): string;        // '綠界', 'ezPay', 'AMEGO'…

	abstract protected static function nonce_action(): string;          // 'moksafowo_ezpay_invoice_admin'

	abstract protected static function ajax_action_prefix(): string;    // 'moksafowo_ezpay_invoice'

	abstract protected static function invoice_number_meta_key(): string;

	abstract protected static function issued_at_meta_key(): string;

	abstract protected static function invalid_at_meta_key(): string;


	abstract protected static function issue_callable(): callable;


	abstract protected static function invalid_callable(): callable;

	protected static function supports_allowance(): bool {
		return false;
	}


	protected static function allowance_callable(): ?callable {
		return null;
	}

	protected static function allowance_no_meta_key(): string {
		return '';
	}

	protected static function allowance_amt_meta_key(): string {
		return '';
	}


	protected static function extra_card_meta( \WC_Order $order ): array {
		return [];
	}

	/** 該 provider 發票設定的 option key 前綴，AdminIssueForm 用來讀「允許捐贈 / 統編 / 預設載具」等設定。 */
	protected static function option_prefix(): string {
		return 'moksafowo_' . static::provider_key() . '_invoice';
	}

	public static function init(): void {
		OrderInfoLayout::boot();
		add_filter( 'moksafowo_order_info_cards', [ static::class, 'add_card' ], 30, 2 );
		// WC Blocks 把 location='order' additional fields 自動 merge 進 admin shipping section，會跟我們 invoice 區重複，移除
		add_filter( 'woocommerce_admin_shipping_fields', [ static::class, 'hide_invoice_in_admin_shipping' ], 11 );

		$prefix = static::ajax_action_prefix();
		add_action( "wp_ajax_{$prefix}_save", [ static::class, 'ajax_save' ] );
		add_action( "wp_ajax_{$prefix}_issue", [ static::class, 'ajax_issue' ] );
		add_action( "wp_ajax_{$prefix}_invalid", [ static::class, 'ajax_invalid' ] );
		if ( static::supports_allowance() ) {
			add_action( "wp_ajax_{$prefix}_allowance", [ static::class, 'ajax_allowance' ] );
		}
		add_action( 'admin_enqueue_scripts', [ static::class, 'enqueue' ] );
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
		$provider_in_order = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		if ( '' !== $provider_in_order && static::provider_key() !== $provider_in_order ) {
			return $cards;
		}
		foreach ( $cards as $c ) {
			if ( ( $c['slot'] ?? '' ) === 'invoice' ) {
				return $cards;
			}
		}

		$inv        = (string) $order->get_meta( static::invoice_number_meta_key() );
		$issued_at  = (string) $order->get_meta( static::issued_at_meta_key() );
		$invalid_at = (string) $order->get_meta( static::invalid_at_meta_key() );
		$type       = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$ubn        = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
		$buyer_name = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
		$carrier_t  = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$carrier_n  = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );
		$love_code  = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );

		$key        = static::provider_key();
		$prefix     = static::ajax_action_prefix();
		$nonce_html = wp_nonce_field( static::nonce_action(), $prefix . '_nonce', true, false );

		ob_start();
		echo '<div class="moksafowo-invoice-meta" data-provider="' . esc_attr( $key ) . '" data-prefix="' . esc_attr( $prefix ) . '" data-order-id="' . esc_attr( (string) $order->get_id() ) . '">';

		if ( '' !== $inv && 'zero' !== $inv && 'negative' !== $inv ) {
			echo '<p><strong>' . esc_html__( 'Invoice number:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $inv ) . '</p>';
			if ( '' !== $issued_at ) {
				echo '<p><strong>' . esc_html__( 'Issued at:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $issued_at ) . '</p>';
			}
			echo '<p><strong>' . esc_html__( 'Invoice issued:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::issue_label( $type ) ) . '</p>';
			if ( '' !== $ubn ) {
				echo '<p><strong>' . esc_html__( 'Tax ID:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $ubn ) . ( '' !== $buyer_name ? ' (' . esc_html( $buyer_name ) . ')' : '' ) . '</p>';
			}
			if ( '' !== $carrier_t && 'b2b' !== $type ) {
				echo '<p><strong>' . esc_html__( 'Carrier type:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( self::carrier_label( $carrier_t ) ) . '</p>';
			}
			if ( '' !== $carrier_n ) {
				echo '<p><strong>' . esc_html__( 'Carrier number:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $carrier_n ) . '</p>';
			}
			if ( '' !== $love_code ) {
				$org_name = InvoiceChannels::donate_org_name( static::option_prefix(), $love_code );
				echo '<p><strong>' . esc_html__( 'Love code:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $love_code );
				if ( '' !== $org_name ) {
					echo ' (' . esc_html( $org_name ) . ')';
				}
				echo '</p>';
			}

			// 子類額外 meta（barcode / qrcode / random number 等）
			foreach ( static::extra_card_meta( $order ) as $label => $value ) {
				if ( '' === $value ) {
					continue;
				}
				echo '<p><strong>' . esc_html( $label ) . '：</strong>' . esc_html( $value ) . '</p>';
			}

			if ( '' !== $invalid_at ) {
				echo '<p style="color:#c00;"><strong>' . esc_html__( 'Voided:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $invalid_at ) . '</p>';
				echo '<hr style="margin:.6em 0;border:0;border-top:1px solid #dcdcde;">';
				echo '<p style="margin:0 0 .4em;font-weight:600;">' . esc_html__( 'Reissue invoice', 'moksa-for-woocommerce' ) . '</p>';
				static::render_issue_controls( $order );
			} else {
				echo '<p style="margin-top:.6em;">';
				echo '<button type="button" class="button moksafowo-invoice-invalid">' . esc_html__( 'Void invoice', 'moksa-for-woocommerce' ) . '</button> ';
				if ( static::supports_allowance() ) {
					echo '<button type="button" class="button moksafowo-invoice-allowance">' . esc_html__( 'Issue allowance', 'moksa-for-woocommerce' ) . '</button>';
				}
				echo '</p>';
				// 作廢原因 — 內聯輸入（取代 JS prompt），按「作廢發票」展開
				echo '<div class="moksafowo-inv-invalid-form" style="display:none;margin-top:.5em;">';
				echo '<input type="text" class="moksafowo-inv-invalid-reason" maxlength="20" style="display:block;width:100%;margin-bottom:.4em;" placeholder="' . esc_attr__( 'Reason for voiding (up to 20 characters)', 'moksa-for-woocommerce' ) . '">';
				echo '<button type="button" class="button button-primary moksafowo-invoice-invalid-confirm">' . esc_html__( 'Confirm void', 'moksa-for-woocommerce' ) . '</button> ';
				echo '<button type="button" class="button moksafowo-inv-invalid-cancel">' . esc_html__( 'Cancel', 'moksa-for-woocommerce' ) . '</button>';
				echo '</div>';
				if ( static::supports_allowance() ) {
					// 折讓金額 — 內聯輸入（取代 JS prompt），按「開立折讓單」展開
					echo '<div class="moksafowo-inv-allowance-form" style="display:none;margin-top:.5em;">';
					echo '<input type="number" class="moksafowo-inv-allowance-amount" min="1" step="1" style="display:block;width:100%;margin-bottom:.4em;" placeholder="' . esc_attr__( 'Allowance amount (whole numbers only)', 'moksa-for-woocommerce' ) . '">';
					echo '<button type="button" class="button button-primary moksafowo-invoice-allowance-confirm">' . esc_html__( 'Confirm allowance', 'moksa-for-woocommerce' ) . '</button> ';
					echo '<button type="button" class="button moksafowo-inv-allowance-cancel">' . esc_html__( 'Cancel', 'moksa-for-woocommerce' ) . '</button>';
					echo '</div>';
				}
			}

			$allowance_no = static::allowance_no_meta_key() !== '' ? (string) $order->get_meta( static::allowance_no_meta_key() ) : '';
			if ( '' !== $allowance_no ) {
				$amt = static::allowance_amt_meta_key() !== '' ? (string) $order->get_meta( static::allowance_amt_meta_key() ) : '';
				echo '<p><strong>' . esc_html__( 'Allowance number:', 'moksa-for-woocommerce' ) . '</strong>' . esc_html( $allowance_no );
				if ( '' !== $amt ) {
					echo ' (' . esc_html__( 'Amount:', 'moksa-for-woocommerce' ) . esc_html( $amt ) . ')';
				}
				echo '</p>';
			}
		} elseif ( 'zero' === $inv || 'negative' === $inv ) {
			echo '<p style="color:#646970;">' . esc_html__( 'The order total is zero or negative, so no invoice was issued.', 'moksa-for-woocommerce' ) . '</p>';
		} else {
			// 後台手動開立 — 可編輯欄位（選項受發票設定限制），預填顧客結帳值或設定預設
			static::render_issue_controls( $order );
		}

		echo '<p class="moksafowo-inv-msg" style="margin:.4em 0 0;"></p>';
		echo $nonce_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field returns escaped HTML.
		echo '</div>';

		$cards[] = [
			'slot'  => 'invoice',
			'title' => sprintf( /* translators: %s: provider label */ __( '%s invoice details', 'moksa-for-woocommerce' ), static::provider_label() ),
			'html'  => (string) ob_get_clean(),
		];
		return $cards;
	}

	/** 手動開立 / 重新開立的可編輯表單 + 更新 / 開立按鈕（編輯態與作廢後重開共用）。 */
	protected static function render_issue_controls( \WC_Order $order ): void {
		AdminIssueForm::render( $order, static::option_prefix() );
		echo '<p class="moksafowo-inv-dirty-hint" style="display:none;margin:.5em 0 0;color:#b26900;">' . esc_html__( 'The fields have changed. Save them with Update before issuing the invoice.', 'moksa-for-woocommerce' ) . '</p>';
		echo '<p style="margin-top:.8em;">';
		echo '<button type="button" class="button moksafowo-invoice-update">' . esc_html__( 'Update', 'moksa-for-woocommerce' ) . '</button> ';
		echo '<button type="button" class="button button-primary moksafowo-invoice-issue" disabled>' . esc_html__( 'Issue invoice', 'moksa-for-woocommerce' ) . '</button>';
		echo '</p>';
	}

	private static function issue_label( string $type ): string {
		return match ( $type ) {
			'b2c_carrier' => __( 'Individual', 'moksa-for-woocommerce' ),
			'b2b'         => __( 'Company', 'moksa-for-woocommerce' ),
			'b2c_donate'  => __( 'Donation', 'moksa-for-woocommerce' ),
			default       => $type,
		};
	}

	private static function carrier_label( string $c ): string {
		return match ( $c ) {
			'mobile' => __( 'Mobile barcode', 'moksa-for-woocommerce' ),
			'cert'   => __( 'Citizen Digital Certificate', 'moksa-for-woocommerce' ),
			'paper'  => __( 'Paper', 'moksa-for-woocommerce' ),
			'member' => __( 'Member carrier', 'moksa-for-woocommerce' ),
			default  => $c,
		};
	}

	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		$handle = 'moksafowo-invoice-admin-meta-box';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			$js_path = MOKSAFOWO_PLUGIN_DIR . 'assets/admin/moksafowo-invoice-admin.js';
			$ver     = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MOKSAFOWO_VERSION;
			wp_register_script(
				$handle,
				MOKSAFOWO_PLUGIN_URL . 'assets/admin/moksafowo-invoice-admin.js',
				[ 'jquery' ],
				$ver,
				true
			);
			wp_localize_script(
				$handle,
				'moksafowo_invoice_admin_i18n',
				[
					'ajax_url'              => admin_url( 'admin-ajax.php' ),
					'issuing'               => __( 'Issuing…', 'moksa-for-woocommerce' ),
					'issue_ok'              => __( 'The invoice was issued.', 'moksa-for-woocommerce' ),
					'issue_fail'            => __( 'Could not issue:', 'moksa-for-woocommerce' ),
					'need_donate'           => __( 'Please choose a charity, or enter a love code.', 'moksa-for-woocommerce' ),
					'need_ubn'              => __( 'Please enter a tax ID.', 'moksa-for-woocommerce' ),
					'need_cnum'             => __( 'Please enter a carrier number.', 'moksa-for-woocommerce' ),
					'updating'              => __( 'Updating…', 'moksa-for-woocommerce' ),
					'updated'               => __( 'Updated. The invoice can now be issued.', 'moksa-for-woocommerce' ),
					'update_fail'           => __( 'Could not update:', 'moksa-for-woocommerce' ),
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
				]
			);
		}
		wp_enqueue_script( $handle );
	}

	/** 「更新」按鈕 — 只把後台手動挑的欄位存回 meta（不開立），前端隨後重整確認。 */
	public static function ajax_save(): void {
		[ $order ] = self::ajax_authenticate();
		$err       = AdminIssueForm::validate();
		if ( null !== $err ) {
			wp_send_json_error( [ 'message' => $err ] );
		}
		AdminIssueForm::save( $order, static::option_prefix() );
		wp_send_json_success( [ 'message' => __( 'Updated.', 'moksa-for-woocommerce' ) ] );
	}

	public static function ajax_issue(): void {
		[ $order ] = self::ajax_authenticate();
		$err       = AdminIssueForm::validate();
		if ( null !== $err ) {
			wp_send_json_error( [ 'message' => $err ] );
		}
		// 作廢後重開 — 清掉舊發票 meta（號碼 / 開立時間 / 作廢時間），讓 provider Issue 重新開立。
		if ( '' !== (string) $order->get_meta( static::invalid_at_meta_key() ) ) {
			$order->delete_meta_data( static::invoice_number_meta_key() );
			$order->delete_meta_data( static::issued_at_meta_key() );
			$order->delete_meta_data( static::invalid_at_meta_key() );
			$order->save();
		}
		// 後台手動挑的發票欄位 → 存回訂單 meta（受設定限制），再交給各 provider 的 Issue 開立。
		AdminIssueForm::save( $order, static::option_prefix() );
		$result = call_user_func( static::issue_callable(), $order );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public static function ajax_invalid(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- AJAX handler; check_ajax_referer() called via self::ajax_authenticate() at method entry.
		[ $order ] = self::ajax_authenticate();
		$reason    = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$result    = call_user_func( static::invalid_callable(), $order, $reason );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public static function ajax_allowance(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- AJAX handler; check_ajax_referer() called via self::ajax_authenticate() at method entry.
		[ $order ] = self::ajax_authenticate();
		$amount    = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;
		$callable  = static::allowance_callable();
		if ( null === $callable ) {
			wp_send_json_error( [ 'message' => __( 'This service does not support allowances.', 'moksa-for-woocommerce' ) ] );
		}
		$result = call_user_func( $callable, $order, $amount );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}


	private static function ajax_authenticate(): array {
		check_ajax_referer( static::nonce_action(), 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}
		return [ $order ];
	}
}
