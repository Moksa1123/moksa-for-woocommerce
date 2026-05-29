<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayShipping\Operations;

use MoksaWeb\Mowc\Modules\SmilepayShipping\Api\Helper;
use MoksaWeb\Mowc\Modules\SmilepayShipping\Module;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class PrintProxy {

	private const ACTION             = 'mo_smilepay_shipping_print';
	private const NONCE_ACTION       = 'mo_smilepay_shipping_print';
	private const ACTION_QUICK       = 'mo_smilepay_shipping_print_quick';
	private const NONCE_ACTION_QUICK = 'mo_smilepay_shipping_print_quick';

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
		add_action( 'admin_print_styles-woocommerce_page_wc-orders', [ __CLASS__, 'print_action_styles' ] );
		add_action( 'admin_print_styles-edit-shop_order', [ __CLASS__, 'print_action_styles' ] );
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

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_QUICK . '&order_id=' . $order->get_id() ),
			self::NONCE_ACTION_QUICK . '_' . $order->get_id()
		);
		$actions['mo_smilepay_print'] = [
			'url'    => $url,
			'name'   => __( '列印速買配標籤', 'mo-ectools' ),
			'action' => 'mo-smilepay-print',
		];
		return $actions;
	}

	public static function print_action_styles(): void {
		echo '<style>
			.wc-action-button.mo-smilepay-print{position:relative;}
			.wc-action-button.mo-smilepay-print::before{
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
			.wc-action-button.mo-smilepay-print:hover{background:#f1f5f9;}
		</style>';
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
		?>
		<!DOCTYPE html>
		<html lang="zh-Hant">
		<head>
			<meta charset="utf-8">
			<title>printing…</title>
			<style>body{font-family:-apple-system,sans-serif;padding:32px;text-align:center;color:#374151;}h2{margin:0 0 8px;}p{margin:4px 0;color:#6b7280;font-size:13px;}</style>
		</head>
		<body>
			<?php /* translators: %d: number of shipping labels being printed */ ?>
			<h2><?php echo esc_html( sprintf( __( '正在列印 %d 張速買配物流標籤…', 'mo-ectools' ), count( $smseids ) ) ); ?></h2>
			<?php if ( count( $smseids ) > 1 ) : ?>
				<?php /* translators: %d: number of packages and print windows that will open */ ?>
				<p><?php echo esc_html( sprintf( __( '此訂單拆 %d 包，會分別開啟列印視窗。', 'mo-ectools' ), count( $smseids ) ) ); ?></p>
				<p><?php esc_html_e( '若瀏覽器擋住跳出視窗，請允許彈出後重新點擊「列印速買配標籤」。', 'mo-ectools' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $smseids as $idx => $smseid ) :
				$form_data = self::relay_form_data( 'tcat', [ 'Smseid' => $smseid, 'print_format' => '1' ] );
			?>
				<form id="f<?php echo (int) $idx; ?>"
					method="post"
					action="<?php echo esc_url( $relay_url ); ?>"
					<?php if ( $idx > 0 ) : ?>target="_blank"<?php endif; ?>>
					<?php foreach ( $form_data as $k => $v ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
					<?php endforeach; ?>
				</form>
			<?php endforeach; ?>
			<script>
	const forms = document.querySelectorAll('form[id^="f"]');
				forms.forEach( ( f, i ) => setTimeout( () => f.submit(), i * 800 ) );
			</script>
		</body>
		</html>
		<?php
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

		$response = wp_remote_post( $endpoint, [
			'timeout' => 40,
			'body'    => $body,
		] );

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
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
		exit;
	}
}
