<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Frontend;

use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\LgsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;

defined( 'ABSPATH' ) || exit;

final class AddressFormatter {

	public static function raw_shipping_address( $raw_address, $order ) {
		$is_payuni_cvs    = false;
		$shipping_methods = $order->get_shipping_methods();

		if ( ! empty( $shipping_methods ) ) {
			foreach ( $shipping_methods as $shipping_method ) {
				if ( false !== strpos( $shipping_method->get_method_id(), 'moksafowo_payuni_shipping_711' ) ) {
					$is_payuni_cvs = true;
					break;
				}
			}
		} elseif ( WC()->session ) {
			// 結帳時訂單未存，從 session 抓 chosen_shipping_methods
			$chosen = WC()->session->get( 'chosen_shipping_methods' );
			if ( ! empty( $chosen ) ) {
				$method_id = false !== strpos( $chosen[0], ':' ) ? explode( ':', $chosen[0] )[0] : $chosen[0];
				if ( false !== strpos( $method_id, 'moksafowo_payuni_shipping_711' ) ) {
					$is_payuni_cvs = true;
				}
			}
		}

		if ( $is_payuni_cvs && $order->get_meta( OrderMeta::StoreId ) ) {
			$raw_address['moksafowo_payuni_storeid']      = $order->get_meta( OrderMeta::StoreId );
			$raw_address['moksafowo_payuni_storename']    = $order->get_meta( OrderMeta::StoreName );
			$raw_address['moksafowo_payuni_storeaddress'] = $order->get_meta( OrderMeta::StoreAddr );
			$raw_address['phone']               = PayuniShipping::moksafowo_payuni_get_shipping_phone( $order );
			$raw_address['country']             = 'PNCVS';
		} else {
			$phone = PayuniShipping::moksafowo_payuni_get_shipping_phone( $order );
			if ( $phone ) {
				$raw_address['phone'] = $phone;
			}
			if ( $order->get_meta( OrderMeta::ShipType ) === ShipType::TCAT ) {
				$raw_address['country'] = 'PNHD';
			}
		}

		return $raw_address;
	}

	public static function address_format( $address_formats ) {
		$address_formats['TW'] = "{postcode}\n{country} {state} {city}\n{address_1} {address_2}\n{company} {last_name} {first_name}";
		if ( is_admin() ) {
			$address_formats['PNCVS'] = "{payuni_storename} ({payuni_storeid})\n{payuni_storeaddress}\n{last_name} {first_name}\n";
			$address_formats['PNHD']  = "{postcode}\n {state} {city}\n{address_1} {address_2}\n{company} {last_name} {first_name}\n";
		} else {
			$address_formats['PNCVS'] = "{payuni_storename} ({payuni_storeid})\n{payuni_storeaddress}\n{last_name} {first_name}\n"
				. '<p class="woocommerce-customer-details--phone">{phone}</p>';
			$address_formats['PNHD']  = "{postcode}\n {state} {city}\n{address_1} {address_2}\n{company} {last_name} {first_name}\n"
				. '<p class="woocommerce-customer-details--phone">{phone}</p>';
		}
		return $address_formats;
	}

	public static function address_replacements( $replacements, $args ) {
		if ( isset( $args['moksafowo_payuni_storeid'] ) ) {
			$replacements['{payuni_storeid}']      = $args['moksafowo_payuni_storeid'];
			$replacements['{payuni_storename}']    = $args['moksafowo_payuni_storename'] ?? '';
			$replacements['{payuni_storeaddress}'] = $args['moksafowo_payuni_storeaddress'] ?? '';
		}
		if ( isset( $args['phone'] ) ) {
			$replacements['{phone}'] = $args['phone'];
		}
		return $replacements;
	}

	public static function address_map( $address, $order ) {
		if ( $order->get_meta( OrderMeta::StoreName ) ) {
			$address['storename'] = $order->get_meta( OrderMeta::StoreName );
			unset( $address['address_2'], $address['country'], $address['city'], $address['state'], $address['postcode'], $address['company'] );
		}
		return $address;
	}

	// B2C: PartnerId(3) + Odno(8) = 11；C2C: Odno(8) + ValidationNo(4) = 12
	public static function format_cvs_shipno( $order ) {
		$ship_type = $order->get_meta( OrderMeta::ShipType );
		if ( $ship_type !== ShipType::SEVEN ) {
			return '';
		}

		$partner_id    = $order->get_meta( OrderMeta::PartnerId );
		$odno          = $order->get_meta( OrderMeta::Odno );
		$validation_no = $order->get_meta( OrderMeta::ValidationNo );
		$ship_no       = $order->get_meta( OrderMeta::ShipNo );
		$lgs_type      = $order->get_meta( OrderMeta::LgsType );

		if ( $lgs_type === LgsType::B2C && strlen( (string) $ship_no ) !== 11 ) {
			$new_ship_no = $partner_id . $odno;
			if ( 11 === strlen( $new_ship_no ) ) {
				$order->update_meta_data( OrderMeta::ShipNo, $new_ship_no );
				$order->save();
				PayuniShipping::log( 'Format B2C ShipNo. old: ' . $ship_no . ', new: ' . $new_ship_no );
			}
			return $new_ship_no;
		}
		if ( $lgs_type === LgsType::C2C && strlen( (string) $ship_no ) !== 12 ) {
			$new_ship_no = $odno . $validation_no;
			if ( 12 === strlen( $new_ship_no ) ) {
				$order->update_meta_data( OrderMeta::ShipNo, $new_ship_no );
				$order->save();
				PayuniShipping::log( 'Format C2C ShipNo. old: ' . $ship_no . ', new: ' . $new_ship_no );
			}
			return $new_ship_no;
		}
		return $ship_no;
	}
}
