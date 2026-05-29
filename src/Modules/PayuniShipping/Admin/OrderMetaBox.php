<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\GoodsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\LgsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ServiceType;



defined( 'ABSPATH' ) || exit;

class OrderMetaBox {

	public static function add_meta_box( $post_type, $post_or_order_object ) {

        $order = ( $post_or_order_object instanceof \WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$screen = wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
		? wc_get_page_screen_id( 'shop-order' )
		: 'shop_order';

        foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
            if ( $item instanceof \WC_Order_Item_Shipping && PayuniShipping::is_payuni_shipping( $item->get_method_id() ) ) {
                add_meta_box( 'payuni-shipping-info', __( 'PAYUNi Shipping Info', 'mo-ectools' ), array( __CLASS__, 'output' ), $screen, 'side', 'high' );
                break;
            }
        }
    
	}

	public static function output( $post ) {
		global $theorder;

		if ( ! is_object( $theorder ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WC core metabox callback injects $theorder; var name fixed by WC core API.
			$theorder = wc_get_order( $post->ID );
		}

		// UNi 物流單號.
		echo '<table>';
		echo '<tr><th><div id="order-id" data-order-id="' . esc_html( $theorder->get_id() ) . '">' . esc_html__( 'ShipTradeNo', 'mo-ectools' ) . '</div></th><td>' . esc_html( $theorder->get_meta( OrderMeta::ShipTradeNo ) ) . '</td></tr>';

		$ship_type     = $theorder->get_meta( OrderMeta::ShipType );
		$goods_type    = $theorder->get_meta( OrderMeta::GoodsType );
		$lgs_type      = $theorder->get_meta( OrderMeta::LgsType );
		$partner_id    = $theorder->get_meta( OrderMeta::PartnerId );
		$odno          = $theorder->get_meta( OrderMeta::Odno );
		$validation_no = $theorder->get_meta( OrderMeta::ValidationNo );
		$shipping_no   = ( $ship_type === ShipType::SEVEN ) ? PayuniShipping::format_cvs_shipno( $theorder ) : $theorder->get_meta( OrderMeta::ShipNo ); 

		$service_type  = $theorder->get_meta( OrderMeta::ServiceType );
		$trade_amt     = $theorder->get_meta( OrderMeta::TradeAmt );

		$ship_status      = $theorder->get_meta( OrderMeta::ShipStatus );
		$ship_status_desc = $theorder->get_meta( OrderMeta::ShipStatusDesc );
		$ship_status_time = $theorder->get_meta( OrderMeta::ShipStatusTime );
		$print_date       = $theorder->get_meta( OrderMeta::PrintDate );
		
		$provider_query_url  = ( $ship_type === ShipType::SEVEN ) ? 'https://tracking.shopmore.com.tw/' : 'https://www.t-cat.com.tw/inquire/trace.aspx';
		$provider_query_html = ( empty( $shipping_no ) ) ? '' : '<a href="' . esc_url( $provider_query_url ) . '" target="_blank"><span class="dashicons dashicons-search"></span></a>' ;
		
		//物流查詢編號(使用者查詢用的物流單號)
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $provider_query_html built locally with esc_url applied
		echo '<tr><th>' . esc_html__( 'ShipNo', 'mo-ectools' ) . '</th><td>' . esc_html( $shipping_no ) . $provider_query_html .'</td></tr>';

		if ( $ship_type === ShipType::SEVEN ) {
			echo '<tr><th>' . esc_html__( 'PartnerID', 'mo-ectools' ) . '</th><td>' . esc_html( $partner_id ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Odon', 'mo-ectools' ) . '</th><td>' . esc_html( $odno ) . '</td></tr>';
			
			if ( $lgs_type === LgsType::C2C ) {
				// validationNo is only for 7-11 C2C.
				echo '<tr><th>' . esc_html__( 'ValidationNo', 'mo-ectools' ) . '</th><td>' . esc_html( $validation_no ) . '</td></tr>';
			}
		}
		echo '<tr><th>' . esc_html__( 'Ship Type', 'mo-ectools' ) . '</th><td>' . esc_html( ShipType::get_name( $ship_type ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Logistic Type', 'mo-ectools' ) . '</th><td>' . esc_html( LgsType::get_name( $lgs_type ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Goods Type', 'mo-ectools' ) . '</th><td>' . esc_html( GoodsType::get_name( $goods_type ) ) . '</td></tr>';
		
		// Package spec field for TCAT shipping only
		if ( $ship_type === ShipType::TCAT ) {
			$package_spec = $theorder->get_meta( OrderMeta::PackageSpec );
			$package_spec_options = self::get_package_spec_options( $goods_type );
			$package_spec_display = isset( $package_spec_options[$package_spec] ) ? $package_spec_options[$package_spec] : $package_spec;
			
			echo '<tr><th>' . esc_html__( 'Package Spec', 'mo-ectools' ) . '</th><td>';
			echo '<select id="package-spec-select" data-order-id="' . esc_attr( $theorder->get_id() ) . '" data-original-value="' . esc_attr( $package_spec ) . '">';
			foreach ( $package_spec_options as $value => $label ) {
				$selected = selected( $package_spec, $value, false );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns escaped HTML attribute
				echo '<option value="' . esc_attr( $value ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '</td></tr>';
		}
		
		echo '<tr><th>' . esc_html__( 'Service Type', 'mo-ectools' ) . '</th><td>' . esc_html( ServiceType::get_name( $service_type ) ) . '</td></tr>';
		
		if ( $theorder->get_payment_method() === 'cod' || $service_type === ServiceType::COD ) {
			echo '<tr><th>' . esc_html__( 'COD Amount', 'mo-ectools' ) . '</th><td>' . esc_html( $trade_amt ) . '</td></tr>';
		}
		
		echo '<tr><th>' . esc_html__( 'Status', 'mo-ectools' ) . '</th><td>' . esc_html( $ship_status ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status Desc', 'mo-ectools' ) . '</th><td>' . esc_html( $ship_status_desc ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status Time', 'mo-ectools' ) . '</th><td>' . esc_html( $ship_status_time ) . '</td></tr>';

		if ( $ship_type === ShipType::TCAT ) {
			echo '<tr><th>' . esc_html__( 'Print Date', 'mo-ectools' ) . '</th><td>' . esc_html( $print_date ) . '</td></tr>';
		}

		if ( ! empty( $shipping_no ) && $ship_type === ShipType::TCAT ) {
			$label_btn = '<button class="button print-label" data-id=' . esc_html( $post->ID ) . ' data-service="' . esc_html( $ship_type ) . '" data-action="mo_payuni_shipping_download_label">下載標籤</button>';
		} else {
			$label_btn = '<button class="button print-label" data-id=' . esc_html( $post->ID ) . ' data-service="' . esc_html( $ship_type ) . '" data-action="mo_payuni_shipping_print_label">列印標籤</button>';
		}
		
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $label_btn built locally with esc_html applied to each interpolation
		echo '<tr id="payuni-action"><th>物流單動作</th><td>' . $label_btn . '<button class="button update-delivery-status" data-id="' . esc_html( $post->ID ) . '">查詢</button></td></tr>';
		echo '</table>';
		
		?>

		<?php
		wc_enqueue_js(
			'jQuery(function($) {
$(".print-label").click(function(){
    var newTab = window.open(ajaxurl + "?" + $.param({
        action: $(this).data("action"),
        orderids: $(this).data("id"),
		service: $(this).data("service"),
    }), "_blank");
    setTimeout(function() {
        newTab.location.reload();
    }, 5000);
});
});'
		);

	}

	private static function get_package_spec_options( $goods_type ) {
		$base_options = array(
			'1' => '60cm',
			'2' => '90cm',
			'3' => '120cm',
		);

		// Only normal temperature supports 150cm
		if ( $goods_type === \MoksaWeb\Mowc\Modules\PayuniShipping\Utils\GoodsType::NORMAL ) {
			$base_options['4'] = '150cm';
		}

		return $base_options;
	}
}
