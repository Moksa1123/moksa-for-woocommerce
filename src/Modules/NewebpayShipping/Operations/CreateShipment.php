<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\NewebpayShipping\Operations;

use MoksaWeb\Mowc\Modules\NewebpayShipping\Api\Helper;
use MoksaWeb\Mowc\Modules\NewebpayShipping\Api\ShippingRequest;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class CreateShipment {

	
	public static function run( \WC_Order $order ): array {
		// 必要 meta：選店時已寫入的 store_id / store_type
		$store_id  = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_STORE_ID );
		if ( '' === $store_id ) {
			$store_id = (string) $order->get_meta( Keys::SHIPPING_CVS_STORE_ID );
		}
		if ( '' === $store_id ) {
			return [ 'ok' => false, 'message' => __( '訂單缺少取貨門市資訊，請顧客先選店或商家手動指定。', 'mo-ectools' ) ];
		}

		$lgs_type  = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_LGS_TYPE ) ?: get_option( 'moksafowo_newebpay_shipping_lgs_type', 'C2C' );
		$ship_type = (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_SHIP_TYPE ) ?: '1'; // default 7-11
		$is_cod    = 'cod' === $order->get_payment_method();
		// 藍新規範：UserName 純中文 1-5 字、不可帶空格。WC 預設 last+first 拼接會夾空白，必移除。
		$user_name = $order->get_shipping_last_name() . $order->get_shipping_first_name();
		if ( '' === trim( $user_name ) ) {
			$user_name = $order->get_billing_last_name() . $order->get_billing_first_name();
		}
		$user_name = preg_replace( '/\s+/u', '', (string) $user_name );

		// 寄件人資料 — 從 settings 讀，CreateShipment 必填（per NDNS 1.0.0 4.1）。
		$sender_name      = trim( (string) get_option( 'moksafowo_newebpay_shipping_sender_name', '' ) );
		$sender_phone     = trim( (string) get_option( 'moksafowo_newebpay_shipping_sender_phone', '' ) );
		$sender_cellphone = trim( (string) get_option( 'moksafowo_newebpay_shipping_sender_cellphone', '' ) );
		$sender_email     = trim( (string) get_option( 'moksafowo_newebpay_shipping_sender_email', '' ) );
		if ( '' === $sender_name || ( '' === $sender_phone && '' === $sender_cellphone ) ) {
			return [
				'ok'      => false,
				'message' => __( '請先到「WooCommerce → Moksa → 藍新物流 → 寄件人資料」填入姓名與電話。', 'mo-ectools' ),
			];
		}

		$mtn  = self::generate_mtn( $order->get_id() );
		$args = [
			'MerchantOrderNo' => $mtn,
			'TradeType'       => $is_cod ? 1 : 3, // 1=取貨付款 / 3=取貨不付款
			'UserName'        => mb_substr( $user_name, 0, 10 ),
			'UserTel'         => $order->get_billing_phone(),
			'UserEmail'       => $order->get_billing_email(),
			'StoreID'         => $store_id,
			'Amt'             => (int) ceil( (float) $order->get_total() ),
			'NotifyURL'       => home_url( '/wc-api/moksafowo_newebpay_shipping_status' ),
			'ItemDesc'        => mb_substr( self::build_item_desc( $order ), 0, 50 ),
			'LgsType'         => $lgs_type,
			'ShipType'        => $ship_type,
			'SenderName'      => mb_substr( $sender_name, 0, 10 ),
			'SenderTel'       => $sender_phone !== '' ? $sender_phone : $sender_cellphone,
			'SenderCellPhone' => $sender_cellphone,
			'SenderEmail'     => $sender_email,
		];

		Helper::log( 'createShipment request', [ 'order_id' => $order->get_id(), 'mtn' => $mtn, 'lgs_type' => $lgs_type, 'ship_type' => $ship_type, 'cod' => $is_cod ] );

		$result = ShippingRequest::create_shipment( $args );
		if ( ! $result['ok'] ) {
			$order->add_order_note( sprintf(
				/* translators: %s: error */
				__( '藍新物流建單失敗：%s', 'mo-ectools' ),
				$result['message']
			) );
			$order->save();
			return [ 'ok' => false, 'message' => $result['message'] ];
		}

		$data     = $result['data'];
		$lgs_no   = (string) ( $data['TradeNo'] ?? '' );  // NewebPay 物流單號
		$trade_no = (string) ( $data['TradeNo'] ?? '' );

		$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_LGS_NO, $lgs_no );
		$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_LGS_TYPE, (string) ( $data['LgsType'] ?? $lgs_type ) );
		$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO, $mtn );
		$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_SHIP_TYPE, (string) ( $data['ShipType'] ?? $ship_type ) );
		$order->update_meta_data( Keys::NEWEBPAY_SHIPPING_TRADE_TYPE, (string) ( $data['TradeType'] ?? '' ) );

		$order->add_order_note( sprintf(
			/* translators: 1: 物流單號, 2: LgsType */
			__( '藍新物流建單成功 — 物流單號 %1$s（LgsType=%2$s）', 'mo-ectools' ),
			$lgs_no,
			$lgs_type
		) );
		$order->save();

		return [ 'ok' => true, 'message' => 'OK', 'lgs_no' => $lgs_no, 'trade_no' => $trade_no ];
	}

	private static function generate_mtn( int $order_id ): string {
		$prefix = (string) get_option( 'moksafowo_newebpay_shipping_order_prefix', '' );
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ?? '';
		$rand   = bin2hex( random_bytes( 3 ) );
		return substr( $prefix . $order_id . 'R' . $rand, 0, 30 );
	}

	private static function build_item_desc( \WC_Order $order ): string {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name();
		}
		$desc = implode( ',', $items );
		return '' !== $desc ? $desc : '訂單 #' . $order->get_id();
	}
}
