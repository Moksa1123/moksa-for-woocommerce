<?php

namespace Moksafowo\Modules\PayuniShipping\Admin;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;
use Moksafowo\Modules\PayuniShipping\Utils\GoodsType;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Modules\PayuniShipping\Utils\ServiceType;



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
			'title' => __( '物流資訊', 'mo-ectools' ),
			'html'  => self::card_html( $order ),
		];
		return $cards;
	}

	private static function card_html( \WC_Order $order ): string {
		$ship_type     = $order->get_meta( OrderMeta::ShipType );
		$goods_type    = $order->get_meta( OrderMeta::GoodsType );
		$lgs_type      = $order->get_meta( OrderMeta::LgsType );
		$partner_id    = $order->get_meta( OrderMeta::PartnerId );
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

		ob_start();
		echo '<table style="width:100%;font-size:12px;table-layout:fixed;">';
		echo '<tr><th style="text-align:left;"><div id="order-id" data-order-id="' . esc_attr( (string) $oid ) . '">' . esc_html__( '物流交易序號', 'mo-ectools' ) . '</div></th><td>' . esc_html( $order->get_meta( OrderMeta::ShipTradeNo ) ) . '</td></tr>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $provider_query_html built locally with esc_url applied
		echo '<tr><th style="text-align:left;">' . esc_html__( '物流單號', 'mo-ectools' ) . '</th><td style="word-break:break-all;">' . esc_html( $shipping_no ) . $provider_query_html . '</td></tr>';

		if ( $ship_type === ShipType::SEVEN ) {
			echo '<tr><th style="text-align:left;">' . esc_html__( '門市代碼', 'mo-ectools' ) . '</th><td>' . esc_html( $partner_id ) . '</td></tr>';
			echo '<tr><th style="text-align:left;">' . esc_html__( '訂單號碼', 'mo-ectools' ) . '</th><td>' . esc_html( $odno ) . '</td></tr>';

			if ( $lgs_type === LgsType::C2C ) {
				echo '<tr><th style="text-align:left;">' . esc_html__( '驗證碼', 'mo-ectools' ) . '</th><td>' . esc_html( $validation_no ) . '</td></tr>';
			}
		}
		echo '<tr><th style="text-align:left;">' . esc_html__( '物流類型', 'mo-ectools' ) . '</th><td>' . esc_html( ShipType::get_name( $ship_type ) ) . '</td></tr>';
		echo '<tr><th style="text-align:left;">' . esc_html__( '配送方式', 'mo-ectools' ) . '</th><td>' . esc_html( LgsType::get_name( $lgs_type ) ) . '</td></tr>';
		echo '<tr><th style="text-align:left;">' . esc_html__( '商品類型', 'mo-ectools' ) . '</th><td>' . esc_html( GoodsType::get_name( $goods_type ) ) . '</td></tr>';

		if ( $ship_type === ShipType::TCAT ) {
			$package_spec         = $order->get_meta( OrderMeta::PackageSpec );
			$package_spec_options = self::get_package_spec_options( $goods_type );

			echo '<tr><th style="text-align:left;">' . esc_html__( '包裹規格', 'mo-ectools' ) . '</th><td>';
			echo '<select id="package-spec-select" style="font-size:12px;height:24px;line-height:22px;padding:0 4px;margin:0;vertical-align:middle;box-sizing:border-box;max-width:140px;" data-order-id="' . esc_attr( (string) $oid ) . '" data-original-value="' . esc_attr( $package_spec ) . '">';
			foreach ( $package_spec_options as $value => $label ) {
				$selected = selected( $package_spec, $value, false );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns escaped HTML attribute
				echo '<option value="' . esc_attr( $value ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '</td></tr>';
		}

		echo '<tr><th style="text-align:left;">' . esc_html__( '服務類型', 'mo-ectools' ) . '</th><td>' . esc_html( ServiceType::get_name( $service_type ) ) . '</td></tr>';

		if ( $order->get_payment_method() === 'cod' || $service_type === ServiceType::COD ) {
			echo '<tr><th style="text-align:left;">' . esc_html__( '代收金額', 'mo-ectools' ) . '</th><td>' . esc_html( $trade_amt ) . '</td></tr>';
		}

		echo '<tr><th style="text-align:left;">' . esc_html__( '物流狀態', 'mo-ectools' ) . '</th><td>' . esc_html( $ship_status ) . '</td></tr>';
		echo '<tr><th style="text-align:left;">' . esc_html__( '狀態說明', 'mo-ectools' ) . '</th><td>' . esc_html( $ship_status_desc ) . '</td></tr>';
		echo '<tr><th style="text-align:left;">' . esc_html__( '狀態時間', 'mo-ectools' ) . '</th><td>' . esc_html( $ship_status_time ) . '</td></tr>';

		if ( $ship_type === ShipType::TCAT ) {
			echo '<tr><th style="text-align:left;">' . esc_html__( '列印時間', 'mo-ectools' ) . '</th><td>' . esc_html( $print_date ) . '</td></tr>';
		}

		if ( ! empty( $shipping_no ) && $ship_type === ShipType::TCAT ) {
			$label_btn = '<button class="button print-label" data-id="' . esc_attr( (string) $oid ) . '" data-service="' . esc_attr( (string) $ship_type ) . '" data-action="moksafowo_payuni_shipping_download_label">' . esc_html__( '下載標籤', 'mo-ectools' ) . '</button>';
		} else {
			$label_btn = '<button class="button print-label" data-id="' . esc_attr( (string) $oid ) . '" data-service="' . esc_attr( (string) $ship_type ) . '" data-action="moksafowo_payuni_shipping_print_label">' . esc_html__( '列印標籤', 'mo-ectools' ) . '</button>';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $label_btn built locally with esc_attr/esc_html applied
		echo '<tr id="moksafowo-payuni-action"><th style="text-align:left;">' . esc_html__( '物流單動作', 'mo-ectools' ) . '</th><td>' . $label_btn . '<button class="button update-delivery-status" data-id="' . esc_attr( (string) $oid ) . '">' . esc_html__( '查詢', 'mo-ectools' ) . '</button></td></tr>';
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
