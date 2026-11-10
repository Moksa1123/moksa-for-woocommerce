<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Admin;

use Moksafowo\Modules\Shared\Admin\LastError;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單列表「物流單號」欄：還沒建單的訂單直接有「建立物流單」按鈕，上次建單失敗的附上原因。
 */
final class ListCreateButton {

	private const AJAX_ACTION = 'moksafowo_shipping_list_create';
	private const CAPABILITY  = 'edit_shop_orders';
	private const STATUSES    = [ 'processing', 'on-hold' ];

	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_create' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	/** 沒有物流單號、而且可以建單時的欄位內容；不能建單回空字串。 */
	public static function render_cell( \WC_Order $order ): string {
		if ( ! self::can_create( $order ) ) {
			return '';
		}
		$html = sprintf(
			'<button type="button" class="button button-small moksafowo-shipping-list-create" data-order-id="%1$d">%2$s</button>',
			(int) $order->get_id(),
			esc_html__( 'Create shipment', 'moksa-for-woocommerce' )
		);
		$last = LastError::get( $order, Keys::SHIPPING_LAST_ERROR );
		if ( null !== $last ) {
			$html .= sprintf(
				'<small class="moksafowo-shipping-list-error" style="display:block;color:#b32d2e;white-space:normal;" title="%1$s">%2$s</small>',
				esc_attr( $last['message'] ),
				/* translators: %s: error message from the shipping carrier */
				esc_html( sprintf( __( 'Failed: %s', 'moksa-for-woocommerce' ), mb_strimwidth( $last['message'], 0, 40, '…' ) ) )
			);
		}
		return $html;
	}

	/**
	 * PAYUNi 7-11 建單後要先印標籤，寄件代碼才會回傳；這段期間列表沒有號碼可顯示，改放「列印標籤」。
	 * 按鈕沿用訂單頁那顆的 class 與 data，由 PAYUNi 後台腳本處理。
	 */
	public static function render_print_cell( \WC_Order $order ): string {
		if ( 'payuni' !== ShipmentCreator::provider_for( $order ) || '' === ShipmentCreator::shipment_ref( $order ) || ! current_user_can( self::CAPABILITY ) ) {
			return '';
		}
		return sprintf(
			'<button type="button" class="button button-small print-label" data-id="%1$d" data-service="%2$s" data-action="moksafowo_payuni_shipping_print_label">%3$s</button>',
			(int) $order->get_id(),
			esc_attr( (string) $order->get_meta( Keys::PAYUNI_SHIPPING_SHIP_TYPE ) ),
			esc_html__( 'Print label', 'moksa-for-woocommerce' )
		);
	}

	private static function can_create( \WC_Order $order ): bool {
		return in_array( $order->get_status(), self::STATUSES, true )
			&& '' === ShipmentCreator::shipment_ref( $order )
			&& '' !== ShipmentCreator::provider_for( $order )
			&& current_user_can( self::CAPABILITY );
	}

	public static function ajax_create(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}
		if ( '' !== ShipmentCreator::shipment_ref( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'This order already has a shipment. Refresh the page to see it.', 'moksa-for-woocommerce' ) ] );
		}
		if ( ! in_array( $order->get_status(), self::STATUSES, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Shipments can only be created for orders that are processing or on hold.', 'moksa-for-woocommerce' ) ] );
		}

		// 連點或兩個分頁同時按，只讓第一個真的去建單。
		$lock = 'moksafowo_ship_list_lock_' . $order->get_id();
		if ( get_transient( $lock ) ) {
			wp_send_json_error( [ 'message' => __( 'A shipment is already being created for this order. Please wait a moment.', 'moksa-for-woocommerce' ) ] );
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );
		$result = ShipmentCreator::create( $order );
		delete_transient( $lock );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( [ 'message' => (string) ( $result['message'] ?? __( 'Unknown error', 'moksa-for-woocommerce' ) ) ] );
		}
		$fresh = wc_get_order( $order->get_id() );
		$no    = $fresh instanceof \WC_Order ? BatchPrintAdminUI::find_label_no( $fresh ) : '';
		wp_send_json_success( [ 'number' => '' !== $no ? $no : __( 'Created — awaiting tracking number', 'moksa-for-woocommerce' ) ] );
	}

	public static function enqueue( string $hook ): void {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_orders = 'woocommerce_page_wc-orders' === $hook || ( $screen && 'edit-shop_order' === $screen->id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( ! $is_orders || in_array( $action, [ 'edit', 'new' ], true ) ) {
			return;
		}
		wp_register_script( 'moksafowo-shipping-list-create', false, [], MOKSAFOWO_VERSION, true );
		wp_enqueue_script( 'moksafowo-shipping-list-create' );
		wp_add_inline_script(
			'moksafowo-shipping-list-create',
			'window.moksafowoShippingListCreate=' . wp_json_encode(
				[
					'ajax'     => admin_url( 'admin-ajax.php' ),
					'action'   => self::AJAX_ACTION,
					'nonce'    => wp_create_nonce( self::AJAX_ACTION ),
					'creating' => __( 'Creating…', 'moksa-for-woocommerce' ),
					'network'  => __( 'The request failed. Please try again.', 'moksa-for-woocommerce' ),
				]
			) . ';' . self::inline_js()
		);
	}

	private static function inline_js(): string {
		return <<<'JS'
document.addEventListener('click',function(e){
	var b=e.target.closest&&e.target.closest('.moksafowo-shipping-list-create');
	if(!b){return;}
	e.preventDefault();e.stopPropagation();
	if(b.disabled){return;}
	var c=window.moksafowoShippingListCreate,cell=b.parentNode,label=b.textContent,fd=new FormData();
	fd.append('action',c.action);fd.append('nonce',c.nonce);fd.append('order_id',b.getAttribute('data-order-id'));
	b.disabled=true;b.textContent=c.creating;
	var fail=function(msg){
		b.disabled=false;b.textContent=label;
		var m=cell.querySelector('.moksafowo-shipping-list-error')||document.createElement('small');
		m.className='moksafowo-shipping-list-error';m.style.display='block';m.style.color='#b32d2e';m.style.whiteSpace='normal';
		m.textContent=msg;m.title=msg;cell.appendChild(m);
	};
	fetch(c.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){
		var d=r&&r.data||{};
		if(r&&r.success){var s=document.createElement('span');s.style.fontFamily='monospace';s.style.wordBreak='break-all';s.textContent=d.number||'';cell.textContent='';cell.appendChild(s);return;}
		fail(d.message||'');
	}).catch(function(){fail(c.network);});
});
JS;
	}
}
