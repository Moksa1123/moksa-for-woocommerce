<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單列表的「發票號碼」欄。任何一個發票模組啟用時才掛上；
 * 用標準欄位註冊，商家可從「顯示項目設定」自行隱藏。
 */
final class OrderListColumn {

	private const COLUMN = 'moksafowo_invoice_number';

	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'register_column' ], 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'register_column' ], 20 );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
	}

	public static function register_column( array $cols ): array {
		$label = __( 'Invoice number', 'moksa-for-woocommerce' );
		$new   = [];
		foreach ( $cols as $k => $v ) {
			if ( 'order_total' === $k ) {
				$new[ self::COLUMN ] = $label;
			}
			$new[ $k ] = $v;
		}
		if ( ! isset( $new[ self::COLUMN ] ) ) {
			$new[ self::COLUMN ] = $label;
		}
		return $new;
	}

	/**
	 * @param string        $column 欄位 ID。
	 * @param \WC_Order|int $order  HPOS 傳訂單物件，舊版文章列表傳 ID。
	 */
	public static function render_column( $column, $order ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order );
		}
		$inv = $order instanceof \WC_Order ? InvoiceNumber::of( $order ) : [
			'number' => '',
			'voided' => false,
		];
		if ( '' === $inv['number'] ) {
			echo '&ndash;';
			return;
		}
		if ( $inv['voided'] ) {
			printf(
				'<del>%1$s</del> <small>%2$s</small>',
				esc_html( $inv['number'] ),
				esc_html__( 'Voided', 'moksa-for-woocommerce' )
			);
			return;
		}
		echo esc_html( $inv['number'] );
	}
}
