<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Admin;

use MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp;
use MoksaWeb\Mowc\Modules\Shipping\Tracking\TrackingLink;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;


final class ShippingCardSection {

	public static function init(): void {
		// priority 21：與 payment(11) / invoice(31) 維持間隔，便於 ECPay 等 priority 10/30 先跑
		add_filter( 'moksafowo_order_info_cards', [ __CLASS__, 'add_shipping_card' ], 21, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	public static function enqueue_assets( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		$css = '.moksafowo-payuni-record summary{cursor:pointer;list-style:none;padding:10px 12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;font-size:12px;}'
			. '.moksafowo-payuni-record[open] summary{border-bottom-left-radius:0;border-bottom-right-radius:0;border-bottom:0;}'
			. '.moksafowo-payuni-record summary::-webkit-details-marker{display:none;}'
			. '.moksafowo-payuni-record summary::before{content:"▶";margin-right:2px;font-size:9px;color:#646970;display:inline-block;transition:transform .15s;flex-shrink:0;}'
			. '.moksafowo-payuni-record[open] summary::before{transform:rotate(90deg);}'
			. '.moksafowo-payuni-record__body{background:#f6f7f7;border:1px solid #dcdcde;border-top:0;border-bottom-left-radius:4px;border-bottom-right-radius:4px;padding:0 12px 10px;font-size:12px;line-height:1.5;}'
			. '.moksafowo-payuni-record__summary-id{font-family:monospace;font-weight:600;color:#0f172a;}'
			. '.moksafowo-payuni-record__summary-status{margin-left:auto;color:#64748b;font-size:11px;}';
		wp_register_style( 'moksafowo-shipping-card', false, [], MOKSAFOWO_VERSION );
		wp_enqueue_style( 'moksafowo-shipping-card' );
		wp_add_inline_style( 'moksafowo-shipping-card', $css );

		$js = 'jQuery(function($){'
			. '$(".moksafowo-newebpay-shipping-create").on("click",function(){var b=$(this);b.prop("disabled",true);$.post(ajaxurl,{action:"moksafowo_newebpay_shipping_create",order_id:b.data("order"),_wpnonce:b.data("nonce")},function(r){alert(r.success?r.data.message:r.data.message);if(r.success)location.reload();}).fail(function(){alert("AJAX 失敗");}).always(function(){b.prop("disabled",false);});});'
			. '$(".moksafowo-newebpay-shipping-query").on("click",function(){var b=$(this);b.prop("disabled",true);$.post(ajaxurl,{action:"moksafowo_newebpay_shipping_query",order_id:b.data("order"),_wpnonce:b.data("nonce")},function(r){alert(r.success?r.data.message:r.data.message);if(r.success)location.reload();}).fail(function(){alert("AJAX 失敗");}).always(function(){b.prop("disabled",false);});});'
			. '$(".moksafowo-newebpay-shipping-trace").on("click",function(){var b=$(this);b.prop("disabled",true);$.post(ajaxurl,{action:"moksafowo_newebpay_shipping_trace",order_id:b.data("order"),_wpnonce:b.data("nonce")},function(r){if(r.success){alert(r.data.history.map(function(h){return h.event_time+" — "+h.label;}).join("\n")||"無追蹤紀錄");}else{alert(r.data.message);}}).fail(function(){alert("AJAX 失敗");}).always(function(){b.prop("disabled",false);});});'
			. '$(".moksafowo-smilepay-shipping-create").on("click",function(){var b=$(this);b.prop("disabled",true);$.post(ajaxurl,{action:"moksafowo_smilepay_shipping_create",order_id:b.data("order"),_wpnonce:b.data("nonce")},function(r){alert(r.success?r.data.message:r.data.message);if(r.success)location.reload();}).fail(function(){alert("AJAX 失敗");}).always(function(){b.prop("disabled",false);});});'
			. '});';
		wp_register_script( 'moksafowo-shipping-card', false, [ 'jquery' ], MOKSAFOWO_VERSION, true );
		wp_enqueue_script( 'moksafowo-shipping-card' );
		wp_add_inline_script( 'moksafowo-shipping-card', $js );
	}

	public static function add_shipping_card( array $cards, \WC_Order $order ): array {
		foreach ( $cards as $c ) {
			if ( ( $c['slot'] ?? '' ) === 'shipping' ) {
				return $cards;
			}
		}

		$shipping_provider = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$mid = (string) $m->get_method_id();
			if ( str_starts_with( $mid, 'moksafowo_payuni_shipping_' ) ) {
				$shipping_provider = 'moksafowo_payuni';
				break;
			}
			if ( str_starts_with( $mid, 'moksafowo_newebpay_shipping_' ) ) {
				$shipping_provider = 'newebpay';
				break;
			}
			if ( str_starts_with( $mid, 'moksafowo_smilepay_shipping_' ) ) {
				$shipping_provider = 'smilepay';
				break;
			}
		}
		if ( '' === $shipping_provider ) {
			return $cards;
		}

		$html = match ( $shipping_provider ) {
			'payuni'   => self::render_payuni_shipping( $order ),
			'newebpay' => self::render_newebpay_shipping( $order ),
			'smilepay' => self::render_smilepay_shipping( $order ),
			default    => '',
		};
		if ( '' !== $html ) {
			$cards[] = [
				'slot'  => 'shipping',
				'title' => __( '物流資訊', 'mo-ectools' ),
				'html'  => $html,
			];
		}
		return $cards;
	}

	private static function render_payuni_shipping( \WC_Order $order ): string {
		$mod_meta = '\MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta';
		$records  = \MoksaWeb\Mowc\Modules\PayuniShipping\Operations\CreateOrderUnified::get_records( $order );
		$trade_no = (string) $order->get_meta( $mod_meta::ShipTradeNo );
		$ship_no  = (string) $order->get_meta( $mod_meta::ShipNo );

		$method_title = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$method_title = (string) $m->get_name();
			break;
		}

		if ( empty( $records ) && '' === $trade_no && '' === $ship_no ) {
			ob_start();
			if ( '' !== $method_title ) {
				echo '<p><strong>' . esc_html__( '運送方式：', 'mo-ectools' ) . '</strong>' . esc_html( $method_title ) . '</p>';
			}
			echo '<p style="color:#646970;font-size:12px;">' . esc_html__( '尚未建立 PAYUNi 物流單。', 'mo-ectools' ) . '</p>';
			return (string) ob_get_clean();
		}

		ob_start();
		if ( '' !== $method_title ) {
			echo '<p><strong>' . esc_html__( '運送方式：', 'mo-ectools' ) . '</strong>' . esc_html( $method_title ) . '</p>';
		}

		if ( ! empty( $records ) ) {
			$is_cod   = 'cod' === (string) $order->get_payment_method();
			$is_split = count( $records ) > 1;
			if ( $is_split ) {
				echo '<p style="margin:0 0 8px;font-size:11px;color:#646970;">';
				/* translators: %d: number of split shipping records */
				echo esc_html( sprintf( __( '本訂單依商品溫層拆成 %d 張物流單，每張獨立列印與追蹤。', 'mo-ectools' ), count( $records ) ) );
				echo '</p>';
			}
			echo '<div class="moksafowo-payuni-records" style="display:flex;flex-direction:column;gap:8px;margin:0 0 8px;">';
			foreach ( $records as $r ) {
				$temp       = (int) ( $r['temp'] ?? 0 );
				$amount     = (int) ( $r['amount'] ?? 0 );
				$temp_label = $temp > 0 ? ProductTemp::label( $temp ) : '';
				$temp_pill  = match ( $temp ) {
					2       => [ '#dbeafe', '#1e40af' ],
					3       => [ '#ede9fe', '#6d28d9' ],
					default => [ '#e5e7eb', '#374151' ],
				};
				/* translators: %d: cash-on-delivery amount in TWD */
				$cod_label  = $is_cod ? sprintf( __( 'NT$%d (貨到付款)', 'mo-ectools' ), $amount ) : __( '否', 'mo-ectools' );
				$ship_t     = (string) ( $r['ship_type'] ?? '' );
				$type_label = '1' === $ship_t ? __( '7-11', 'mo-ectools' ) : ( '2' === $ship_t ? __( '黑貓宅配', 'mo-ectools' ) : '' );
				$ship_trade_no = (string) ( $r['ship_trade_no'] ?? '' );
				$rtn_msg_p     = (string) ( $r['rtn_msg'] ?? '' );
				$open_attr     = $is_split ? '' : 'open';
				echo '<details class="moksafowo-payuni-record" ' . esc_attr( $open_attr ) . '>';
				echo '<summary>';
				echo '<span class="moksafowo-payuni-record__summary-id">' . esc_html( $ship_trade_no ) . '</span>';
				if ( '' !== $type_label ) {
					echo '<span style="background:#dbeafe;color:#1e40af;padding:1px 8px;border-radius:3px;font-size:11px;white-space:nowrap;">' . esc_html( $type_label ) . '</span>';
				}
				if ( '' !== $temp_label ) {
					echo '<span style="background:' . esc_attr( $temp_pill[0] ) . ';color:' . esc_attr( $temp_pill[1] ) . ';padding:1px 8px;border-radius:3px;font-size:11px;white-space:nowrap;">' . esc_html( $temp_label ) . '</span>';
				}
				if ( '' !== $rtn_msg_p ) {
					echo '<span class="moksafowo-payuni-record__summary-status">' . esc_html( $rtn_msg_p ) . '</span>';
				}
				echo '</summary>';
				echo '<div class="moksafowo-payuni-record__body">';
				if ( ! empty( $r['odno'] ) ) {
					echo '<p style="margin:.2em 0;"><strong>' . esc_html__( '物流商出貨編號：', 'mo-ectools' ) . '</strong><span style="font-family:monospace;">' . esc_html( (string) $r['odno'] ) . '</span></p>';
				}
				if ( ! empty( $r['validation_no'] ) ) {
					echo '<p style="margin:.2em 0;"><strong>' . esc_html__( '驗證碼：', 'mo-ectools' ) . '</strong><span style="font-family:monospace;">' . esc_html( (string) $r['validation_no'] ) . '</span></p>';
				}
				if ( $amount > 0 ) {
					/* translators: %d: declared value amount in TWD */
					echo '<p style="margin:.2em 0;"><strong>' . esc_html__( '申報價值：', 'mo-ectools' ) . '</strong>' . esc_html( sprintf( __( 'NT$%d', 'mo-ectools' ), $amount ) ) . '</p>';
					echo '<p style="margin:.2em 0;"><strong>' . esc_html__( '代收貨款：', 'mo-ectools' ) . '</strong>' . esc_html( $cod_label ) . '</p>';
				}
				if ( ! empty( $r['created_at'] ) ) {
					echo '<p style="margin:.2em 0;"><strong>' . esc_html__( '建立時間：', 'mo-ectools' ) . '</strong>' . esc_html( (string) $r['created_at'] ) . '</p>';
				}
				$tracking_info = TrackingLink::for_payuni_record( $r );
				if ( null !== $tracking_info ) {
					echo '<div style="margin-top:8px;">' . wp_kses( TrackingLink::render_button_html( $tracking_info ), TrackingLink::kses_allowlist() ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses-filtered above.
				}
				echo '</div>';
				echo '</details>';
			}
			echo '</div>';
			return (string) ob_get_clean();
		}

		$odno      = (string) $order->get_meta( $mod_meta::Odno );
		$status    = (string) $order->get_meta( $mod_meta::ShipStatus );
		$status_d  = (string) $order->get_meta( $mod_meta::ShipStatusDesc );
		$status_t  = (string) $order->get_meta( $mod_meta::ShipStatusTime );
		$trade_amt = (string) $order->get_meta( $mod_meta::TradeAmt );
		$service_t = (string) $order->get_meta( $mod_meta::ServiceType );

		if ( '' !== $trade_no ) {
			echo '<p><strong>' . esc_html__( 'PAYUNi 物流編號：', 'mo-ectools' ) . '</strong><span style="font-family:monospace;">' . esc_html( $trade_no ) . '</span></p>';
		}
		if ( '' !== $odno ) {
			echo '<p><strong>' . esc_html__( '物流商出貨編號：', 'mo-ectools' ) . '</strong><span style="font-family:monospace;">' . esc_html( $odno ) . '</span></p>';
		}
		if ( '' !== $ship_no ) {
			echo '<p><strong>' . esc_html__( '物流單號：', 'mo-ectools' ) . '</strong><span style="font-family:monospace;">' . esc_html( $ship_no ) . '</span></p>';
		}
		if ( '' !== $trade_amt ) {
			$is_cod = '1' === $service_t;
			echo '<p><strong>' . esc_html__( '代收貨款：', 'mo-ectools' ) . '</strong>';
			echo $is_cod
				/* translators: %d: cash-on-delivery amount in TWD */
				? esc_html( sprintf( __( 'NT$%d (貨到付款)', 'mo-ectools' ), (int) $trade_amt ) )
				: esc_html__( '否', 'mo-ectools' );
			echo '</p>';
		}
		if ( '' !== $status_d ) {
			echo '<p><strong>' . esc_html__( '物流狀態：', 'mo-ectools' ) . '</strong>' . esc_html( $status_d );
			if ( '' !== $status ) {
				echo ' <span style="color:#646970;font-size:11px;">(' . esc_html( $status ) . ')</span>';
			}
			echo '</p>';
		}
		if ( '' !== $status_t ) {
			echo '<p><strong>' . esc_html__( '狀態更新時間：', 'mo-ectools' ) . '</strong>' . esc_html( $status_t ) . '</p>';
		}
		$tracking_info = TrackingLink::for_payuni_record( [
			'ship_type' => (string) $order->get_meta( $mod_meta::ShipType ),
			'odno'      => $odno,
		] );
		if ( null !== $tracking_info ) {
			echo '<div style="margin-top:8px;">' . wp_kses( TrackingLink::render_button_html( $tracking_info ), TrackingLink::kses_allowlist() ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses-filtered above.
		}
		return (string) ob_get_clean();
	}

	private static function render_newebpay_shipping( \WC_Order $order ): string {
		$lgs_no     = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_LGS_NO );
		$lgs_type   = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_LGS_TYPE );
		$store_id   = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_STORE_ID );
		$store_name = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_STORE_NAME );
		$store_addr = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_STORE_ADDR );
		$status     = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_STATUS );

		$method_title = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$method_title = (string) $m->get_name();
			break;
		}

		$nonce_create = wp_create_nonce( 'moksafowo_newebpay_shipping_create' );
		$nonce_query  = wp_create_nonce( 'moksafowo_newebpay_shipping_query' );
		$nonce_trace  = wp_create_nonce( 'moksafowo_newebpay_shipping_trace' );
		$order_id     = $order->get_id();

		ob_start();
		if ( '' !== $method_title ) {
			echo '<p><strong>' . esc_html__( '運送方式：', 'mo-ectools' ) . '</strong>' . esc_html( $method_title ) . '</p>';
		}
		if ( '' === $lgs_no && '' === $store_id ) {
			echo '<p style="color:#646970;font-size:12px;margin-bottom:10px;">';
			echo esc_html__( '尚未取得藍新物流資訊。顧客可在結帳頁選店，或商家自行建立物流單。', 'mo-ectools' );
			echo '</p>';
		}
		if ( '' !== $lgs_no ) {
			echo '<p><strong>' . esc_html__( '藍新物流編號：', 'mo-ectools' ) . '</strong><span style="font-family:monospace;">' . esc_html( $lgs_no ) . '</span></p>';
		}
		if ( '' !== $lgs_type ) {
			echo '<p><strong>' . esc_html__( '物流類型：', 'mo-ectools' ) . '</strong>' . esc_html( $lgs_type ) . '</p>';
		}
		if ( '' !== $store_id || '' !== $store_name ) {
			echo '<p><strong>' . esc_html__( '取貨門市：', 'mo-ectools' ) . '</strong>';
			if ( '' !== $store_name ) {
				echo esc_html( $store_name );
			}
			if ( '' !== $store_id ) {
				echo ' <span style="font-family:monospace;color:#646970;font-size:11px;">(' . esc_html( $store_id ) . ')</span>';
			}
			echo '</p>';
		}
		if ( '' !== $store_addr ) {
			echo '<p><strong>' . esc_html__( '門市地址：', 'mo-ectools' ) . '</strong>' . esc_html( $store_addr ) . '</p>';
		}
		if ( '' !== $status ) {
			echo '<p><strong>' . esc_html__( '物流狀態：', 'mo-ectools' ) . '</strong>' . esc_html( $status ) . '</p>';
		}

		echo '<p style="margin-top:10px;padding-top:8px;border-top:1px dashed #c0c0c0;">';
		if ( '' === $lgs_no && '' !== $store_id ) {
			echo '<button type="button" class="button button-primary moksafowo-newebpay-shipping-create" data-order="' . esc_attr( (string) $order_id ) . '" data-nonce="' . esc_attr( $nonce_create ) . '">' . esc_html__( '建立藍新物流單', 'mo-ectools' ) . '</button> ';
		}
		if ( '' !== $lgs_no ) {
			echo '<button type="button" class="button moksafowo-newebpay-shipping-query" data-order="' . esc_attr( (string) $order_id ) . '" data-nonce="' . esc_attr( $nonce_query ) . '">' . esc_html__( '查詢即時狀態', 'mo-ectools' ) . '</button> ';
			echo '<button type="button" class="button moksafowo-newebpay-shipping-trace" data-order="' . esc_attr( (string) $order_id ) . '" data-nonce="' . esc_attr( $nonce_trace ) . '">' . esc_html__( '物流追蹤', 'mo-ectools' ) . '</button>';
		}
		echo '</p>';

		return (string) ob_get_clean();
	}

	private static function render_smilepay_shipping( \WC_Order $order ): string {
		$smseid     = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_NO );
		$ship_type  = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_TYPE );
		$lgs_type   = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_LGS_TYPE );
		$store_id   = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_ID );
		$store_name = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_NAME );
		$store_addr = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STORE_ADDR );
		$pay_no     = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_PAY_NO );
		$track_no   = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_TRACK_NO );
		$status     = (string) $order->get_meta( Keys::SMILEPAY_SHIPPING_STATUS );

		$method_title = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$method_title = (string) $m->get_name();
			break;
		}

		$nonce_create = wp_create_nonce( 'moksafowo_smilepay_shipping_create' );
		$order_id     = $order->get_id();

		ob_start();
		if ( '' !== $method_title ) {
			echo '<p><strong>' . esc_html__( '運送方式：', 'mo-ectools' ) . '</strong>' . esc_html( $method_title ) . '</p>';
		}
		if ( '' !== $smseid ) {
			echo '<p><strong>' . esc_html__( '速買配序號：', 'mo-ectools' ) . '</strong><span style="font-family:monospace;">' . esc_html( $smseid ) . '</span></p>';
		}
		if ( '' !== $ship_type ) {
			echo '<p><strong>' . esc_html__( '物流類型：', 'mo-ectools' ) . '</strong>' . esc_html( $ship_type ) . ( '' !== $lgs_type ? ' (' . esc_html( $lgs_type ) . ')' : '' ) . '</p>';
		}
		if ( '' !== $store_id || '' !== $store_name ) {
			echo '<p><strong>' . esc_html__( '取貨門市：', 'mo-ectools' ) . '</strong>';
			if ( '' !== $store_name ) {
				echo esc_html( $store_name );
			}
			if ( '' !== $store_id ) {
				echo ' <span style="font-family:monospace;color:#646970;font-size:11px;">(' . esc_html( $store_id ) . ')</span>';
			}
			echo '</p>';
		}
		if ( '' !== $store_addr ) {
			echo '<p><strong>' . esc_html__( '門市地址：', 'mo-ectools' ) . '</strong>' . esc_html( $store_addr ) . '</p>';
		}
		if ( '' !== $pay_no ) {
			echo '<p><strong>' . esc_html__( '取貨碼：', 'mo-ectools' ) . '</strong><span style="font-family:monospace;">' . esc_html( $pay_no ) . '</span></p>';
		}
		if ( '' !== $track_no ) {
			echo '<p><strong>' . esc_html__( '黑貓託運單號：', 'mo-ectools' ) . '</strong><span style="font-family:monospace;">' . esc_html( $track_no ) . '</span></p>';
		}
		if ( '' !== $status ) {
			echo '<p><strong>' . esc_html__( '物流狀態：', 'mo-ectools' ) . '</strong>' . esc_html( $status ) . '</p>';
		}
		$tracking_info = TrackingLink::for_smilepay_record( [
			'lgs_type'  => $lgs_type,
			'pay_no'    => $pay_no,
			'track_num' => $track_no,
		] );
		if ( null !== $tracking_info ) {
			echo '<div style="margin-top:8px;">' . wp_kses( TrackingLink::render_button_html( $tracking_info ), TrackingLink::kses_allowlist() ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses-filtered above.
		}
		if ( '' === $smseid && '' === $store_id ) {
			echo '<p style="color:#646970;font-size:12px;margin-bottom:10px;">';
			echo esc_html__( '尚未建立速買配物流單。', 'mo-ectools' );
			echo '</p>';
		}

		echo '<p style="margin-top:10px;padding-top:8px;border-top:1px dashed #c0c0c0;">';
		if ( '' === $pay_no && '' === $track_no ) {
			echo '<button type="button" class="button button-primary moksafowo-smilepay-shipping-create" data-order="' . esc_attr( (string) $order_id ) . '" data-nonce="' . esc_attr( $nonce_create ) . '">' . esc_html__( '建立速買配物流單', 'mo-ectools' ) . '</button>';
		} else {
			echo '<span style="color:#00a32a;">' . esc_html__( '已建單', 'mo-ectools' ) . '</span>';
		}
		echo '</p>';

		return (string) ob_get_clean();
	}
}
