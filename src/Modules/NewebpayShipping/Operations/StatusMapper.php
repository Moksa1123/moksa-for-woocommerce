<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping\Operations;

defined( 'ABSPATH' ) || exit;

final class StatusMapper {


	public static function map( string $retld ): array {
		$lookup = [
			// Forward shipping (取貨流程)
			'0_1' => [
				'label'     => __( 'Shipment created', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'shipping',
			],
			'0_2' => [
				'label'     => __( 'Shipment created', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'shipping',
			],
			'0_3' => [
				'label'     => __( 'At the store, waiting to be dispatched', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'shipping',
			],
			'1'   => [
				'label'     => __( 'Handed to the carrier', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-shipped',
				'type'      => 'shipping',
			],
			'2'   => [
				'label'     => __( 'In transit', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-shipped',
				'type'      => 'shipping',
			],
			'3'   => [
				'label'     => __( 'Arrived at the store, waiting for pickup', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-cvs-arrived',
				'type'      => 'shipping',
			],
			'4'   => [
				'label'     => __( 'Collected by the customer', 'moksa-for-woocommerce' ),
				'wc_status' => 'completed',
				'type'      => 'shipping',
			],
			'11'  => [
				'label'     => __( 'Delivery problem', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'error',
			],
			'5'   => [
				'label'     => __( 'Cancelled', 'moksa-for-woocommerce' ),
				'wc_status' => 'cancelled',
				'type'      => 'error',
			],
			'6'   => [
				'label'     => __( 'Not collected in time', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-store-closed',
				'type'      => 'error',
			],
			// Negative codes (錯誤 / 退貨)
			'-1'  => [
				'label'     => __( 'Could not create the shipment', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'error',
			],
			'-6'  => [
				'label'     => __( 'Return — rejected by the carrier', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-9'  => [
				'label'     => __( 'Return — damaged goods', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-2'  => [
				'label'     => __( 'Return — refused by the customer', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-3'  => [
				'label'     => __( 'Return — parcel problem', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-4'  => [
				'label'     => __( 'Return — not collected in time', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-5'  => [
				'label'     => __( 'Return — order cancelled by the customer', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-7'  => [
				'label'     => __( 'Return in transit', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'-10' => [
				'label'     => __( 'Return received by the store', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'-11' => [
				'label'     => __( 'Return completed', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			// 退貨流程（Forward 進行中的退貨）
			'10'  => [
				'label'     => __( 'Return shipment created', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'12'  => [
				'label'     => __( 'Return in transit', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'13'  => [
				'label'     => __( 'Return arrived at the store', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'14'  => [
				'label'     => __( 'Return sent back', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'15'  => [
				'label'     => __( 'Return completed', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'16'  => [
				'label'     => __( 'Return problem or cancelled', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'error',
			],
		];
		return $lookup[ $retld ] ?? [
			/* translators: %s: NewebPay Retld status code */
			'label'     => sprintf( __( 'Unknown status (Retld %s)', 'moksa-for-woocommerce' ), $retld ),
			'wc_status' => '',
			'type'      => 'unknown',
		];
	}
}
