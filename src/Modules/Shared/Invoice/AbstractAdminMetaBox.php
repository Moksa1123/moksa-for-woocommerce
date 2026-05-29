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

	abstract protected static function nonce_action(): string;          // 'mo_ezpay_invoice_admin'

	abstract protected static function ajax_action_prefix(): string;    // 'mo_ezpay_invoice'

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

	public static function init(): void {
		OrderInfoLayout::boot();
		add_filter( 'mo_order_info_cards', [ static::class, 'add_card' ], 30, 2 );
		// WC Blocks 把 location='order' additional fields 自動 merge 進 admin shipping section，會跟我們 invoice 區重複，移除
		add_filter( 'woocommerce_admin_shipping_fields', [ static::class, 'hide_invoice_in_admin_shipping' ], 11 );

		$prefix = static::ajax_action_prefix();
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

		if ( '' === $inv && '' === $type ) {
			return $cards;
		}

		$key        = static::provider_key();
		$prefix     = static::ajax_action_prefix();
		$nonce_html = wp_nonce_field( static::nonce_action(), $prefix . '_nonce', true, false );

		ob_start();
		echo '<div class="mo-invoice-meta" data-provider="' . esc_attr( $key ) . '" data-prefix="' . esc_attr( $prefix ) . '" data-order-id="' . esc_attr( (string) $order->get_id() ) . '">';

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
				echo '<p><strong>' . esc_html__( '愛心碼：', 'mo-ectools' ) . '</strong>' . esc_html( $love_code ) . '</p>';
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
			} else {
				echo '<p style="margin-top:.6em;">';
				echo '<button type="button" class="button mo-invoice-invalid">' . esc_html__( '作廢發票', 'mo-ectools' ) . '</button> ';
				if ( static::supports_allowance() ) {
					echo '<button type="button" class="button mo-invoice-allowance">' . esc_html__( '開立折讓單', 'mo-ectools' ) . '</button>';
				}
				echo '</p>';
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
			if ( '' !== $type ) {
				echo '<p><strong>' . esc_html__( '發票開立：', 'mo-ectools' ) . '</strong>' . esc_html( self::issue_label( $type ) ) . '</p>';
			}
			if ( '' !== $carrier_t && 'b2b' !== $type ) {
				echo '<p><strong>' . esc_html__( '載具類型：', 'mo-ectools' ) . '</strong>' . esc_html( self::carrier_label( $carrier_t ) ) . '</p>';
			}
			echo '<p style="margin-top:.6em;"><button type="button" class="button button-primary mo-invoice-issue">' . esc_html__( '開立發票', 'mo-ectools' ) . '</button></p>';
		}

		echo $nonce_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field returns escaped HTML.
		echo '</div>';

		$cards[] = [
			'slot'  => 'invoice',
			'title' => sprintf( /* translators: %s: provider label */ __( '%s 發票資訊', 'mo-ectools' ), static::provider_label() ),
			'html'  => (string) ob_get_clean(),
		];
		return $cards;
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
		$handle = 'mo-invoice-admin-meta-box';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				MOWC_PLUGIN_URL . 'assets/admin/mo-invoice-admin.js',
				[ 'jquery' ],
				MOWC_VERSION,
				true
			);
			wp_localize_script( $handle, 'mo_invoice_admin_i18n', [
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'issuing'         => __( '開立中…', 'mo-ectools' ),
				'issue_ok'        => __( '發票已開立。', 'mo-ectools' ),
				'issue_fail'      => __( '開立失敗：', 'mo-ectools' ),
				'invalid_prompt'  => __( '請輸入作廢原因（最多 20 字）：', 'mo-ectools' ),
				'invalid_ok'      => __( '發票已作廢。', 'mo-ectools' ),
				'invalid_fail'    => __( '作廢失敗：', 'mo-ectools' ),
				'allowance_prompt' => __( '請輸入折讓金額（整數）：', 'mo-ectools' ),
				'allowance_ok'    => __( '折讓單已開立。', 'mo-ectools' ),
				'allowance_fail'  => __( '折讓失敗：', 'mo-ectools' ),
				'confirm_invalid'   => __( '確定要作廢這張發票？此動作不可復原。', 'mo-ectools' ),
				'confirm_allowance' => __( '確定要開立折讓單？此動作不可復原。', 'mo-ectools' ),
				'unknown_error'     => __( '未知錯誤，請稍後再試或查看記錄。', 'mo-ectools' ),
			] );
		}
		wp_enqueue_script( $handle );
	}

	public static function ajax_issue(): void {
		[ $order ] = self::ajax_authenticate();
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
