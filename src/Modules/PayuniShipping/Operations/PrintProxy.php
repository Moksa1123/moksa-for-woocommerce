<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Operations;

use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;

use MoksaWeb\Mowc\Modules\Shared\Frontend\Interstitial;

defined( 'ABSPATH' ) || exit;

final class PrintProxy {

	private const ACTION_QUICK       = 'moksafowo_payuni_shipping_print_quick';
	private const NONCE_ACTION_QUICK = 'moksafowo_payuni_shipping_print_quick';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_QUICK, [ __CLASS__, 'handle_quick' ] );
		add_filter( 'woocommerce_admin_order_actions', [ __CLASS__, 'add_print_action' ], 25, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_action_assets' ] );
	}

	public static function add_print_action( array $actions, \WC_Order $order ): array {
		$method_id = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid = (string) $m->get_method_id();
			if ( PayuniShipping::is_payuni_shipping( $mid ) ) {
				$method_id = $mid;
				break;
			}
		}
		if ( '' === $method_id ) {
			return $actions;
		}
		$has_records = ! empty( CreateOrderUnified::get_records( $order ) )
			|| '' !== (string) $order->get_meta( OrderMeta::ShipTradeNo );
		if ( ! $has_records ) {
			return $actions;
		}

		$url                               = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_QUICK . '&order_id=' . $order->get_id() ),
			self::NONCE_ACTION_QUICK . '_' . $order->get_id()
		);
		$actions['moksafowo_payuni_print'] = [
			'url'    => $url,
			'name'   => __( '列印 PAYUNi 標籤', 'mo-ectools' ),
			'action' => 'moksafowo-payuni-print',
		];
		return $actions;
	}

	public static function enqueue_action_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
			return;
		}
		$css = <<<'CSS'
.wc-action-button.moksafowo-payuni-print{position:relative;}
.wc-action-button.moksafowo-payuni-print::before{
	content:"\f193" !important;
	font-family:dashicons !important;
	font-size:16px !important;
	line-height:1 !important;
	text-indent:0 !important;
	position:absolute !important;
	top:0 !important;left:0 !important;right:0 !important;bottom:0 !important;
	display:flex !important;
	align-items:center !important;
	justify-content:center !important;
	color:#dc2626 !important;
	background:none !important;
	margin:0 !important;
	padding:0 !important;
	width:auto !important;height:auto !important;
	mask:none !important;-webkit-mask:none !important;
}
.wc-action-button.moksafowo-payuni-print:hover{background:#f1f5f9;}
CSS;
		wp_register_style( 'moksafowo-payuni-print-actions', false, [ 'dashicons' ], MOKSAFOWO_VERSION );
		wp_enqueue_style( 'moksafowo-payuni-print-actions' );
		wp_add_inline_style( 'moksafowo-payuni-print-actions', $css );
	}

	public static function handle_quick(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( '權限不足。', 'mo-ectools' ), '', 403 );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		check_admin_referer( self::NONCE_ACTION_QUICK . '_' . $order_id );

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( '找不到訂單。', 'mo-ectools' ), '', 404 );
		}

		$ship_type = '';
		$records   = CreateOrderUnified::get_records( $order );
		if ( ! empty( $records ) ) {
			$ship_type = (string) ( $records[0]['ship_type'] ?? '' );
		} else {
			$ship_type = (string) $order->get_meta( OrderMeta::ShipType );
		}

		$forms = ( ShipType::SEVEN === $ship_type )
			? BatchPrint::cvs( [ $order_id ] )
			: BatchPrint::home( [ $order_id ] );

		if ( empty( $forms ) ) {
			wp_die( esc_html__( '此訂單尚未建立物流單。', 'mo-ectools' ), '', 400 );
		}

		$forms_html = '';
		foreach ( $forms as $idx => $spec ) {
			$forms_html .= '<form id="f' . (int) $idx . '" method="post" action="' . esc_url( $spec['api_url'] ) . '"' . ( $idx > 0 ? ' target="_blank"' : '' ) . '>';
			foreach ( (array) ( $spec['form_data'] ?? [] ) as $k => $v ) {
				$forms_html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
			$forms_html .= '</form>';
		}

		Interstitial::render(
			__( '列印 PAYUNi 標籤', 'mo-ectools' ),
			__( '正在列印 PAYUNi 物流標籤…', 'mo-ectools' ),
			[],
			$forms_html,
			'var forms=document.querySelectorAll("form[id^=f]");forms.forEach(function(f,i){setTimeout(function(){f.submit();},i*800);});'
		);
		exit;
	}
}
