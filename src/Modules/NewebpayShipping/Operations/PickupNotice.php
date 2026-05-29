<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\NewebpayShipping\Operations;

use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class PickupNotice {

	private const ACTION = 'mo_newebpay_shipping_label';

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'output' ] );
	}

	public static function render( array $order_ids, array $options = [] ): array {
		$valid_ids = [];
		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( $order instanceof \WC_Order && self::has_store( $order ) ) {
				$valid_ids[] = (int) $oid;
			}
		}
		if ( empty( $valid_ids ) ) {
			return [];
		}
		return [ [
			'api_url'   => admin_url( 'admin-post.php' ),
			'form_data' => [
				'action'    => self::ACTION,
				'order_ids' => implode( ',', $valid_ids ),
				'_wpnonce'  => wp_create_nonce( self::ACTION ),
			],
		] ];
	}

	public static function output(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( '權限不足。', 'mo-ectools' ) );
		}
		check_admin_referer( self::ACTION );

		$raw = isset( $_POST['order_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['order_ids'] ) ) : '';
		$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );

		$orders = [];
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( $o instanceof \WC_Order && self::has_store( $o ) ) {
				$orders[] = $o;
			}
		}
		if ( empty( $orders ) ) {
			wp_die( esc_html__( '沒有可列印的託運單（訂單缺少取貨門市資訊）。', 'mo-ectools' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- build_html returns fully escaped HTML composed via esc_html/esc_attr internally
		echo self::build_html( $orders );
		exit;
	}

	public static function record_count( \WC_Order $order ): int {
		return self::has_store( $order ) ? 1 : 0;
	}

	private static function has_store( \WC_Order $order ): bool {
		$id = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_STORE_ID );
		if ( '' === $id ) {
			$id = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );
		}
		return '' !== $id;
	}

	private static function build_html( array $orders ): string {
		$shop_name    = (string) get_bloginfo( 'name' );
		$shop_address = (string) ( get_option( 'woocommerce_store_address', '' ) );
		$shop_city    = (string) ( get_option( 'woocommerce_store_city', '' ) );
		$shop_postcode = (string) ( get_option( 'woocommerce_store_postcode', '' ) );

		ob_start();
		?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title><?php esc_html_e( '藍新物流託運單', 'mo-ectools' ); ?></title>
<style>
	body { font-family: "Microsoft JhengHei", "PingFang TC", sans-serif; margin: 0; padding: 12px; color: #000; }
	.label { width: 100mm; min-height: 150mm; border: 2px solid #000; padding: 12px; margin: 0 auto 12px; box-sizing: border-box; page-break-after: always; background: #fff; }
	.label:last-child { page-break-after: auto; }
	.label__head { text-align: center; padding-bottom: 8px; border-bottom: 2px solid #000; margin-bottom: 10px; }
	.label__head h1 { margin: 0; font-size: 16px; letter-spacing: 4px; }
	.label__head .subline { font-size: 11px; color: #555; margin-top: 4px; }
	.row { display: flex; padding: 4px 0; border-bottom: 1px dashed #999; }
	.row:last-child { border-bottom: 0; }
	.row .label-key { width: 70px; font-weight: bold; flex-shrink: 0; }
	.row .label-val { flex: 1; word-break: break-all; }
	.big { font-size: 22px; font-weight: bold; letter-spacing: 1px; }
	.store { background: #f0f0f0; padding: 8px; margin: 8px 0; border-left: 4px solid #000; }
	.store-id { font-size: 18px; font-weight: bold; }
	.items { margin-top: 10px; padding-top: 8px; border-top: 1px solid #000; font-size: 11px; }
	.items table { width: 100%; border-collapse: collapse; }
	.items th, .items td { padding: 3px 4px; border-bottom: 1px solid #ddd; text-align: left; }
	.items th { background: #eee; }
	.print-bar { margin: 10px auto; max-width: 100mm; padding: 8px; background: #f0f0f0; border-radius: 4px; }
	.print-bar button { padding: 6px 16px; font-size: 14px; cursor: pointer; }
	.print-bar .note { color: #666; font-size: 12px; margin-top: 4px; }
	@media print {
		.print-bar { display: none; }
		body { padding: 0; }
		.label { border: 1px solid #000; margin: 0; }
	}
</style>
</head>
<body>
<div class="print-bar">
	<button onclick="window.print()"><?php esc_html_e( '列印', 'mo-ectools' ); ?></button>
	<div class="note"><?php esc_html_e( '提示：藍新物流無 API 託運標籤，本單由 mowp 自產，貼於包裹上即可。', 'mo-ectools' ); ?></div>
</div>
<?php foreach ( $orders as $order ) : ?>
	<?php
	$store_id   = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_STORE_ID );
	$store_name = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_STORE_NAME );
	$store_addr = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_STORE_ADDR );
	if ( '' === $store_id ) {
		$store_id   = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );
		$store_name = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_NAME );
		$store_addr = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ADDRESS );
	}
	$customer = trim( $order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name() );
	if ( '' === $customer ) {
		$customer = trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() );
	}
	?>
	<div class="label">
		<div class="label__head">
			<h1><?php esc_html_e( '藍新物流託運單', 'mo-ectools' ); ?></h1>
			<div class="subline"><?php echo esc_html( $order->get_date_created()?->date( 'Y-m-d H:i' ) ?? '' ); ?></div>
		</div>
		<div class="row"><div class="label-key"><?php esc_html_e( '訂單編號', 'mo-ectools' ); ?></div><div class="label-val big">#<?php echo esc_html( (string) $order->get_id() ); ?></div></div>
		<div class="row"><div class="label-key"><?php esc_html_e( '寄件人', 'mo-ectools' ); ?></div><div class="label-val"><?php echo esc_html( $shop_name ); ?></div></div>
		<?php if ( '' !== $shop_address ) : ?>
		<div class="row"><div class="label-key"><?php esc_html_e( '寄件地址', 'mo-ectools' ); ?></div><div class="label-val"><?php echo esc_html( trim( $shop_postcode . ' ' . $shop_city . ' ' . $shop_address ) ); ?></div></div>
		<?php endif; ?>
		<div class="row"><div class="label-key"><?php esc_html_e( '收件人', 'mo-ectools' ); ?></div><div class="label-val big"><?php echo esc_html( $customer ); ?></div></div>
		<div class="row"><div class="label-key"><?php esc_html_e( '收件電話', 'mo-ectools' ); ?></div><div class="label-val"><?php echo esc_html( $order->get_billing_phone() ); ?></div></div>
		<div class="store">
			<div class="store-id"><?php esc_html_e( '取貨門市', 'mo-ectools' ); ?>：<?php echo esc_html( $store_name ); ?> <span style="font-weight:normal;">(<?php echo esc_html( $store_id ); ?>)</span></div>
			<?php if ( '' !== $store_addr ) : ?>
				<div style="margin-top:4px;"><?php echo esc_html( $store_addr ); ?></div>
			<?php endif; ?>
		</div>
		<div class="items">
			<table>
				<thead><tr><th><?php esc_html_e( '商品', 'mo-ectools' ); ?></th><th style="width:50px;text-align:right;"><?php esc_html_e( '數量', 'mo-ectools' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $order->get_items() as $item ) : ?>
					<tr>
						<td><?php echo esc_html( $item->get_name() ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="row" style="margin-top:8px;border-top:1px solid #000;padding-top:6px;"><div class="label-key"><?php esc_html_e( '訂單總額', 'mo-ectools' ); ?></div><div class="label-val big">NT$<?php echo esc_html( (string) (int) $order->get_total() ); ?></div></div>
	</div>
<?php endforeach; ?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
