<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單列表的「發票號碼」欄。任何一個發票模組啟用時才掛上；
 * 用標準欄位註冊，商家可從「顯示項目設定」自行隱藏。
 * 開立時機設成「手動」的發票服務，已付款、還沒開過的訂單在這欄直接有「開立發票」按鈕。
 */
final class OrderListColumn {

	private const COLUMN = 'moksafowo_invoice_number';

	private const AJAX_ACTION = 'moksafowo_invoice_list_issue';

	private static bool $booted = false;

	/** @var array<string,array{when_option:string,issue:callable}> */
	private static array $issuers = [];

	/**
	 * 發票模組在 admin 開機時登記自己；只有開立時機選「手動」的才會出現按鈕。
	 */
	public static function register_manual_issuer( string $provider, string $when_option, callable $issue ): void {
		self::$issuers[ $provider ] = [
			'when_option' => $when_option,
			'issue'       => $issue,
		];
	}

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'register_column' ], 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'register_column' ], 20 );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_column' ], 10, 2 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_issue' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	private static function manual_issuer(): string {
		foreach ( self::$issuers as $provider => $issuer ) {
			if ( 'manual' === get_option( $issuer['when_option'], 'paid' ) ) {
				return $provider;
			}
		}
		return '';
	}

	public static function enqueue( string $hook ): void {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_orders = 'woocommerce_page_wc-orders' === $hook || ( $screen && 'edit-shop_order' === $screen->id );
		if ( ! $is_orders || '' === self::manual_issuer() ) {
			return;
		}
		wp_register_script( 'moksafowo-invoice-list-issue', false, [], MOKSAFOWO_VERSION, true );
		wp_enqueue_script( 'moksafowo-invoice-list-issue' );
		wp_add_inline_script(
			'moksafowo-invoice-list-issue',
			'window.moksafowoInvoiceListIssue=' . wp_json_encode(
				[
					'ajax'    => admin_url( 'admin-ajax.php' ),
					'action'  => self::AJAX_ACTION,
					'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
					'issuing' => __( 'Issuing…', 'moksa-for-woocommerce' ),
				]
			) . ';' . self::inline_js()
		);
	}

	private static function inline_js(): string {
		return <<<'JS'
document.addEventListener('click',function(e){
	var b=e.target.closest&&e.target.closest('.moksafowo-invoice-list-issue');
	if(!b){return;}
	e.preventDefault();e.stopPropagation();
	if(b.disabled){return;}
	var c=window.moksafowoInvoiceListIssue,cell=b.parentNode,label=b.textContent,fd=new FormData();
	fd.append('action',c.action);fd.append('nonce',c.nonce);fd.append('order_id',b.getAttribute('data-order-id'));
	b.disabled=true;b.textContent=c.issuing;
	fetch(c.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){
		var d=r&&r.data||{};
		if(r&&r.success&&d.number){cell.textContent=d.number;return;}
		b.disabled=false;b.textContent=label;
		var m=cell.querySelector('.moksafowo-invoice-list-error')||document.createElement('small');
		m.className='moksafowo-invoice-list-error';m.style.display='block';m.style.color='#b32d2e';
		m.textContent=d.message||'';cell.appendChild(m);
	}).catch(function(){b.disabled=false;b.textContent=label;});
});
JS;
	}

	public static function ajax_issue(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}
		$provider = self::manual_issuer();
		if ( '' === $provider || '' !== InvoiceNumber::of( $order )['number'] || ! $order->is_paid() ) {
			wp_send_json_error( [ 'message' => __( 'Issuing failed', 'moksa-for-woocommerce' ) ] );
		}
		$result = (array) call_user_func( self::$issuers[ $provider ]['issue'], $order );
		$fresh  = wc_get_order( $order->get_id() );
		$number = $fresh instanceof \WC_Order ? InvoiceNumber::of( $fresh )['number'] : '';
		if ( ! empty( $result['ok'] ) && '' !== $number ) {
			wp_send_json_success( [ 'number' => $number ] );
		}
		wp_send_json_error( [ 'message' => (string) ( $result['message'] ?? __( 'Issuing failed', 'moksa-for-woocommerce' ) ) ] );
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
			if ( $order instanceof \WC_Order && $order->is_paid() && '' !== self::manual_issuer() ) {
				printf(
					'<button type="button" class="button button-small moksafowo-invoice-list-issue" data-order-id="%1$d">%2$s</button>',
					(int) $order->get_id(),
					esc_html__( 'Issue invoice', 'moksa-for-woocommerce' )
				);
				return;
			}
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
