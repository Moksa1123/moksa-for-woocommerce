<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Operations;

use Moksafowo\Modules\EcpayShipping\Api\Helper;
use Moksafowo\Modules\Shared\Frontend\Interstitial;
use Moksafowo\Modules\EcpayShipping\Module;

defined( 'ABSPATH' ) || exit;

final class PrintProxy {

	private const NONCE_ACTION       = 'moksafowo_ecpay_shipping_print_v2';
	private const ACTION             = 'moksafowo_ecpay_shipping_print_v2';
	private const ACTION_QUICK       = 'moksafowo_ecpay_shipping_print_quick';
	private const NONCE_ACTION_QUICK = 'moksafowo_ecpay_shipping_print_quick';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_' . self::ACTION_QUICK, [ __CLASS__, 'handle_quick' ] );
		add_filter( 'woocommerce_admin_order_actions', [ __CLASS__, 'add_print_actions' ], 20, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_action_assets' ] );
	}

	public static function add_print_actions( array $actions, \WC_Order $order ): array {
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
		$records = CreateOrder::get_records( $order );
		if ( empty( $records ) ) {
			return $actions;
		}

		// 任一 record 是 A6-capable subtype 就顯示 A6 按鈕；其他 subtype 自動 fallback A4
		$a6_subtypes      = [ 'UNIMARTC2C', 'UNIMART', 'UNIMARTFREEZE', 'POST' ];
		$any_a6_supported = false;
		foreach ( $records as $r ) {
			if ( in_array( (string) ( $r['subtype'] ?? '' ), $a6_subtypes, true ) ) {
				$any_a6_supported = true;
				break;
			}
		}
		$modes = [ '1' => 'a4' ];
		if ( $any_a6_supported ) {
			$modes['2'] = 'a6';
		}

		foreach ( $modes as $mode => $tone ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::ACTION_QUICK . '&order_id=' . $order->get_id() . '&mode=' . $mode ),
				self::NONCE_ACTION_QUICK . '_' . $order->get_id()
			);
			$actions[ 'moksafowo_ecpay_print_' . $tone ] = [
				'url'    => $url,
				'name'   => 'a4' === $tone ? __( '列印物流標籤 A4', 'moksa-for-woocommerce' ) : __( '列印物流標籤 A6', 'moksa-for-woocommerce' ),
				'action' => 'moksafowo-ecpay-print moksafowo-ecpay-print-' . $tone,
			];
		}
		return $actions;
	}

	public static function enqueue_action_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'edit-shop_order' ], true ) ) {
			return;
		}
		$css = <<<'CSS'
/* 對標 WC 原生 .wc-action-button-{action} 樣式，icon 走 absolute + 全填滿 + flex 置中 */
.wc-action-button.moksafowo-ecpay-print{position:relative;}
.wc-action-button.moksafowo-ecpay-print::before{
	content:"\f193" !important; /* dashicons-printer U+F193 */
	font-family:dashicons !important;
	font-size:16px !important;
	line-height:1 !important;
	text-indent:0 !important;
	position:absolute !important;
	top:0 !important;left:0 !important;right:0 !important;bottom:0 !important;
	display:flex !important;
	align-items:center !important;
	justify-content:center !important;
	background:none !important;
	margin:0 !important;
	padding:0 !important;
	width:auto !important;height:auto !important;
	mask:none !important;-webkit-mask:none !important;
}
.wc-action-button.moksafowo-ecpay-print-a4::before{color:#1d4ed8;}
.wc-action-button.moksafowo-ecpay-print-a6::before{color:#7c3aed;}
.wc-action-button.moksafowo-ecpay-print:hover{background:#f1f5f9;}
.wc-action-button.moksafowo-ecpay-print:focus-visible{outline:2px solid currentColor;outline-offset:1px;}
/* native title attribute 走瀏覽器內建 tooltip — JS enrich() 注入，位置自動不會被 column 切到 */
CSS;
		wp_register_style( 'moksafowo-ecpay-print-actions', false, [ 'dashicons' ], MOKSAFOWO_VERSION );
		wp_enqueue_style( 'moksafowo-ecpay-print-actions' );
		wp_add_inline_style( 'moksafowo-ecpay-print-actions', $css );

		$js = <<<'JS'
(function(){
	function enrich(){
		document.querySelectorAll(".moksafowo-ecpay-print-a4,.moksafowo-ecpay-print-a6").forEach(function(a){
			var aria=a.getAttribute("aria-label")||"";
			if(aria){a.setAttribute("title",aria);}
			a.setAttribute("target","_blank");
			a.setAttribute("rel","noopener");
		});
		/* 清理 WC HPOS list table 「運送至」column 的 maps.google 連結 — q 參數裡的尾端空 fields */
		document.querySelectorAll('a[href*="maps.google.com"]').forEach(function(a){
			try{
				var u=new URL(a.href);
				var q=u.searchParams.get("q")||"";
				var cleaned=q.replace(/(?:,\s*)+$/,"").replace(/(?:,\s*){2,}/g,", ");
				if(cleaned !== q && cleaned.length){
					u.searchParams.set("q",cleaned);
					a.href=u.toString();
				}
			}catch(e){}
		});
	}
	if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",enrich);}else{enrich();}
	/* React 可能會 re-render 把 title 拔掉，每 200ms 補一次 */
	setInterval(enrich,200);
})();
JS;
		wp_register_script( 'moksafowo-ecpay-print-actions', false, [], MOKSAFOWO_VERSION, true );
		wp_enqueue_script( 'moksafowo-ecpay-print-actions' );
		wp_add_inline_script( 'moksafowo-ecpay-print-actions', $js );
	}

	public static function handle_quick(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( '權限不足。', 'moksa-for-woocommerce' ), '', 403 );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$mode     = isset( $_GET['mode'] ) && '2' === sanitize_text_field( wp_unslash( $_GET['mode'] ) ) ? '2' : '1';
		check_admin_referer( self::NONCE_ACTION_QUICK . '_' . $order_id );

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( '找不到訂單。', 'moksa-for-woocommerce' ), '', 404 );
		}
		$records = CreateOrder::get_records( $order );
		if ( empty( $records ) ) {
			wp_die( esc_html__( '此訂單尚未建立物流單。', 'moksa-for-woocommerce' ), '', 400 );
		}

		// 多溫層：各 subtype 各一筆 API；同 subtype 多筆 ID comma-separated（一張 PDF）
		$buckets = [];
		foreach ( $records as $r ) {
			$id      = (string) ( $r['id'] ?? '' );
			$subtype = (string) ( $r['subtype'] ?? '' );
			if ( '' !== $id && '' !== $subtype ) {
				$buckets[ $subtype ][] = $id;
			}
		}
		if ( empty( $buckets ) ) {
			wp_die( esc_html__( '物流單資料不完整。', 'moksa-for-woocommerce' ), '', 400 );
		}

		// TCAT / FAMI / HILIFE / OK 不支援 A6；自動降 A4
		$a6_subtypes = [ 'UNIMARTC2C', 'UNIMART', 'UNIMARTFREEZE', 'POST' ];

		$nonce      = wp_create_nonce( self::NONCE_ACTION );
		$action_url = self::action_url();
		$bucket_idx = 0;

		$paragraphs = [];
		if ( count( $buckets ) > 1 ) {
			/* translators: 1: number of shipping subtypes, 2: number of print windows that will open */
			$paragraphs[] = sprintf( __( '此訂單含 %1$d 種物流通路（subtype），會分別開啟 %2$d 個列印視窗。', 'moksa-for-woocommerce' ), count( $buckets ), count( $buckets ) );
			$paragraphs[] = __( '若瀏覽器擋住跳出視窗，請允許彈出後重新點擊「列印物流標籤」。', 'moksa-for-woocommerce' );
		}

		$forms = '';
		foreach ( $buckets as $subtype => $ids ) {
			$bucket_mode = ( '2' === $mode && ! in_array( $subtype, $a6_subtypes, true ) ) ? '1' : $mode; // A4-only subtype 降 A4
			$forms      .= '<form id="f' . (int) $bucket_idx . '" method="post" action="' . esc_url( $action_url ) . '"' . ( $bucket_idx > 0 ? ' target="_blank"' : '' ) . '>'
				. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
				. '<input type="hidden" name="logistics_ids" value="' . esc_attr( implode( ',', $ids ) ) . '">'
				. '<input type="hidden" name="subtype" value="' . esc_attr( $subtype ) . '">'
				. '<input type="hidden" name="mode" value="' . esc_attr( $bucket_mode ) . '">'
				. '</form>';
			++$bucket_idx;
		}

		Interstitial::render(
			__( '列印物流標籤', 'moksa-for-woocommerce' ),
			/* translators: %d: total number of shipping labels across all subtypes */
			sprintf( __( '正在列印 %d 張物流標籤…', 'moksa-for-woocommerce' ), array_sum( array_map( 'count', $buckets ) ) ),
			$paragraphs,
			$forms,
			'var forms=document.querySelectorAll("form[id^=f]");forms.forEach(function(f,i){setTimeout(function(){f.submit();},i*800);});'
		);
		exit;
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}

	public static function action_url(): string {
		return admin_url( 'admin-post.php?action=' . self::ACTION );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( '權限不足。', 'moksa-for-woocommerce' ), '', 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		$ids_csv = isset( $_POST['logistics_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['logistics_ids'] ) ) : '';
		$subtype = isset( $_POST['subtype'] ) ? sanitize_text_field( wp_unslash( $_POST['subtype'] ) ) : '';
		$mode    = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '1';

		$ids = array_values( array_filter( array_map( 'trim', explode( ',', $ids_csv ) ) ) );
		if ( empty( $ids ) || '' === $subtype ) {
			wp_die( esc_html__( '缺少必要參數。', 'moksa-for-woocommerce' ), '', 400 );
		}

		$mer = Helper::merchant_id( $subtype );

		$data = [
			'MerchantID'       => $mer,
			'LogisticsID'      => $ids,
			'LogisticsSubType' => $subtype,
			'PrintMode'        => '2' === $mode ? 2 : 1, // 1=A4, 2=A6
		];
		$args = [
			'MerchantID' => $mer,
			'RqHeader'   => [
				'Timestamp' => time(),
				'Revision'  => '1.0.0',
			],
			'Data'       => wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		];
		// ECPay V2: urlencode → AES-128-CBC encrypt，用對應 subtype 的 hash key/iv
		$args['Data'] = self::ecpay_urlencode( (string) $args['Data'] );
		$args['Data'] = openssl_encrypt(
			$args['Data'],
			'aes-128-cbc',
			Helper::hash_key( $subtype ),
			0,
			Helper::hash_iv( $subtype )
		);

		Helper::log( 'V2 PrintTradeDocument request', [ 'args' => $args ] );

		$endpoint = Helper::is_sandbox()
			? 'https://logistics-stage.ecpay.com.tw/Express/v2/PrintTradeDocument'
			: 'https://logistics.ecpay.com.tw/Express/v2/PrintTradeDocument';

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 40,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $args ),
			]
		);

		if ( is_wp_error( $response ) ) {
			Helper::log( 'V2 print wp_error', [ 'msg' => $response->get_error_message() ] );
			wp_die( esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== (int) $code ) {
			Helper::log(
				'V2 print http error',
				[
					'code' => $code,
					'body' => substr( $body, 0, 500 ),
				]
			);
			wp_die( esc_html( sprintf( 'ECPay HTTP %d: %s', $code, substr( $body, 0, 200 ) ) ) );
		}

		// ECPay V2 可能回標籤 HTML 或轉址中繼頁；form/input 入 allowlist，submit 補回（CSP safe）
		$allow          = self::label_allowlist();
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

		// 宣告 UTF-8，避免 ECPay 中繼頁中文被瀏覽器猜錯編碼
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		$is_forward = (bool) preg_match( '/<form[^>]*name=["\']?PostForm/i', $body )
			|| ( preg_match( '/<form/i', $body ) && preg_match( '/<input/i', $body ) && ! preg_match( '/<table|<img/i', $body ) );

		if ( $is_forward ) {
			// 隱藏 ECPay 中繼頁（避免閃亂碼），由下方 script 程式化 submit
			echo '<!DOCTYPE html><meta charset="utf-8"><div style="font-family:-apple-system,\'Microsoft JhengHei\',sans-serif;text-align:center;margin-top:18vh;color:#1d2327;font-size:16px">' . esc_html__( '物流標籤產生中，請稍候…', 'moksa-for-woocommerce' ) . '</div>';
			echo '<div style="display:none">' . wp_kses( $body, $allow ) . '</div>';
		} else {
			echo wp_kses( $body, $allow );
		}
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

	public static function label_allowlist(): array {
		return Interstitial::label_allowlist();
	}

	private static function ecpay_urlencode( string $s ): string {
		// 對齊 ECPay SDK：保留 - _ . * ! ( )
		return str_replace(
			[ '%2D', '%2d', '%5F', '%5f', '%2E', '%2e', '%2A', '%2a', '%21', '%28', '%29' ],
			[ '-', '-', '_', '_', '.', '.', '*', '*', '!', '(', ')' ],
			urlencode( $s )
		);
	}
}
