<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared\Invoice;

use MoksaWeb\Mowc\Modules\Shared\Admin\OrderInfoLayout;
use MoksaWeb\Mowc\Order\Meta\Keys;

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
			if ( str_contains( (string) $key, 'mowp/invoice' ) ) {
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

		$inv         = (string) $order->get_meta( static::invoice_number_meta_key() );
		$issued_at   = (string) $order->get_meta( static::issued_at_meta_key() );
		$invalid_at  = (string) $order->get_meta( static::invalid_at_meta_key() );
		$type        = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$ubn         = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
		$buyer_name  = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
		$carrier_t   = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$carrier_n   = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );
		$love_code   = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );

		$key        = static::provider_key();
		$prefix     = static::ajax_action_prefix();
		$nonce_html = wp_nonce_field( static::nonce_action(), $prefix . '_nonce', true, false );

		ob_start();
		echo '<div class="moksafowo-invoice-meta" data-provider="' . esc_attr( $key ) . '" data-prefix="' . esc_attr( $prefix ) . '" data-order-id="' . esc_attr( (string) $order->get_id() ) . '">';

		if ( '' !== $inv && 'zero' !== $inv && 'negative' !== $inv ) {
			echo '<p><strong>' . esc_html__( '發票號碼：', 'mo-ectools' ) . '</strong>' . esc_html( $inv ) . '</p>';
			if ( '' !== $issued_at ) {
				echo '<p><strong>' . esc_html__( '開立時間：', 'mo-ectools' ) . '</strong>' . esc_html( $issued_at ) . '</p>';
			}
			echo '<p><strong>' . esc_html__( '發票開立：', 'mo-ectools' ) . '</strong>' . esc_html( self::issue_label( $type ) ) . '</p>';
			if ( '' !== $ubn ) {
				echo '<p><strong>' . esc_html__( '統一編號：', 'mo-ectools' ) . '</strong>' . esc_html( $ubn ) . ( '' !== $buyer_name ? ' (' . esc_html( $buyer_name ) . ')' : '' ) . '</p>';
			}
			if ( '' !== $carrier_t && 'b2b' !== $type ) {
				echo '<p><strong>' . esc_html__( '載具類型：', 'mo-ectools' ) . '</strong>' . esc_html( self::carrier_label( $carrier_t ) ) . '</p>';
			}
			if ( '' !== $carrier_n ) {
				echo '<p><strong>' . esc_html__( '載具編號：', 'mo-ectools' ) . '</strong>' . esc_html( $carrier_n ) . '</p>';
			}
			if ( '' !== $love_code ) {
				$org_name = InvoiceChannels::donate_org_name( static::option_prefix(), $love_code );
				echo '<p><strong>' . esc_html__( '愛心碼：', 'mo-ectools' ) . '</strong>' . esc_html( $love_code );
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
				echo '<p style="color:#c00;"><strong>' . esc_html__( '已作廢：', 'mo-ectools' ) . '</strong>' . esc_html( $invalid_at ) . '</p>';
				echo '<hr style="margin:.6em 0;border:0;border-top:1px solid #dcdcde;">';
				echo '<p style="margin:0 0 .4em;font-weight:600;">' . esc_html__( '重新開立發票', 'mo-ectools' ) . '</p>';
				static::render_issue_controls( $order );
			} else {
				echo '<p style="margin-top:.6em;">';
				echo '<button type="button" class="button moksafowo-invoice-invalid">' . esc_html__( '作廢發票', 'mo-ectools' ) . '</button> ';
				if ( static::supports_allowance() ) {
					echo '<button type="button" class="button moksafowo-invoice-allowance">' . esc_html__( '開立折讓單', 'mo-ectools' ) . '</button>';
				}
				echo '</p>';
				// 作廢原因 — 內聯輸入（取代 JS prompt），按「作廢發票」展開
				echo '<div class="moksafowo-inv-invalid-form" style="display:none;margin-top:.5em;">';
				echo '<input type="text" class="moksafowo-inv-invalid-reason" maxlength="20" style="display:block;width:100%;margin-bottom:.4em;" placeholder="' . esc_attr__( '作廢原因（最多 20 字）', 'mo-ectools' ) . '">';
				echo '<button type="button" class="button button-primary moksafowo-invoice-invalid-confirm">' . esc_html__( '確認作廢', 'mo-ectools' ) . '</button> ';
				echo '<button type="button" class="button moksafowo-inv-invalid-cancel">' . esc_html__( '取消', 'mo-ectools' ) . '</button>';
				echo '</div>';
				if ( static::supports_allowance() ) {
					// 折讓金額 — 內聯輸入（取代 JS prompt），按「開立折讓單」展開
					echo '<div class="moksafowo-inv-allowance-form" style="display:none;margin-top:.5em;">';
					echo '<input type="number" class="moksafowo-inv-allowance-amount" min="1" step="1" style="display:block;width:100%;margin-bottom:.4em;" placeholder="' . esc_attr__( '折讓金額（整數）', 'mo-ectools' ) . '">';
					echo '<button type="button" class="button button-primary moksafowo-invoice-allowance-confirm">' . esc_html__( '確認折讓', 'mo-ectools' ) . '</button> ';
					echo '<button type="button" class="button moksafowo-inv-allowance-cancel">' . esc_html__( '取消', 'mo-ectools' ) . '</button>';
					echo '</div>';
				}
			}

			$allowance_no = static::allowance_no_meta_key() !== '' ? (string) $order->get_meta( static::allowance_no_meta_key() ) : '';
			if ( '' !== $allowance_no ) {
				$amt = static::allowance_amt_meta_key() !== '' ? (string) $order->get_meta( static::allowance_amt_meta_key() ) : '';
				echo '<p><strong>' . esc_html__( '折讓單號：', 'mo-ectools' ) . '</strong>' . esc_html( $allowance_no );
				if ( '' !== $amt ) {
					echo ' (' . esc_html__( '金額：', 'mo-ectools' ) . esc_html( $amt ) . ')';
				}
				echo '</p>';
			}
		} elseif ( 'zero' === $inv || 'negative' === $inv ) {
			echo '<p style="color:#646970;">' . esc_html__( '訂單金額為 0 或負，未開立發票。', 'mo-ectools' ) . '</p>';
		} else {
			// 後台手動開立 — 可編輯欄位（選項受發票設定限制），預填顧客結帳值或設定預設
			static::render_issue_controls( $order );
		}

		echo '<p class="moksafowo-inv-msg" style="margin:.4em 0 0;"></p>';
		echo $nonce_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field returns escaped HTML.
		echo '</div>';

		$cards[] = [
			'slot'  => 'invoice',
			'title' => sprintf( /* translators: %s: provider label */ __( '%s 發票資訊', 'mo-ectools' ), static::provider_label() ),
			'html'  => (string) ob_get_clean(),
		];
		return $cards;
	}

	/** 手動開立 / 重新開立的可編輯表單 + 更新 / 開立按鈕（編輯態與作廢後重開共用）。 */
	protected static function render_issue_controls( \WC_Order $order ): void {
		AdminIssueForm::render( $order, static::option_prefix() );
		echo '<p class="moksafowo-inv-dirty-hint" style="display:none;margin:.5em 0 0;color:#b26900;">' . esc_html__( '欄位已修改，請先按「更新」儲存並確認後再開立。', 'mo-ectools' ) . '</p>';
		echo '<p style="margin-top:.8em;">';
		echo '<button type="button" class="button moksafowo-invoice-update">' . esc_html__( '更新', 'mo-ectools' ) . '</button> ';
		echo '<button type="button" class="button button-primary moksafowo-invoice-issue" disabled>' . esc_html__( '開立發票', 'mo-ectools' ) . '</button>';
		echo '</p>';
	}

	private static function issue_label( string $type ): string {
		return match ( $type ) {
			'b2c_carrier' => __( '個人', 'mo-ectools' ),
			'b2b'         => __( '公司', 'mo-ectools' ),
			'b2c_donate'  => __( '捐贈', 'mo-ectools' ),
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
			wp_localize_script( $handle, 'moksafowo_invoice_admin_i18n', [
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'issuing'         => __( '開立中…', 'mo-ectools' ),
				'issue_ok'        => __( '發票已開立。', 'mo-ectools' ),
				'issue_fail'      => __( '開立失敗：', 'mo-ectools' ),
				'need_donate'     => __( '請選擇捐贈單位（或輸入愛心碼）。', 'mo-ectools' ),
				'need_ubn'        => __( '請輸入統一編號。', 'mo-ectools' ),
				'need_cnum'       => __( '請輸入載具編號。', 'mo-ectools' ),
				'updating'        => __( '更新中…', 'mo-ectools' ),
				'updated'         => __( '已更新，可開立發票。', 'mo-ectools' ),
				'update_fail'     => __( '更新失敗：', 'mo-ectools' ),
				'cnum_mobile'     => __( '手機條碼（/ 開頭 + 7 碼，限 0-9 A-Z . + -）', 'mo-ectools' ),
				'cnum_cert'       => __( '自然人憑證（2 大寫字母 + 14 碼數字）', 'mo-ectools' ),
				'invalidating'    => __( '作廢中…', 'mo-ectools' ),
				'invalid_need_reason' => __( '請輸入作廢原因。', 'mo-ectools' ),
				'invalid_ok'      => __( '發票已作廢。', 'mo-ectools' ),
				'invalid_fail'    => __( '作廢失敗：', 'mo-ectools' ),
				'allowancing'     => __( '折讓中…', 'mo-ectools' ),
				'allowance_need_amount' => __( '請輸入折讓金額。', 'mo-ectools' ),
				'allowance_ok'    => __( '折讓單已開立。', 'mo-ectools' ),
				'allowance_fail'  => __( '折讓失敗：', 'mo-ectools' ),
				'unknown_error'     => __( '未知錯誤，請稍後再試或查看記錄。', 'mo-ectools' ),
			] );
		}
		wp_enqueue_script( $handle );
	}

	/** 「更新」按鈕 — 只把後台手動挑的欄位存回 meta（不開立），前端隨後重整確認。 */
	public static function ajax_save(): void {
		[ $order ] = self::ajax_authenticate();
		$err = AdminIssueForm::validate();
		if ( null !== $err ) {
			wp_send_json_error( [ 'message' => $err ] );
		}
		AdminIssueForm::save( $order, static::option_prefix() );
		wp_send_json_success( [ 'message' => __( '已更新。', 'mo-ectools' ) ] );
	}

	public static function ajax_issue(): void {
		[ $order ] = self::ajax_authenticate();
		$err = AdminIssueForm::validate();
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
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$result = call_user_func( static::invalid_callable(), $order, $reason );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public static function ajax_allowance(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- AJAX handler; check_ajax_referer() called via self::ajax_authenticate() at method entry.
		[ $order ] = self::ajax_authenticate();
		$amount   = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;
		$callable = static::allowance_callable();
		if ( null === $callable ) {
			wp_send_json_error( [ 'message' => __( '此 provider 不支援折讓。', 'mo-ectools' ) ] );
		}
		$result = call_user_func( $callable, $order, $amount );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	
	private static function ajax_authenticate(): array {
		check_ajax_referer( static::nonce_action(), 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '找不到訂單。', 'mo-ectools' ) ], 404 );
		}
		return [ $order ];
	}
}
