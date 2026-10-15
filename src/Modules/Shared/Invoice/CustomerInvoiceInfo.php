<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Invoice;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 顧客的訂單頁與信件裡的發票資訊。
 *
 * 區塊結帳的發票欄位是 additional fields，WC 會自己列出來，那種訂單只補發票號碼；
 * 傳統結帳的選項只存在 meta，連顧客自己選的類型與載具都要在這裡列。
 */
final class CustomerInvoiceInfo {

	private static bool $booted = false;

	/** @var array<string,string> */
	private static array $carrier_labels = [];

	/**
	 * @param array<string,string> $carrier_labels 載具代碼 → 顯示名稱（會員載具名稱各家不同）。
	 */
	public static function boot( array $carrier_labels ): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted         = true;
		self::$carrier_labels = $carrier_labels;
		add_action( 'woocommerce_order_details_after_customer_details', [ self::class, 'render_order_details' ] );
		add_action( 'woocommerce_email_order_meta', [ self::class, 'render_email' ], 20, 3 );
	}

	public static function render_order_details( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$rows = self::rows( $order );
		if ( empty( $rows ) ) {
			return;
		}
		echo '<section class="moksafowo-invoice-info"><h2 class="woocommerce-column__title">' . esc_html__( 'Invoice details', 'moksa-for-woocommerce' ) . '</h2>';
		echo '<table class="woocommerce-table shop_table"><tbody>';
		foreach ( $rows as [ $label, $value ] ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	public static function render_email( $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( ! $order instanceof \WC_Order || $sent_to_admin ) {
			return;
		}
		$rows = self::rows( $order );
		if ( empty( $rows ) ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html( wp_strip_all_tags( __( 'Invoice details', 'moksa-for-woocommerce' ) ) ) . "\n";
			foreach ( $rows as [ $label, $value ] ) {
				echo esc_html( $label . '：' . $value ) . "\n";
			}
			return;
		}
		echo '<h2>' . esc_html__( 'Invoice details', 'moksa-for-woocommerce' ) . '</h2><ul style="margin:0 0 16px;padding-left:1.2em;">';
		foreach ( $rows as [ $label, $value ] ) {
			echo '<li><strong>' . esc_html( $label ) . '：</strong>' . esc_html( $value ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function rows( \WC_Order $order ): array {
		$rows    = [];
		$invoice = InvoiceNumber::of( $order );
		if ( '' !== $invoice['number'] ) {
			$rows[] = [
				__( 'Invoice number', 'moksa-for-woocommerce' ),
				$invoice['voided']
					/* translators: %s: invoice number */
					? sprintf( __( '%s (voided)', 'moksa-for-woocommerce' ), $invoice['number'] )
					: $invoice['number'],
			];
		}

		// 區塊結帳訂單的選項 WC 已經列過，不重複。
		$type = (string) $order->get_meta( Keys::INVOICE_TYPE );
		if ( '' === $type || '' !== (string) $order->get_meta( '_wc_other/moksafowo/invoice-type' ) ) {
			return $rows;
		}

		$type_labels = [
			'b2c_carrier' => __( 'Individual', 'moksa-for-woocommerce' ),
			'b2b'         => __( 'Company', 'moksa-for-woocommerce' ),
			'b2c_donate'  => __( 'Donation', 'moksa-for-woocommerce' ),
		];
		$rows[]      = [ __( 'Invoice type', 'moksa-for-woocommerce' ), $type_labels[ $type ] ?? $type ];

		if ( 'b2c_carrier' === $type ) {
			$carrier = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
			if ( '' !== $carrier ) {
				$rows[] = [ __( 'Invoice carrier', 'moksa-for-woocommerce' ), self::$carrier_labels[ $carrier ] ?? $carrier ];
			}
			$num = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );
			if ( '' !== $num && in_array( $carrier, [ 'mobile', 'cert' ], true ) ) {
				$rows[] = [ __( 'Carrier number', 'moksa-for-woocommerce' ), $num ];
			}
		} elseif ( 'b2b' === $type ) {
			foreach ( [
				Keys::INVOICE_BUYER_UBN  => __( 'Tax ID', 'moksa-for-woocommerce' ),
				Keys::INVOICE_BUYER_NAME => __( 'Company name', 'moksa-for-woocommerce' ),
			] as $key => $label ) {
				$value = (string) $order->get_meta( $key );
				if ( '' !== $value ) {
					$rows[] = [ $label, $value ];
				}
			}
		} elseif ( 'b2c_donate' === $type ) {
			$code = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );
			if ( '' !== $code ) {
				$rows[] = [ __( 'Donation code', 'moksa-for-woocommerce' ), $code ];
			}
		}
		return $rows;
	}
}
