<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Frontend;

use MoksaWeb\Mowc\Modules\Address\TwAddress;
use MoksaWeb\Mowc\Modules\EcpayShipping\Module;
use MoksaWeb\Mowc\Modules\EcpayShipping\Operations\CreateOrder;
use MoksaWeb\Mowc\Modules\Shipping\Tracking\TrackingLink;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class CustomerOrderView {

	public static function init(): void {
		add_action( 'woocommerce_order_details_before_order_table', [ __CLASS__, 'render' ], 5, 1 );
		add_filter( 'woocommerce_get_order_item_totals', [ __CLASS__, 'filter_order_item_totals' ], 10, 3 );
		add_filter( 'woocommerce_order_item_name', [ __CLASS__, 'filter_item_name' ], 10, 2 );
		// HPOS 訂單列表「運送至」column 走 $order->get_shipping_method()，沒過 wc_get_order_item_name
		add_filter( 'woocommerce_order_shipping_method', [ __CLASS__, 'filter_shipping_method_string' ], 10, 2 );
		// my-account/view-order 頁載入共用 card CSS + clipboard JS（priority 20 確保 register 先跑）
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_copy_script' ], 20 );
	}

	public static function enqueue_copy_script(): void {
		// 只在 my-account/view-order 載入共用 card CSS + clipboard JS
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		wp_enqueue_style( 'moksafowo-shipping-card' );
		wp_enqueue_script( 'moksafowo-tracking-copy' );
	}

	public static function filter_shipping_method_string( string $shipping_method, $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return $shipping_method;
		}
		$map   = Module::method_map();
		$names = [];
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid  = (string) $m->get_method_id();
			$name = (string) $m->get_name();
			if ( isset( $map[ $mid ] ) ) {
				$names[] = self::label_with_breakdown( $name, $mid );
			} else {
				$names[] = $name;
			}
		}
		return ! empty( $names ) ? implode( ', ', $names ) : $shipping_method;
	}

	public static function filter_order_item_totals( array $total_rows, $order, $tax_display ): array {
		if ( ! $order instanceof \WC_Order || ! isset( $total_rows['shipping'] ) ) {
			return $total_rows;
		}
		$map   = Module::method_map();
		$names = [];
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid  = (string) $m->get_method_id();
			$name = (string) $m->get_name();
			// 我們提供的物流 method 一律以最新的 carrier_title 顯示
			// （cover raw method_id / 舊「ECPay — XXX」/ 中間 rename 過的多種 legacy 字串）
			// 例外：stored name 含 multi-temp breakdown（CartTempLabel 注入的 ` · `
			//      per-temp 分隔符）就保留 stored name，讓 my-account 看到完整 breakdown
			if ( isset( $map[ $mid ] ) ) {
				$names[] = self::label_with_breakdown( $name, $mid );
			} else {
				$names[] = $name;
			}
		}
		if ( ! empty( $names ) ) {
			$total_rows['shipping']['value'] = implode( ', ', $names );
		}
		return $total_rows;
	}

	
	private static function label_with_breakdown( string $stored_name, string $method_id ): string {
		if ( '' === $stored_name ) {
			return self::carrier_title( $method_id );
		}
		// 偵測 CartTempLabel 注入的視覺標記（任一出現即視為有 breakdown）
		$markers = [ '　｜　', '🟫', '🟦', '🟪' ];
		foreach ( $markers as $m ) {
			if ( str_contains( $stored_name, $m ) ) {
				return $stored_name;
			}
		}
		return self::carrier_title( $method_id );
	}

	public static function filter_item_name( string $name, $item ): string {
		if ( ! $item instanceof \WC_Order_Item_Shipping ) {
			return $name;
		}
		$method_id = (string) $item->get_method_id();
		if ( ! isset( Module::method_map()[ $method_id ] ) ) {
			return $name;
		}
		return self::carrier_title( $method_id );
	}

	public static function render( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$map       = Module::method_map();
		$method_id = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid = (string) $m->get_method_id();
			if ( isset( $map[ $mid ] ) ) {
				$method_id = $mid;
				break;
			}
		}
		if ( '' === $method_id ) {
			return;
		}

		$records = CreateOrder::get_records( $order );
		if ( empty( $records ) ) {
			return;
		}

		$is_cvs      = str_contains( $method_id, '_cvs_' );
		$carrier     = self::carrier_title( $method_id );
		$status      = $order->get_status();
		$status_text = wc_get_order_status_name( $status );
		$status_tone = self::status_tone( $status );
		$is_split    = count( $records ) > 1;

		?>
		<section class="moksafowo-shipping-card" aria-label="<?php esc_attr_e( '物流資訊', 'mo-ectools' ); ?>">

			<header class="moksafowo-shipping-card__head">
				<h2 class="moksafowo-shipping-card__title">
					<span class="moksafowo-shipping-card__title-text"><?php esc_html_e( '物流資訊', 'mo-ectools' ); ?></span>
					<span class="moksafowo-shipping-card__subtitle"><?php echo esc_html( $carrier ); ?></span>
				</h2>
				<span class="moksafowo-shipping-card__pill moksafowo-shipping-card__pill--<?php echo esc_attr( $status_tone ); ?>">
					<?php echo esc_html( $status_text ); ?>
				</span>
			</header>

			<div class="moksafowo-shipping-card__body">
				<?php
				// 取貨資訊 (CVS) 或 收件人/地址 (HOME) — 全 records 共用，顯示一次就好
				if ( $is_cvs ) :
					$store_name = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_NAME );
					$store_id   = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );
					$store_addr = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ADDRESS );
					if ( '' !== $store_name || '' !== $store_id ) : ?>
						<div class="moksafowo-shipping-card__row">
							<span class="moksafowo-shipping-card__label"><?php esc_html_e( '取貨門市', 'mo-ectools' ); ?></span>
							<span class="moksafowo-shipping-card__value">
								<?php echo esc_html( $store_name ); ?>
								<?php if ( '' !== $store_id ) : ?>
									<span class="moksafowo-shipping-card__store-id">#<?php echo esc_html( $store_id ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $store_addr ) : ?>
									<span class="moksafowo-shipping-card__store-addr"><?php echo esc_html( $store_addr ); ?></span>
								<?php endif; ?>
							</span>
						</div>
					<?php endif;
				else :
					$address   = TwAddress::format_shipping_address( $order );
					$recipient = trim( $order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name() );
					if ( '' === $recipient ) {
						$recipient = trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() );
					}
					if ( '' !== $recipient ) : ?>
						<div class="moksafowo-shipping-card__row">
							<span class="moksafowo-shipping-card__label"><?php esc_html_e( '收件人', 'mo-ectools' ); ?></span>
							<span class="moksafowo-shipping-card__value"><?php echo esc_html( $recipient ); ?></span>
						</div>
					<?php endif;
					if ( '' !== $address ) : ?>
						<div class="moksafowo-shipping-card__row">
							<span class="moksafowo-shipping-card__label"><?php esc_html_e( '收件地址', 'mo-ectools' ); ?></span>
							<span class="moksafowo-shipping-card__value"><?php echo esc_html( $address ); ?></span>
						</div>
					<?php endif;
				endif;

				// records loop — 多溫層拆單訂單會有多筆，每筆顯示物流編號 + 貨態查詢按鈕
				if ( $is_split ) : ?>
					<div class="moksafowo-shipping-card__row" style="grid-template-columns:1fr;border-bottom:1px dashed #f1f5f9;">
						<span class="moksafowo-shipping-card__label" style="font-size:12px;">
							<?php
							/* translators: %d: package count */
							echo esc_html( sprintf( __( '本訂單依商品溫層拆成 %d 張物流單', 'mo-ectools' ), count( $records ) ) );
							?>
						</span>
					</div>
				<?php endif;

				foreach ( $records as $r ) :
					$rec_id  = (string) ( $r['id'] ?? '' );
					$rec_pay = (string) ( $r['cvs_payment_no'] ?? '' );
					$rec_val = (string) ( $r['cvs_validation_no'] ?? '' );
					$rec_book = (string) ( $r['booking_note'] ?? '' );
					$rec_temp = (int) ( $r['temp'] ?? 0 );
					$temp_label = $rec_temp > 0 ? \MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp::label( $rec_temp ) : '';
					$tracking_info = TrackingLink::for_ecpay_record( $r );
					?>
					<div class="moksafowo-shipping-card__row">
						<span class="moksafowo-shipping-card__label">
							<?php
							echo esc_html__( '物流編號', 'mo-ectools' );
							if ( $is_split && '' !== $temp_label ) {
								echo '<br><span style="font-size:11px;color:#94a3b8;">' . esc_html( $temp_label ) . '</span>';
							}
							?>
						</span>
						<span class="moksafowo-shipping-card__value" style="display:flex;flex-direction:column;gap:6px;">
							<?php if ( '' !== $rec_id ) : ?>
								<span class="moksafowo-shipping-card__code"><?php echo esc_html( $rec_id ); ?></span>
							<?php endif; ?>
							<?php if ( $is_cvs && '' !== $rec_pay ) : ?>
								<span style="font-size:12px;color:#475569;">
									<?php esc_html_e( '寄貨編號：', 'mo-ectools' ); ?>
									<span class="moksafowo-shipping-card__code"><?php echo esc_html( $rec_pay ); ?><?php if ( '' !== $rec_val ) : ?> / <?php echo esc_html( $rec_val ); ?><?php endif; ?></span>
								</span>
							<?php endif; ?>
							<?php if ( null !== $tracking_info ) : ?>
								<?php echo wp_kses( TrackingLink::render_button_html( $tracking_info ), TrackingLink::kses_allowlist() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses-filtered above. ?>
							<?php endif; ?>
						</span>
					</div>
					<?php
				endforeach;
				?>
			</div>
		</section>
		<?php
	}

	private static function carrier_title( string $method_id ): string {
		$map = [
			'moksafowo_ecpay_shipping_cvs_711'    => __( '綠界 — 7-11 取貨', 'mo-ectools' ),
			'moksafowo_ecpay_shipping_cvs_family' => __( '綠界 — 全家取貨', 'mo-ectools' ),
			'moksafowo_ecpay_shipping_cvs_hilife' => __( '綠界 — 萊爾富取貨', 'mo-ectools' ),
			'moksafowo_ecpay_shipping_cvs_okmart' => __( '綠界 — OK 取貨', 'mo-ectools' ),
			'moksafowo_ecpay_shipping_home_tcat'  => __( '綠界 — 黑貓宅配', 'mo-ectools' ),
			'moksafowo_ecpay_shipping_home_post'  => __( '綠界 — 中華郵政', 'mo-ectools' ),
		];
		return $map[ $method_id ] ?? __( '綠界物流', 'mo-ectools' );
	}

	private static function status_tone( string $status ): string {
		$status = ltrim( $status, 'wc-' );
		switch ( $status ) {
			case 'moksa-shipped':
				return 'blue';
			case 'moksa-cvs-arrived':
			case 'moksa-store-closed':
				return 'amber';
			case 'completed':
				return 'green';
			case 'failed':
			case 'cancelled':
			case 'refunded':
			case 'moksafowo-failed':
				return 'rose';
			default:
				return 'slate';
		}
	}
}
