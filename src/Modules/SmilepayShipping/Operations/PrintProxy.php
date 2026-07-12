<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping\Operations;

use Moksafowo\Modules\SmilepayShipping\Api\Helper;
use Moksafowo\Modules\SmilepayShipping\Module;
use Moksafowo\Order\Meta\Keys;

use Moksafowo\Modules\Shared\Frontend\Interstitial;

defined( 'ABSPATH' ) || exit;

final class PrintProxy {

	private const ACTION             = 'moksafowo_smilepay_shipping_print';
	private const NONCE_ACTION       = 'moksafowo_smilepay_shipping_print';
	private const ACTION_QUICK       = 'moksafowo_smilepay_shipping_print_quick';
	private const NONCE_ACTION_QUICK = 'moksafowo_smilepay_shipping_print_quick';

	private const ENDPOINTS = [
		'tcat' => Helper::ENDPOINT_TCAT_PRINT,
		'b2c'  => Helper::ENDPOINT_B2C_PRINT,
		'c2c'  => Helper::ENDPOINT_C2C_API,
		'c2cu' => Helper::ENDPOINT_C2CU_API,
	];

	private const PASSTHROUGH = [ 'Smseid', 'smseid', 'Pay_subzg', 'types', 'print_format', 'PaperModel' ];

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_' . self::ACTION_QUICK, [ __CLASS__, 'handle_quick' ] );
		add_filter( 'woocommerce_admin_order_actions', [ __CLASS__, 'add_print_action' ], 25, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_action_assets' ] );
	}

	public static function relay_action(): string {
		return self::ACTION;
	}

	public static function relay_url(): string {
		return admin_url( 'admin-post.php' );
	}

	public static function relay_nonce(): string {
		return wp_create_nonce( self::NONCE_ACTION );
	}

	public static function relay_form_data( string $endpoint_key, array $passthrough ): array {
		$data = [
			'action'       => self::ACTION,
			'_wpnonce'     => self::relay_nonce(),
			'endpoint_key' => $endpoint_key,
		];
		foreach ( self::PASSTHROUGH as $f ) {
			if ( isset( $passthrough[ $f ] ) && '' !== (string) $passthrough[ $f ] ) {
				$data[ $f ] = (string) $passthrough[ $f ];
			}
		}
		return $data;
	}

	public static function add_print_action( array $actions, \WC_Order $order ): array {
		$method_id = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid = (string) $m->get_method_id();
			if ( isset( Module::method_map()[ $mid ] ) ) {
				$method_id = $mid;
				break;
			}
		}
		if ( '' === $method_id ) {
			return $actions;
		}
		$has_records = ! empty( CreateOrder::get_records( $order ) )
			|| '' !== (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_NO );
		if ( ! $has_records ) {
			return $actions;
		}

		$url                                 = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_QUICK . '&order_id=' . $order->get_id() ),
			self::NONCE_ACTION_QUICK . '_' . $order->get_id()
		);
		$actions['moksafowo_smilepay_print'] = [
			'url'    => $url,
			'name'   => __( '列印速買配標籤', 'mo-ectools' ),
			'action' => 'moksafowo-smilepay-print',
		];
		return $actions;
	}

	public static function enqueue_action_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
			return;
		}
		$css = <<<'CSS'
.wc-action-button.moksafowo-smilepay-print{position:relative;}
.wc-action-button.moksafowo-smilepay-print::before{
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
	color:#16a34a !important;
	background:none !important;
	margin:0 !important;
	padding:0 !important;
	width:auto !important;height:auto !important;
	mask:none !important;-webkit-mask:none !important;
}
.wc-action-button.moksafowo-smilepay-print:hover{background:#f1f5f9;}
CSS;
		wp_register_style( 'moksafowo-smilepay-print-actions', false, [ 'dashicons' ], MOKSAFOWO_VERSION );
		wp_enqueue_style( 'moksafowo-smilepay-print-actions' );
		wp_add_inline_style( 'moksafowo-smilepay-print-actions', $css );
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

		$smseids = [];
		foreach ( CreateOrder::get_records( $order ) as $r ) {
			$smseid = (string) ( $r['smseid'] ?? '' );
			if ( '' !== $smseid ) {
				$smseids[] = $smseid;
			}
		}
		if ( empty( $smseids ) ) {
			$legacy = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_NO );
			if ( '' !== $legacy ) {
				$smseids[] = $legacy;
			}
		}
		$smseids = array_values( array_unique( $smseids ) );
		if ( empty( $smseids ) ) {
			wp_die( esc_html__( '此訂單尚未建立物流單。', 'mo-ectools' ), '', 400 );
		}

		$relay_url = self::relay_url();

		$paragraphs = [];
		if ( count( $smseids ) > 1 ) {
			/* translators: %d: number of packages and print windows that will open */
			$paragraphs[] = sprintf( __( '此訂單拆 %d 包，會分別開啟列印視窗。', 'mo-ectools' ), count( $smseids ) );
			$paragraphs[] = __( '若瀏覽器擋住跳出視窗，請允許彈出後重新點擊「列印速買配標籤」。', 'mo-ectools' );
		}

		$forms_html = '';
		foreach ( $smseids as $idx => $smseid ) {
			$form_data   = self::relay_form_data(
				'tcat',
				[
					'Smseid'       => $smseid,
					'print_format' => '1',
				]
			);
			$forms_html .= '<form id="f' . (int) $idx . '" method="post" action="' . esc_url( $relay_url ) . '"' . ( $idx > 0 ? ' target="_blank"' : '' ) . '>';
			foreach ( $form_data as $k => $v ) {
				$forms_html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
			$forms_html .= '</form>';
		}

		Interstitial::render(
			__( '列印速買配標籤', 'mo-ectools' ),
			/* translators: %d: number of shipping labels being printed */
			sprintf( __( '正在列印 %d 張速買配物流標籤…', 'mo-ectools' ), count( $smseids ) ),
			$paragraphs,
			$forms_html,
			'var forms=document.querySelectorAll("form[id^=f]");forms.forEach(function(f,i){setTimeout(function(){f.submit();},i*800);});'
		);
		exit;
	}

	public static function handle(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( '權限不足。', 'mo-ectools' ), '', 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		$key = isset( $_POST['endpoint_key'] ) ? sanitize_key( wp_unslash( $_POST['endpoint_key'] ) ) : '';
		if ( ! isset( self::ENDPOINTS[ $key ] ) ) {
			wp_die( esc_html__( '不支援的列印通道。', 'mo-ectools' ), '', 400 );
		}
		$endpoint = self::ENDPOINTS[ $key ];

		$body = [];
		foreach ( self::PASSTHROUGH as $f ) {
			if ( isset( $_POST[ $f ] ) ) {
				$val = sanitize_text_field( wp_unslash( $_POST[ $f ] ) );
				if ( '' !== $val ) {
					$body[ $f ] = $val;
				}
			}
		}

		// 憑證一律 server 端注入，永不經過瀏覽器。
		$body['Dcvc']       = Helper::dcvc();
		$body['Verify_key'] = Helper::verify_key();
		if ( 'tcat' === $key ) {
			$body['Rvg2c'] = Helper::rvg2c();
			if ( empty( $body['print_format'] ) ) {
				$body['print_format'] = '1';
			}
		} elseif ( 'b2c' === $key ) {
			$body['Rvg2c'] = '1';
			if ( empty( $body['PaperModel'] ) ) {
				$body['PaperModel'] = '1';
			}
		}

		if ( empty( $body['Smseid'] ) && empty( $body['smseid'] ) ) {
			wp_die( esc_html__( '缺少物流單號。', 'mo-ectools' ), '', 400 );
		}

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 40,
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			Helper::log( 'print relay wp_error', [ 'msg' => $response->get_error_message() ] );
			wp_die( esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$html = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== (int) $code ) {
			Helper::log( 'print relay http error', [ 'code' => $code ] );
			wp_die( esc_html( sprintf( 'SmilePay HTTP %d', $code ) ) );
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		// 速買配 print server 回應有兩種:① 直接標籤 HTML ② 自動轉址中繼頁(隱藏表單 + submit
		// script)。wp_kses 會剔除 active content,故額外保留 form/input 讓轉址表單存活,被剔除的
		// 自動 submit 由我們補回:有轉址表單就送出(→ 真標籤頁自行列印),沒有就直接列印。
		// (與 EcpayShipping PrintProxy 一致 — 避免卡在中繼頁印不出標籤。)
		$allow          = Interstitial::label_allowlist();
		$allow['form']  = [
			'method'         => true,
			'id'             => true,
			'name'           => true,
			'action'         => true,
			'target'         => true,
			'enctype'        => true,
			'accept-charset' => true,
		];
		$allow['input'] = [
			'type'  => true,
			'name'  => true,
			'value' => true,
			'id'    => true,
		];
		echo wp_kses( $html, $allow );
		wp_print_inline_script_tag(
			'window.addEventListener("load",function(){'
			. 'var f=document.forms["PostForm"]||document.getElementById("PostForm");'
			. 'if(!f){var fs=document.querySelectorAll("form");f=fs.length?fs[fs.length-1]:null;}'
			. 'if(f&&f.querySelector("input")){try{f.submit();return;}catch(e){}}'
			. 'window.print();'
			. '});'
		);
		exit;
	}
}
