<?php

namespace Moksafowo\Modules\PayuniShipping\Admin;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;
use Moksafowo\Modules\PayuniShipping\Utils\GoodsType;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Modules\PayuniShipping\Utils\ServiceType;
use Moksafowo\Order\Meta\Keys;



defined( 'ABSPATH' ) || exit;

class OrderMetaBox {

	/** PAYUNi 物流資訊 — 整合進統一的「金流 / 物流 / 電子發票」metabox（slot=shipping）。 */
	public static function add_card( array $cards, \WC_Order $order ): array {
		$is_payuni = false;
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Shipping && PayuniShipping::is_payuni_shipping( $item->get_method_id() ) ) {
				$is_payuni = true;
				break;
			}
		}
		if ( ! $is_payuni ) {
			return $cards;
		}

		$cards[] = [
			'slot'  => 'shipping',
			'title' => __( 'Shipping details', 'moksa-for-woocommerce' ),
			'html'  => self::card_html( $order ),
		];
		return $cards;
	}

	private static function card_html( \WC_Order $order ): string {
		$ship_type     = $order->get_meta( OrderMeta::ShipType );
		$goods_type    = $order->get_meta( OrderMeta::GoodsType );
		$lgs_type      = $order->get_meta( OrderMeta::LgsType );
		$odno          = $order->get_meta( OrderMeta::Odno );
		$validation_no = $order->get_meta( OrderMeta::ValidationNo );
		$shipping_no   = ( $ship_type === ShipType::SEVEN ) ? PayuniShipping::format_cvs_shipno( $order ) : $order->get_meta( OrderMeta::ShipNo );

		$service_type = $order->get_meta( OrderMeta::ServiceType );
		$trade_amt    = $order->get_meta( OrderMeta::TradeAmt );

		$ship_status      = $order->get_meta( OrderMeta::ShipStatus );
		$ship_status_desc = $order->get_meta( OrderMeta::ShipStatusDesc );
		$ship_status_time = $order->get_meta( OrderMeta::ShipStatusTime );
		$print_date       = $order->get_meta( OrderMeta::PrintDate );

		$provider_query_url  = ( $ship_type === ShipType::SEVEN ) ? 'https://tracking.shopmore.com.tw/' : 'https://www.t-cat.com.tw/inquire/trace.aspx';
		$provider_query_html = ( empty( $shipping_no ) ) ? '' : '<a href="' . esc_url( $provider_query_url ) . '" target="_blank"><span class="dashicons dashicons-search"></span></a>';

		$oid = (int) $order->get_id();

		// 沒有值的欄位不印（還沒建單、還沒收到貨態時整排空白很怪）。
		$row = static function ( string $label, $value ): void {
			if ( '' === trim( (string) $value ) ) {
				return;
			}
			echo '<tr><th style="text-align:left;">' . esc_html( $label ) . '</th><td style="word-break:break-all;">' . esc_html( (string) $value ) . '</td></tr>';
		};

		ob_start();
		echo '<table style="width:100%;font-size:12px;table-layout:fixed;">';
		$ship_trade_no = (string) $order->get_meta( OrderMeta::ShipTradeNo );
		// order-id 這個節點給後台 JS 讀訂單 ID，沒有交易編號時也要在。
		echo '<tr' . ( '' === $ship_trade_no ? ' style="display:none;"' : '' ) . '><th style="text-align:left;"><div id="order-id" data-order-id="' . esc_attr( (string) $oid ) . '">' . esc_html__( 'Shipping transaction ID', 'moksa-for-woocommerce' ) . '</div></th><td>' . esc_html( $ship_trade_no ) . '</td></tr>';

		if ( '' !== (string) $shipping_no ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $provider_query_html built locally with esc_url applied
			echo '<tr><th style="text-align:left;">' . esc_html__( 'Tracking number', 'moksa-for-woocommerce' ) . '</th><td style="word-break:break-all;">' . esc_html( $shipping_no ) . $provider_query_html . '</td></tr>';
		}

		if ( $ship_type === ShipType::SEVEN ) {
			// 原本這列標「門市代號」卻印 PartnerId（B2C 的廠商代號，已含在物流編號裡），顧客選的門市反而沒顯示。
			$store_id = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );
			if ( '' === $store_id ) {
				$store_id = (string) $order->get_meta( OrderMeta::StoreId );
			}
			if ( '' !== $store_id ) {
				$store_name = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_NAME );
				$store_addr = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ADDRESS );
				if ( '' === $store_name ) {
					$store_name = (string) $order->get_meta( OrderMeta::StoreName );
					$store_addr = (string) $order->get_meta( OrderMeta::StoreAddr );
				}
				echo '<tr><th style="text-align:left;">' . esc_html__( 'Pickup store', 'moksa-for-woocommerce' ) . '</th><td>' . esc_html( trim( $store_name . ' (' . $store_id . ')' ) ) . ( '' !== $store_addr ? '<br/>' . esc_html( $store_addr ) : '' ) . '</td></tr>';
			}
			if ( '' !== (string) $odno ) {
				echo '<tr><th style="text-align:left;">' . esc_html__( 'Sender code', 'moksa-for-woocommerce' ) . '</th><td>' . esc_html( $odno ) . '</td></tr>';
			}
			if ( $lgs_type === LgsType::C2C && '' !== (string) $validation_no ) {
				echo '<tr><th style="text-align:left;">' . esc_html__( 'Verification code', 'moksa-for-woocommerce' ) . '</th><td>' . esc_html( $validation_no ) . '</td></tr>';
			}
		}
		$row( __( 'Shipping type', 'moksa-for-woocommerce' ), '' !== (string) $ship_type ? ShipType::get_name( $ship_type ) : '' );
		$row( __( 'Delivery method', 'moksa-for-woocommerce' ), '' !== (string) $lgs_type ? LgsType::get_name( $lgs_type ) : '' );
		$row( __( 'Product type', 'moksa-for-woocommerce' ), '' !== (string) $goods_type ? GoodsType::get_name( $goods_type ) : '' );

		if ( $ship_type === ShipType::TCAT ) {
			$package_spec         = $order->get_meta( OrderMeta::PackageSpec );
			$package_spec_options = self::get_package_spec_options( $goods_type );

			echo '<tr><th style="text-align:left;">' . esc_html__( 'Parcel size', 'moksa-for-woocommerce' ) . '</th><td>';
			echo '<select id="package-spec-select" style="font-size:12px;height:24px;line-height:22px;padding:0 4px;margin:0;vertical-align:middle;box-sizing:border-box;max-width:140px;" data-order-id="' . esc_attr( (string) $oid ) . '" data-original-value="' . esc_attr( $package_spec ) . '">';
			foreach ( $package_spec_options as $value => $label ) {
				$selected = selected( $package_spec, $value, false );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns escaped HTML attribute
				echo '<option value="' . esc_attr( $value ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '</td></tr>';
		}

		// 統一方法（混溫）建單時服務類型與金額記在每一包的紀錄裡，單一欄位是空的；依付款方式與各包金額補上。
		if ( '' === (string) $service_type ) {
			$service_type = 'cod' === $order->get_payment_method() ? ServiceType::COD : ServiceType::NOT_COD;
		}
		if ( '' === (string) $trade_amt ) {
			$records = \Moksafowo\Modules\PayuniShipping\Operations\CreateOrderUnified::get_records( $order );
			if ( ! empty( $records ) ) {
				$trade_amt = implode( ' + ', array_map( static fn( $r ) => (string) ( $r['amount'] ?? '' ), $records ) );
			}
		}
		$row( __( 'Service type', 'moksa-for-woocommerce' ), ServiceType::get_name( $service_type ) );

		if ( $order->get_payment_method() === 'cod' || $service_type === ServiceType::COD ) {
			$row( __( 'Amount to collect', 'moksa-for-woocommerce' ), $trade_amt );
		}

		// 貨態顯示說明文字，代碼放在括號裡。
		$row( __( 'Shipping status', 'moksa-for-woocommerce' ), '' !== (string) $ship_status_desc ? $ship_status_desc . ( '' !== (string) $ship_status ? ' (' . $ship_status . ')' : '' ) : $ship_status );
		$row( __( 'Status updated', 'moksa-for-woocommerce' ), $ship_status_time );

		if ( $ship_type === ShipType::TCAT ) {
			$row( __( 'Printed at', 'moksa-for-woocommerce' ), $print_date );
		}

		if ( ! empty( $shipping_no ) && $ship_type === ShipType::TCAT ) {
			$label_btn = '<button class="button print-label" data-id="' . esc_attr( (string) $oid ) . '" data-service="' . esc_attr( (string) $ship_type ) . '" data-action="moksafowo_payuni_shipping_download_label">' . esc_html__( 'Download label', 'moksa-for-woocommerce' ) . '</button>';
		} else {
			$label_btn = '<button class="button print-label" data-id="' . esc_attr( (string) $oid ) . '" data-service="' . esc_attr( (string) $ship_type ) . '" data-action="moksafowo_payuni_shipping_print_label">' . esc_html__( 'Print label', 'moksa-for-woocommerce' ) . '</button>';
		}

		// 依溫層拆成多包時，上面的單一欄位只鏡射最後一包；每一包另外列出來。
		$packages = \Moksafowo\Modules\PayuniShipping\Operations\CreateOrderUnified::get_records( $order );
		if ( count( $packages ) > 1 ) {
			echo '<tr><td colspan="2" style="padding-top:8px;"><div style="font-weight:600;margin-bottom:4px;">' . esc_html__( 'Packages', 'moksa-for-woocommerce' ) . '</div>';
			foreach ( $packages as $pkg ) {
				$temp = (int) ( $pkg['temp'] ?? 0 );
				$desc = (string) ( $pkg['ship_status_desc'] ?? '' );
				echo '<div style="border:1px solid #dcdcde;border-radius:4px;padding:6px 8px;margin-bottom:6px;">';
				echo '<div><strong>' . esc_html( $temp > 0 ? \Moksafowo\Modules\Shipping\Temp\ProductTemp::label( $temp ) : '' ) . '</strong> <span style="font-family:monospace;">' . esc_html( (string) ( $pkg['odno'] ?? '' ) ) . '</span></div>';
				if ( '' !== $desc ) {
					echo '<div style="color:#646970;">' . esc_html( $desc ) . '</div>';
				}
				$info = \Moksafowo\Modules\Shipping\Tracking\TrackingLink::for_payuni_record( $pkg );
				if ( null !== $info ) {
					echo '<div style="margin-top:4px;">' . wp_kses( \Moksafowo\Modules\Shipping\Tracking\TrackingLink::render_button_html( $info ), \Moksafowo\Modules\Shipping\Tracking\TrackingLink::kses_allowlist() ) . '</div>';
				}
				echo '</div>';
			}
			echo '</td></tr>';
		}

		// 動作列走整列（colspan=2）+ 標題在上：這張卡片只有 200px 出頭，
		// 擠在右邊的值欄裡兩顆按鈕放不下，會上下堆疊。按鈕本身包 flex 給間距。
		// 還沒建物流單時，列印與查詢都無法運作，不顯示。
		if ( '' === $ship_trade_no ) {
			echo '</table>';
			return (string) ob_get_clean();
		}
		echo '<tr id="moksafowo-payuni-action"><td colspan="2" style="padding-top:10px;">'
			. '<div style="font-weight:600;margin-bottom:6px;">' . esc_html__( 'Shipment actions', 'moksa-for-woocommerce' ) . '</div>'
			. '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $label_btn built locally with esc_attr/esc_html applied
			. $label_btn
			. '<button class="button update-delivery-status" data-id="' . esc_attr( (string) $oid ) . '">' . esc_html__( 'Look up', 'moksa-for-woocommerce' ) . '</button>'
			. '</div></td></tr>';
		echo '</table>';

		return (string) ob_get_clean();
	}

	private static function get_package_spec_options( $goods_type ) {
		$base_options = array(
			'1' => '60cm',
			'2' => '90cm',
			'3' => '120cm',
		);

		if ( $goods_type === GoodsType::NORMAL ) {
			$base_options['4'] = '150cm';
		}

		return $base_options;
	}
}
