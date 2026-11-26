<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Payuni\Embed;

use Moksafowo\Compatibility\Subscriptions;
use Moksafowo\Compatibility\WpSubscription;
use Moksafowo\Modules\Payuni\Credentials;
use Moksafowo\Modules\Payuni\Gateways\Credit;
use Moksafowo\Modules\Payuni\PayuniPayment;
use Moksafowo\Modules\Shared\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * 統一金流免跳轉信用卡（UNi Embed，API 3.0）：結帳頁嵌入統一金流的卡號 iframe。
 *
 * 1. 結帳頁向本站拿 SDK token（token_get），token 存在 WC session，前端只拿來初始化 SDK。
 * 2. 顧客填卡號後 SDK 把卡號綁到 token；下單時伺服器用 session 裡的 token 打 merchant_trade 授權。
 * 3. token 用過一次就失效，每次授權前後都要換新的。
 *
 * 只在「分開顯示各付款方式」時套用在統一金流信用卡；合併顯示一律跳轉。
 */
final class Embed {

	public const OPTION = 'moksafowo_payuni_credit_embed';

	public const NONCE = 'moksafowo-payuni-embed';

	public const CHECK_HOOK = 'moksafowo_payuni_embed_check';

	private const SESSION_KEY = 'moksafowo_payuni_embed';

	/** 每個 session／IP 每 10 分鐘最多拿幾次 token：正常結帳一兩次，重試幾次也夠。 */
	private const TOKEN_LIMIT = 10;

	private const TOKEN_WINDOW = 600;

	public static function init(): void {
		add_action( 'wp_ajax_moksafowo_payuni_embed_test', [ self::class, 'ajax_test' ] );
		add_action( 'woocommerce_admin_field_moksafowo_payuni_embed_test', [ self::class, 'render_test_button' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'admin_inline_script' ], 20 );
		add_action( self::CHECK_HOOK, [ Trade::class, 'check' ], 10, 2 );
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'wp_ajax_moksafowo_payuni_embed_token', [ self::class, 'ajax_token' ] );
		add_action( 'wp_ajax_nopriv_moksafowo_payuni_embed_token', [ self::class, 'ajax_token' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_classic' ], 20 );
	}

	/** 商家選了嵌入，而且是分開顯示（合併顯示只有一個跳轉到統一金流的按鈕）。 */
	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION, 'no' ) && 'multi' === get_option( 'moksafowo_payuni_display_mode', 'multi' );
	}

	public static function sdk_url(): string {
		return Credentials::test_mode_enabled()
			? 'https://sandbox-vendor.payuni.com.tw/sdk/uni-payment.js'
			: 'https://vendor.payuni.com.tw/sdk/uni-payment.js';
	}

	public static function api_base(): string {
		return Credentials::test_mode_enabled() ? 'https://sandbox-api.payuni.com.tw/api/iframe/' : 'https://api.payuni.com.tw/api/iframe/';
	}

	/** IFrameDomain：只取網站本身的 https 網域，不收前端傳來的值。 */
	public static function iframe_domain(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return 'https://' . $host;
	}

	/**
	 * 向統一金流拿 SDK token。
	 *
	 * @param string $credit_token 要約定信用卡時的付款人識別碼；空字串表示一般交易。
	 * @return array{ok:bool,token:string,expires:int,status:string,message:string}
	 */
	public static function token_get( string $credit_token = '' ): array {
		$info = [
			'MerID'        => Credentials::merchant_id(),
			'Timestamp'    => time(),
			'IFrameDomain' => self::iframe_domain(),
		];
		if ( '' !== $credit_token ) {
			$info['CreditToken']     = $credit_token;
			$info['UseTokenType']    = 3;
			$info['CreditTokenType'] = 2;
		}
		$result = self::post( 'token_get', $info, '3.0' );
		$data   = $result['data'];
		$token  = (string) ( $data['Token'] ?? '' );
		$ok     = $result['verified'] && 'SUCCESS' === (string) ( $data['Status'] ?? '' ) && preg_match( '/^[A-Za-z0-9]{16,128}$/', $token );
		$exp    = strtotime( (string) ( $data['TokenExpired'] ?? '' ) . ' Asia/Taipei' );
		return [
			'ok'      => (bool) $ok,
			'token'   => $ok ? $token : '',
			// 統一金流給的到期時間解不出來時，照文件抓 10 分鐘。
			'expires' => $exp ? (int) $exp : time() + 600,
			'status'  => (string) ( $data['Status'] ?? $result['status'] ),
			'message' => (string) ( $data['Message'] ?? '' ),
		];
	}

	/**
	 * 打統一金流 iframe API：先驗 HashInfo 再解密，解出來的內容逐層 sanitize。
	 *
	 * @param string              $path    token_get／merchant_trade。
	 * @param array<string,mixed> $info    要加密的參數。
	 * @param string              $version API 版本。
	 * @return array{transport:bool,verified:bool,status:string,data:array<string,mixed>}
	 */
	public static function post( string $path, array $info, string $version ): array {
		$encrypted = PayuniPayment::encrypt( $info );
		$response  = wp_remote_post(
			self::api_base() . $path,
			[
				'httpversion' => '1.1',
				// 授權要等銀行，官方 60 秒等不到才回 UNKNOWN。
				'timeout'     => 'merchant_trade' === $path ? 70 : 20,
				'headers'     => [ 'User-Agent' => 'payuni' ],
				'body'        => [
					'MerID'       => (string) $info['MerID'],
					'Version'     => $version,
					'EncryptInfo' => $encrypted,
					'HashInfo'    => PayuniPayment::hash_info( $encrypted ),
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			PayuniPayment::log( 'embed ' . $path . ' error: ' . $response->get_error_message() );
			return [
				'transport' => false,
				'verified'  => false,
				'status'    => '',
				'data'      => [],
			];
		}
		$body     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body     = is_array( $body ) ? $body : [];
		$data     = [];
		$verified = false;
		if ( is_string( $body['EncryptInfo'] ?? null ) && is_string( $body['HashInfo'] ?? null )
			&& hash_equals( PayuniPayment::hash_info( $body['EncryptInfo'] ), strtoupper( $body['HashInfo'] ) ) ) {
			$data     = (array) PayuniPayment::decrypt( $body['EncryptInfo'] );
			$data     = map_deep( $data, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
			$verified = [] !== $data;
		}
		PayuniPayment::log( 'embed ' . $path . ' response: ' . wc_print_r( \Moksafowo\Modules\Payuni\Api\PaymentRequest::redact_for_log( $data ), true ) );
		return [
			'transport' => true,
			'verified'  => $verified,
			'status'    => sanitize_text_field( (string) ( $body['Status'] ?? '' ) ),
			'data'      => $data,
		];
	}

	/**
	 * 結帳頁拿 token。訪客也要能用，所以只回 token，並用 nonce＋節流擋濫用。
	 * 同一個 session 的 token 還沒用過、離到期還有 2 分鐘以上、約定與否一樣，就回同一個。
	 */
	public static function ajax_token(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE ) ) {
			wp_send_json_error( [ 'message' => __( 'The security check failed. Please refresh the page and try again.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id  = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$renew     = false;
		$order     = null;
		if ( $order_id > 0 ) {
			// 訂單付款頁（重新付款）：要對得上訂單金鑰才用這張訂單判斷要不要約定。
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
				wp_send_json_error( [ 'message' => __( 'That order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
			}
			$renew = Credit::renews_subscription( $order );
		} else {
			// 結帳頁一定有購物車；空購物車的請求不是來結帳的。
			if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
				wp_send_json_error( [ 'message' => __( 'Your session has expired. Please refresh the page.', 'moksa-for-woocommerce' ) ], 400 );
			}
			$renew = self::cart_renews();
		}

		$session = self::session();
		if ( ! $session ) {
			wp_send_json_error( [ 'message' => __( 'Your session has expired. Please refresh the page.', 'moksa-for-woocommerce' ) ], 400 );
		}
		$current = self::current( $session );
		if ( null !== $current && $current['agree'] === $renew && $current['expires'] - time() > 120 ) {
			wp_send_json_success( self::client_payload( $current ) );
		}

		if ( ! self::take_slot() ) {
			wp_send_json_error( [ 'message' => __( 'Too many attempts. Please wait a few minutes and try again.', 'moksa-for-woocommerce' ) ], 429 );
		}

		$credit_token = $renew ? self::credit_token( $order ) : '';
		$result       = self::token_get( $credit_token );
		if ( ! $result['ok'] ) {
			PayuniPayment::warn( 'embed token_get failed: ' . $result['status'] . ' ' . $result['message'] );
			wp_send_json_error(
				[
					'message'  => __( 'The card form could not be loaded. You can still pay on the PAYUNi payment page.', 'moksa-for-woocommerce' ),
					'fallback' => true,
				],
				502
			);
		}
		$entry = [
			'token'        => $result['token'],
			'expires'      => $result['expires'],
			'agree'        => $renew,
			'credit_token' => $credit_token,
		];
		$session->set( self::SESSION_KEY, $entry );
		wp_send_json_success( self::client_payload( $entry ) );
	}

	/**
	 * @param array{token:string,expires:int,agree:bool,credit_token:string} $entry session 裡的 token。
	 * @return array<string,mixed>
	 */
	private static function client_payload( array $entry ): array {
		return [
			'token'   => $entry['token'],
			'expires' => $entry['expires'],
			'agree'   => $entry['agree'],
		];
	}

	/** 購物車裡有要自動續扣的訂閱（WooCommerce Subscriptions 或 WPSubscription）。 */
	public static function cart_renews(): bool {
		if ( 'yes' !== get_option( 'moksafowo_payuni_payment_subscriptions_enabled', 'no' ) ) {
			return false;
		}
		if ( Subscriptions::active() && class_exists( 'WC_Subscriptions_Cart' ) && \WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return true;
		}
		// 續訂單、重新訂閱在購物車付款時，訂單同樣會帶訂閱。
		if ( Subscriptions::active() && ( ( function_exists( 'wcs_cart_contains_renewal' ) && wcs_cart_contains_renewal() ) || ( function_exists( 'wcs_cart_contains_resubscribe' ) && wcs_cart_contains_resubscribe() ) ) ) {
			return true;
		}
		return Credit::wps_enabled() && [] !== WpSubscription::cart_recurrings();
	}

	/**
	 * 約定信用卡的付款人識別碼：會員用固定的隨機碼（跟跳轉流程同一個），訪客每次一組。
	 * 續扣只用回傳的 CreditHash，識別碼只是統一金流那邊的綁定對象。
	 */
	private static function credit_token( ?\WC_Order $order ): string {
		if ( $order instanceof \WC_Order ) {
			$id = Subscriptions::member_id( $order );
			if ( '' !== $id ) {
				return $id;
			}
		}
		$id = Subscriptions::member_id_for_user( get_current_user_id() );
		return '' !== $id ? $id : 'mowp' . bin2hex( random_bytes( 12 ) );
	}

	/** WC session；訪客還沒有 session cookie 時建立，才能把 token 留到下單那一刻。 */
	private static function session(): ?\WC_Session {
		if ( ! function_exists( 'WC' ) || ! WC()->session instanceof \WC_Session ) {
			return null;
		}
		if ( method_exists( WC()->session, 'has_session' ) && ! WC()->session->has_session() && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
			WC()->session->set_customer_session_cookie( true );
		}
		return WC()->session;
	}

	/**
	 * session 裡還能用的 token。
	 *
	 * @return array{token:string,expires:int,agree:bool,credit_token:string}|null
	 */
	public static function current( ?\WC_Session $session = null ): ?array {
		$session = $session ?? ( function_exists( 'WC' ) && WC()->session instanceof \WC_Session ? WC()->session : null );
		if ( ! $session ) {
			return null;
		}
		$entry = $session->get( self::SESSION_KEY );
		if ( ! is_array( $entry ) || ! is_string( $entry['token'] ?? null ) || '' === $entry['token'] || (int) ( $entry['expires'] ?? 0 ) <= time() ) {
			return null;
		}
		return [
			'token'        => $entry['token'],
			'expires'      => (int) $entry['expires'],
			'agree'        => (bool) ( $entry['agree'] ?? false ),
			'credit_token' => (string) ( $entry['credit_token'] ?? '' ),
		];
	}

	/** token 一送去授權就作廢：成功、失敗都一樣，統一金流不讓同一個 token 再用。 */
	public static function forget(): void {
		if ( function_exists( 'WC' ) && WC()->session instanceof \WC_Session ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * 節流：session、IP（IPv6 以 /64 為一組）、全站各算一份，先佔名額；拿不到鎖時當作已達上限。
	 * 站台在 CDN 後面時很多顧客會共用 IP，所以 IP 的上限比 session 寬，再用全站上限擋大量請求。
	 */
	private static function take_slot(): bool {
		$keys = [];
		if ( function_exists( 'WC' ) && WC()->session instanceof \WC_Session ) {
			$keys[ 's' . md5( (string) WC()->session->get_customer_id() ) ] = self::TOKEN_LIMIT;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : false;
		if ( $ip ) {
			$packed                      = inet_pton( (string) $ip );
			$group                       = ( false !== $packed && 16 === strlen( $packed ) ) ? substr( $packed, 0, 8 ) : (string) $ip;
			$keys[ 'i' . md5( $group ) ] = self::TOKEN_LIMIT * 5;
		}
		$keys['all'] = self::TOKEN_LIMIT * 50;
		foreach ( $keys as $key => $limit ) {
			$name = 'moksafowo_pyembed_rl_' . $key;
			if ( ! Lock::acquire_wait( $name . '_lock', 10, 20, 25 ) ) {
				return false;
			}
			$count = (int) get_transient( $name );
			$ok    = $count < $limit;
			if ( $ok ) {
				set_transient( $name, $count + 1, self::TOKEN_WINDOW );
			}
			Lock::release( $name . '_lock' );
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/** 傳統結帳與訂單付款頁。區塊結帳由 Blocks\PayuniBlocksMethod 帶自己的腳本。 */
	public static function enqueue_classic(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) && ! is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		self::register_sdk();
		$path = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Payuni/assets/js/embed-classic.js';
		wp_enqueue_script(
			'moksafowo-payuni-embed',
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/Payuni/assets/js/embed-classic.js',
			[ 'jquery', 'moksafowo-payuni-sdk' ],
			file_exists( $path ) ? MOKSAFOWO_VERSION . '.' . filemtime( $path ) : MOKSAFOWO_VERSION,
			true
		);
		$config = self::client_config();
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			global $wp;
			$order_id = absint( $wp->query_vars['order-pay'] ?? 0 );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order instanceof \WC_Order ) {
				$config['orderId']  = $order_id;
				$config['orderKey'] = $order->get_order_key();
			}
		}
		wp_localize_script( 'moksafowo-payuni-embed', 'moksafowoPayuniEmbed', $config );
		$css = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Payuni/assets/css/embed.css';
		wp_enqueue_style(
			'moksafowo-payuni-embed',
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/Payuni/assets/css/embed.css',
			[],
			file_exists( $css ) ? MOKSAFOWO_VERSION . '.' . filemtime( $css ) : MOKSAFOWO_VERSION
		);
	}

	public static function register_sdk(): string {
		$handle = 'moksafowo-payuni-sdk';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			// 統一金流規定只能從官方網址載入 SDK，不能打包進外掛。
			wp_register_script( $handle, self::sdk_url(), [], MOKSAFOWO_VERSION, true );
		}
		return $handle;
	}

	/** @return array<string,mixed> 前端設定（傳統、區塊共用）。 */
	public static function client_config(): array {
		return [
			'gatewayId' => Credit::GATEWAY_ID,
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE ),
			'env'       => Credentials::test_mode_enabled() ? 'S' : 'P',
			'i18n'      => [
				'cardNumber' => __( 'Card number', 'moksa-for-woocommerce' ),
				'expiry'     => __( 'Expiry (MM / YY)', 'moksa-for-woocommerce' ),
				'cvc'        => __( 'CVC', 'moksa-for-woocommerce' ),
				'loading'    => __( 'Loading the card form…', 'moksa-for-woocommerce' ),
				'incomplete' => __( 'Please fill in all the card details.', 'moksa-for-woocommerce' ),
				'failed'     => __( 'The card form could not be loaded. You can still pay on the PAYUNi payment page.', 'moksa-for-woocommerce' ),
				'fallback'   => __( 'Pay on the PAYUNi payment page instead', 'moksa-for-woocommerce' ),
				'reenter'    => __( 'Please enter your card details again.', 'moksa-for-woocommerce' ),
				'bindFailed' => __( 'The card details could not be sent to PAYUNi. Please check them and try again.', 'moksa-for-woocommerce' ),
			],
		];
	}

	/** 設定頁的「測試連線」：只拿一次 token，不建交易。 */
	public static function render_test_button( array $value ): void {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( (string) ( $value['title'] ?? '' ) ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="moksafowo-payuni-embed-test" data-nonce="<?php echo esc_attr( wp_create_nonce( 'moksafowo-payuni-embed-test' ) ); ?>"><?php esc_html_e( 'Test connection', 'moksa-for-woocommerce' ); ?></button>
				<p id="moksafowo-payuni-embed-test-result" style="margin:8px 0 0;"></p>
				<p class="description"><?php echo esc_html( (string) ( $value['desc'] ?? '' ) ); ?></p>
			</td>
		</tr>
		<?php
	}

	/** 「測試連線」按鈕的腳本，掛在統一金流後台腳本後面（只在設定頁與訂單頁載入）。 */
	public static function admin_inline_script(): void {
		if ( ! wp_script_is( 'moksafowo-payuni-admin', 'enqueued' ) ) {
			return;
		}
		$error = wp_json_encode( __( 'Connection error. Please try again later.', 'moksa-for-woocommerce' ) );
		$ajax  = wp_json_encode( admin_url( 'admin-ajax.php' ) );
		wp_add_inline_script(
			'moksafowo-payuni-admin',
			'(function(){var b=document.getElementById("moksafowo-payuni-embed-test"),o=document.getElementById("moksafowo-payuni-embed-test-result");if(!b){return;}'
			. 'b.addEventListener("click",function(){b.disabled=true;o.textContent="…";'
			. 'fetch(' . $ajax . ',{method:"POST",credentials:"same-origin",body:new URLSearchParams({action:"moksafowo_payuni_embed_test",nonce:b.getAttribute("data-nonce")})})'
			. '.then(function(r){return r.json();}).then(function(r){o.textContent=(r&&r.data&&r.data.message)||"";})'
			. '.catch(function(){o.textContent=' . $error . ';}).finally(function(){b.disabled=false;});});})();'
		);
	}

	public static function ajax_test(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'moksafowo-payuni-embed-test' ) ) {
			wp_send_json_error( [ 'message' => __( 'The security check failed. Please refresh the page and try again.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$plain = self::token_get();
		if ( ! $plain['ok'] ) {
			wp_send_json_error( [ 'message' => self::describe( $plain['status'], $plain['message'] ) ] );
		}
		$message = __( 'Connected. The embedded card form is enabled for this store.', 'moksa-for-woocommerce' );
		if ( 'yes' === get_option( 'moksafowo_payuni_payment_subscriptions_enabled', 'no' ) ) {
			$agree    = self::token_get( 'mowptest' . bin2hex( random_bytes( 6 ) ) );
			$message .= ' ' . ( $agree['ok']
				? __( 'Saving cards for subscriptions is enabled too.', 'moksa-for-woocommerce' )
				: self::describe( $agree['status'], $agree['message'] ) );
		}
		wp_send_json_success( [ 'message' => $message ] );
	}

	/** 開通相關的錯誤碼換成商家看得懂、知道要做什麼的說明。 */
	public static function describe( string $code, string $message ): string {
		$map = [
			'TOKEN03003' => __( 'PAYUNi has not enabled the embedded card form for this store yet. Apply for it in the PAYUNi dashboard.', 'moksa-for-woocommerce' ),
			'TOKEN03004' => __( 'No allowed domain is set for the embedded card form. Add your site address in the PAYUNi dashboard.', 'moksa-for-woocommerce' ),
			'TOKEN03005' => __( 'No allowed server IP is set. Add your server IP in the PAYUNi dashboard.', 'moksa-for-woocommerce' ),
			'TOKEN03006' => __( 'This server\'s IP is not on the allowed list. Add it in the PAYUNi dashboard.', 'moksa-for-woocommerce' ),
			'TOKEN03008' => __( 'PAYUNi has not enabled saved cards (card agreements) for this store, so subscriptions cannot be paid with the embedded form yet.', 'moksa-for-woocommerce' ),
		];
		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ] . ' (' . $code . ')';
		}
		if ( '' === $code ) {
			return __( 'Could not get a reply from PAYUNi.', 'moksa-for-woocommerce' );
		}
		/* translators: 1: PAYUNi message, 2: code */
		return sprintf( __( 'PAYUNi replied: %1$s (%2$s)', 'moksa-for-woocommerce' ), $message, $code );
	}
}
