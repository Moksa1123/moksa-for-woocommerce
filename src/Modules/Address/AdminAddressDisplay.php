<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Address;

defined( 'ABSPATH' ) || exit;

/**
 * 後台訂單的地址顯示（台灣店家常用）：
 * - 訂單列表加「電話」欄，帳單與運送電話都列出來。
 * - 訂單編輯頁的台灣地址併成一行：姓名一行，郵遞區號＋縣市＋區＋地址一行。
 * 只影響後台畫面，信件、前台、存的資料都不變。
 */
final class AdminAddressDisplay {

	public const OPTION_PHONE    = 'moksafowo_admin_order_list_phone';
	public const OPTION_ONE_LINE = 'moksafowo_admin_order_one_line_address';

	private const COLUMN = 'moksafowo_phone';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		if ( 'yes' === get_option( self::OPTION_PHONE, 'no' ) ) {
			add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'add_column' ], 20 );
			add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_column' ], 20 );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
			add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
		}
		if ( 'yes' === get_option( self::OPTION_ONE_LINE, 'no' ) ) {
			add_filter( 'woocommerce_order_get_formatted_billing_address', [ __CLASS__, 'one_line' ], 20, 3 );
			add_filter( 'woocommerce_order_get_formatted_shipping_address', [ __CLASS__, 'one_line' ], 20, 3 );
		}
	}

	/**
	 * @param array<string,string> $columns 訂單列表欄位。
	 * @return array<string,string>
	 */
	public static function add_column( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'billing_address' === $key ) {
				$out[ self::COLUMN ] = __( 'Phone', 'moksa-for-woocommerce' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Phone', 'moksa-for-woocommerce' );
		}
		return $out;
	}

	/**
	 * @param string        $column 欄位 ID。
	 * @param \WC_Order|int $order  HPOS 傳訂單物件，舊版文章列表傳 ID。
	 */
	public static function render_column( $column, $order ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$billing  = (string) $order->get_billing_phone();
		$shipping = (string) $order->get_shipping_phone();
		if ( '' === $billing && '' === $shipping ) {
			echo '&ndash;';
			return;
		}
		if ( '' !== $billing ) {
			/* translators: %s: phone number */
			echo '<span style="display:block;">' . esc_html( sprintf( __( 'Billing: %s', 'moksa-for-woocommerce' ), $billing ) ) . '</span>';
		}
		if ( '' !== $shipping && $shipping !== $billing ) {
			/* translators: %s: phone number */
			echo '<span style="display:block;">' . esc_html( sprintf( __( 'Shipping: %s', 'moksa-for-woocommerce' ), $shipping ) ) . '</span>';
		}
	}

	/**
	 * 只在後台訂單編輯頁、台灣地址時併成兩行（姓名／地址）。
	 *
	 * @param string    $address 已格式化的地址（用 <br/> 分行）。
	 * @param array     $raw     地址欄位。
	 * @param \WC_Order $order   訂單。
	 */
	public static function one_line( $address, $raw, $order ): string {
		$address = (string) $address;
		if ( '' === $address || 'TW' !== (string) ( $raw['country'] ?? '' ) || ! self::on_order_edit_screen() ) {
			return $address;
		}
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '#<br\s*/?>#i', $address ) ) ) );
		if ( count( $lines ) <= 2 ) {
			return $address;
		}
		// 台灣格式的姓名、公司不一定在第一行：認出姓名與公司那幾行留著，其餘併成一行。
		$last   = trim( (string) ( $raw['last_name'] ?? '' ) );
		$first  = trim( (string) ( $raw['first_name'] ?? '' ) );
		$people = array_filter( [ trim( $last . ' ' . $first ), trim( $first . ' ' . $last ), $last . $first, trim( (string) ( $raw['company'] ?? '' ) ) ] );
		$keep   = [];
		$rest   = [];
		foreach ( $lines as $line ) {
			if ( in_array( wp_strip_all_tags( $line ), $people, true ) ) {
				$keep[] = $line;
			} else {
				$rest[] = $line;
			}
		}
		if ( count( $rest ) <= 1 ) {
			return $address;
		}
		$keep[] = implode( ' ', $rest );
		return implode( '<br/>', $keep );
	}

	private static function on_order_edit_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// 只在看頁面時改：存檔（POST）時可能順便寄顧客信，信裡的地址不能變。
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( null === $screen || 'get' !== $method || did_action( 'woocommerce_email_header' ) ) {
			return false;
		}
		// HPOS：admin.php?page=wc-orders&action=edit；舊版文章：post.php?post=…（base = post）。
		if ( 'woocommerce_page_wc-orders' === $screen->id ) {
			return 'edit' === sanitize_key( (string) \Moksafowo\Http\Input::get( 'action' ) );
		}
		return 'shop_order' === $screen->id && 'post' === $screen->base;
	}
}
