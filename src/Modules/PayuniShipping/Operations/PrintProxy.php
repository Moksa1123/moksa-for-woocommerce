<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Operations;

use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;

defined( 'ABSPATH' ) || exit;

final class PrintProxy {

	private const ACTION_QUICK       = 'mo_payuni_shipping_print_quick';
	private const NONCE_ACTION_QUICK = 'mo_payuni_shipping_print_quick';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_QUICK, [ __CLASS__, 'handle_quick' ] );
		add_filter( 'woocommerce_admin_order_actions', [ __CLASS__, 'add_print_action' ], 25, 2 );
		add_action( 'admin_print_styles-woocommerce_page_wc-orders', [ __CLASS__, 'print_action_styles' ] );
		add_action( 'admin_print_styles-edit-shop_order', [ __CLASS__, 'print_action_styles' ] );
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
		// unified records list 或 legacy single ShipTradeNo 任一存在即可
		$has_records = ! empty( CreateOrderUnified::get_records( $order ) )
			|| '' !== (string) $order->get_meta( OrderMeta::ShipTradeNo );
		if ( ! $has_records ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_QUICK . '&order_id=' . $order->get_id() ),
			self::NONCE_ACTION_QUICK . '_' . $order->get_id()
		);
		$actions['mo_payuni_print'] = [
			'url'    => $url,
			'name'   => __( '列印 PAYUNi 標籤', 'mo-ectools' ),
			'action' => 'mo-payuni-print',
		];
		return $actions;
	}

	public static function print_action_styles(): void {
		echo '<style>
			.wc-action-button.mo-payuni-print{position:relative;}
			.wc-action-button.mo-payuni-print::before{
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
			.wc-action-button.mo-payuni-print:hover{background:#f1f5f9;}
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

		// 偵測 ShipType（從 records 第一筆或 legacy meta 推）
		$ship_type = '';
		$records   = CreateOrderUnified::get_records( $order );
		if ( ! empty( $records ) ) {
			$ship_type = (string) ( $records[0]['ship_type'] ?? '' );
		} else {
			$ship_type = (string) $order->get_meta( OrderMeta::ShipType );
		}

		// 直接 reuse BatchPrint::cvs/home — 已支援 records list 多筆
		$forms = ( ShipType::SEVEN === $ship_type )
			? BatchPrint::cvs( [ $order_id ] )
			: BatchPrint::home( [ $order_id ] );

		if ( empty( $forms ) ) {
			wp_die( esc_html__( '此訂單尚未建立物流單。', 'mo-ectools' ), '', 400 );
		}

		?>
		<!DOCTYPE html>
		<html lang="zh-Hant">
		<head>
			<meta charset="utf-8">
			<title>printing…</title>
			<style>body{font-family:-apple-system,sans-serif;padding:32px;text-align:center;color:#374151;}h2{margin:0 0 8px;}p{margin:4px 0;color:#6b7280;font-size:13px;}</style>
		</head>
		<body>
			<h2><?php esc_html_e( '正在列印 PAYUNi 物流標籤…', 'mo-ectools' ); ?></h2>
			<?php foreach ( $forms as $idx => $spec ) : ?>
				<form id="f<?php echo (int) $idx; ?>"
					method="post"
					action="<?php echo esc_url( $spec['api_url'] ); ?>"
					<?php if ( $idx > 0 ) : ?>target="_blank"<?php endif; ?>>
					<?php foreach ( (array) ( $spec['form_data'] ?? [] ) as $k => $v ) : ?>
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
}
