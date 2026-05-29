<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Operations;

use MoksaWeb\Mowc\Modules\EcpayShipping\Api\Helper;
use MoksaWeb\Mowc\Modules\EcpayShipping\Module;

defined( 'ABSPATH' ) || exit;

final class PrintProxy {

	private const NONCE_ACTION       = 'mo_ecpay_shipping_print_v2';
	private const ACTION             = 'mo_ecpay_shipping_print_v2';
	private const ACTION_QUICK       = 'mo_ecpay_shipping_print_quick';
	private const NONCE_ACTION_QUICK = 'mo_ecpay_shipping_print_quick';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'admin_post_' . self::ACTION_QUICK, [ __CLASS__, 'handle_quick' ] );
		add_filter( 'woocommerce_admin_order_actions', [ __CLASS__, 'add_print_actions' ], 20, 2 );
		add_action( 'admin_print_styles-woocommerce_page_wc-orders', [ __CLASS__, 'print_action_styles' ] );
		add_action( 'admin_print_styles-edit-shop_order', [ __CLASS__, 'print_action_styles' ] );
		// 純 admin 用，不開 nopriv（browser 已經登入）
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

		// A4 一律支援；A6 只有 7-11（UNIMART/UNIMARTC2C/UNIMARTFREEZE）+ 中華郵政（POST）。
		// 訂單只要有「任一筆」record 是 A6-capable subtype 就顯示 A6 按鈕；handle_quick
		// 會依 subtype 分桶處理，A4-only subtype 自動 fallback A4 不會印錯。
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
			// name = 完整無障礙描述（會渲染進 aria-label / title）
			// 可見圖示由 CSS ::before 用 dashicons-printer，文字本體由 text-indent 隱藏
			$actions[ 'mo_ecpay_print_' . $tone ] = [
				'url'    => $url,
				'name'   => 'a4' === $tone ? __( '列印物流標籤 A4', 'mo-ectools' ) : __( '列印物流標籤 A6', 'mo-ectools' ),
				'action' => 'mo-ecpay-print mo-ecpay-print-' . $tone,
			];
		}
		return $actions;
	}

	public static function print_action_styles(): void {
		echo '<style>
			/* 對標 WC 原生 .wc-action-button-{action} 樣式，icon 走 absolute + 全填滿 + flex 置中 */
			.wc-action-button.mo-ecpay-print{position:relative;}
			.wc-action-button.mo-ecpay-print::before{
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
			.wc-action-button.mo-ecpay-print-a4::before{color:#1d4ed8;}
			.wc-action-button.mo-ecpay-print-a6::before{color:#7c3aed;}
			.wc-action-button.mo-ecpay-print:hover{background:#f1f5f9;}
			.wc-action-button.mo-ecpay-print:focus-visible{outline:2px solid currentColor;outline-offset:1px;}
			/* native title attribute 走瀏覽器內建 tooltip — JS enrich() 注入，位置自動不會被 column 切到 */
		</style>
		<script>
		(function(){
			function enrich(){
				document.querySelectorAll(".mo-ecpay-print-a4,.mo-ecpay-print-a6").forEach(function(a){
					var aria=a.getAttribute("aria-label")||"";
					if(aria){a.setAttribute("title",aria);}
					a.setAttribute("target","_blank");
					a.setAttribute("rel","noopener");
				});
				// 清理 WC HPOS list table 「運送至」column 的 maps.google 連結 — q 參數裡的尾端空 fields
				// （WC 用 a1+a2+city+state+postcode+country join \", \"，CVS 訂單只有 a1 → 後面 5 個 \", \"）
				document.querySelectorAll("a[href*=\"maps.google.com\"]").forEach(function(a){
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
			// React 可能會 re-render 把 title 拔掉，每 200ms 補一次
			setInterval(enrich,200);
		})();
		</script>';
	}

	public static function handle_quick(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( '權限不足。', 'mo-ectools' ), '', 403 );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$mode     = isset( $_GET['mode'] ) && '2' === sanitize_text_field( wp_unslash( $_GET['mode'] ) ) ? '2' : '1';
		check_admin_referer( self::NONCE_ACTION_QUICK . '_' . $order_id );

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( '找不到訂單。', 'mo-ectools' ), '', 404 );
		}
		$records = CreateOrder::get_records( $order );
		if ( empty( $records ) ) {
			wp_die( esc_html__( '此訂單尚未建立物流單。', 'mo-ectools' ), '', 400 );
		}

		// 多溫層拆單訂單可能有多個 subtype（如 UNIMART + UNIMARTFREEZE）→ 各 subtype 一筆 print API call
		// 同 subtype 的多筆 LogisticsID 走同一筆 API 列印（comma-separated），1 張 PDF 含所有標籤
		$buckets = [];  // subtype => list<logistics_id>
		foreach ( $records as $r ) {
			$id      = (string) ( $r['id'] ?? '' );
			$subtype = (string) ( $r['subtype'] ?? '' );
			if ( '' !== $id && '' !== $subtype ) {
				$buckets[ $subtype ][] = $id;
			}
		}
		if ( empty( $buckets ) ) {
			wp_die( esc_html__( '物流單資料不完整。', 'mo-ectools' ), '', 400 );
		}

		// A6 只有 7-11（UNIMART/UNIMARTC2C/UNIMARTFREEZE）+ 郵政（POST）支援；
		// 不支援 A6 的 subtype（TCAT / FAMI / HILIFE / OK）自動降 A4 不報錯。
		$a6_subtypes = [ 'UNIMARTC2C', 'UNIMART', 'UNIMARTFREEZE', 'POST' ];

		$nonce      = wp_create_nonce( self::NONCE_ACTION );
		$action_url = self::action_url();
		$bucket_idx = 0;
		?>
		<!DOCTYPE html>
		<html lang="zh-Hant">
		<head>
			<meta charset="utf-8">
			<title>printing…</title>
			<style>body{font-family:-apple-system,sans-serif;padding:32px;text-align:center;color:#374151;}h2{margin:0 0 8px;}p{margin:4px 0;color:#6b7280;}</style>
		</head>
		<body>
			<?php /* translators: %d: total number of shipping labels across all subtypes */ ?>
			<h2><?php echo esc_html( sprintf( __( '正在列印 %d 張物流標籤…', 'mo-ectools' ), array_sum( array_map( 'count', $buckets ) ) ) ); ?></h2>
			<?php if ( count( $buckets ) > 1 ) : ?>
				<?php /* translators: 1: number of shipping subtypes, 2: number of print windows that will open */ ?>
				<p><?php echo esc_html( sprintf( __( '此訂單含 %1$d 種物流通路（subtype），會分別開啟 %2$d 個列印視窗。', 'mo-ectools' ), count( $buckets ), count( $buckets ) ) ); ?></p>
				<p style="font-size:12px;"><?php esc_html_e( '若瀏覽器擋住跳出視窗，請允許彈出後重新點擊「列印物流標籤」。', 'mo-ectools' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $buckets as $subtype => $ids ) :
				// 此 subtype 不支援 A6 → 自動降 A4
				$bucket_mode = ( '2' === $mode && ! in_array( $subtype, $a6_subtypes, true ) ) ? '1' : $mode;
			?>
				<form id="f<?php echo (int) $bucket_idx; ?>"
					method="post"
					action="<?php echo esc_url( $action_url ); ?>"
					<?php if ( $bucket_idx > 0 ) : ?>target="_blank"<?php endif; ?>>
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
					<input type="hidden" name="logistics_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
					<input type="hidden" name="subtype" value="<?php echo esc_attr( $subtype ); ?>">
					<input type="hidden" name="mode" value="<?php echo esc_attr( $bucket_mode ); ?>">
				</form>
				<?php ++$bucket_idx; ?>
			<?php endforeach; ?>
			<script>
				// 依序送出：第 1 張在原視窗、其餘 target=_blank 開新分頁，間隔 800ms 避免被瀏覽器擋
				const forms = document.querySelectorAll('form[id^="f"]');
				forms.forEach( ( f, i ) => setTimeout( () => f.submit(), i * 800 ) );
			</script>
		</body>
		</html>
		<?php
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
			wp_die( esc_html__( '權限不足。', 'mo-ectools' ), '', 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		$ids_csv = isset( $_POST['logistics_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['logistics_ids'] ) ) : '';
		$subtype = isset( $_POST['subtype'] ) ? sanitize_text_field( wp_unslash( $_POST['subtype'] ) ) : '';
		$mode    = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '1';

		$ids = array_values( array_filter( array_map( 'trim', explode( ',', $ids_csv ) ) ) );
		if ( empty( $ids ) || '' === $subtype ) {
			wp_die( esc_html__( '缺少必要參數。', 'mo-ectools' ), '', 400 );
		}

		$mer = Helper::merchant_id( $subtype );

		// 1) 內部資料
		$data = [
			'MerchantID'       => $mer,
			'LogisticsID'      => $ids,
			'LogisticsSubType' => $subtype,
			'PrintMode'        => '2' === $mode ? 2 : 1,  // 1=A4，2=A6
		];

		// 2) 包 envelope
		$args = [
			'MerchantID' => $mer,
			'RqHeader'   => [
				'Timestamp' => time(),
				'Revision'  => '1.0.0',
			],
			'Data'       => wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		];

		// 3) urlencode + AES-128-CBC encrypt Data（ECPay V2 規則）— 用對應 subtype 的 hash key/iv
		$args['Data'] = self::ecpay_urlencode( (string) $args['Data'] );
		$args['Data'] = openssl_encrypt(
			$args['Data'],
			'aes-128-cbc',
			Helper::hash_key( $subtype ),
			0,
			Helper::hash_iv( $subtype )
		);

		Helper::log( 'V2 PrintTradeDocument request', [ 'args' => $args ] );

		// 4) POST JSON
		$endpoint = Helper::is_sandbox()
			? 'https://logistics-stage.ecpay.com.tw/Express/v2/PrintTradeDocument'
			: 'https://logistics.ecpay.com.tw/Express/v2/PrintTradeDocument';

		$response = wp_remote_post( $endpoint, [
			'timeout' => 40,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $args ),
		] );

		if ( is_wp_error( $response ) ) {
			Helper::log( 'V2 print wp_error', [ 'msg' => $response->get_error_message() ] );
			wp_die( esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== (int) $code ) {
			Helper::log( 'V2 print http error', [ 'code' => $code, 'body' => substr( $body, 0, 500 ) ] );
			wp_die( esc_html( sprintf( 'ECPay HTTP %d: %s', $code, substr( $body, 0, 200 ) ) ) );
		}

		// 5) Echo label HTML 給 browser
		// ECPay V2 Print 直接回 HTML（不像其他 V2 API 回加密 JSON）
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $body;
		exit;
	}

	private static function ecpay_urlencode( string $s ): string {
		// 對齊 ECPay SDK 的 urlencode 邏輯：保留 - _ . * ! ( )
		return str_replace(
			[ '%2D', '%2d', '%5F', '%5f', '%2E', '%2e', '%2A', '%2a', '%21', '%28', '%29' ],
			[ '-', '-', '_', '_', '.', '.', '*', '*', '!', '(', ')' ],
			urlencode( $s )
		);
	}
}
