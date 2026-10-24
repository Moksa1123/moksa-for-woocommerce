<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * 依 NDNS 1.0.0 p.31-32 RetId 貨態表。
 * 退貨中、異常、待商家處理的碼不轉訂單狀態（退款由商家決定）；買家未取的退回轉「未取件」，
 * 包裹銷毀或判賠才轉失敗。
 */
final class StatusMapper {


	public static function map( string $retld ): array {
		$lookup = [
			// 待處理
			'0_1' => [
				'label'     => __( 'Order not yet processed', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'shipping',
			],
			'0_2' => [
				'label'     => __( 'Shipping number expired, request a new one', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'error',
			],
			'0_3' => [
				'label'     => __( 'Shipment cancelled', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'error',
			],
			// 處理中／運送中
			'1'   => [
				'label'     => __( 'Order being processed', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'shipping',
			],
			'2'   => [
				'label'     => __( 'Received by the store', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-shipped',
				'type'      => 'shipping',
			],
			'3'   => [
				'label'     => __( 'Store reselected, waiting to be shipped again', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-shipped',
				'type'      => 'shipping',
			],
			'4'   => [
				'label'     => __( 'Checked in at the distribution center', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-shipped',
				'type'      => 'shipping',
			],
			'11'  => [
				'label'     => __( 'Pickup store closed, choose another store', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-store-closed',
				'type'      => 'error',
			],
			// 待取貨／已完成
			'5'   => [
				'label'     => __( 'Arrived at the store, waiting for pickup', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-cvs-arrived',
				'type'      => 'shipping',
			],
			'6'   => [
				'label'     => __( 'Collected by the customer', 'moksa-for-woocommerce' ),
				'wc_status' => 'completed',
				'type'      => 'shipping',
			],
			'-1'  => [
				'label'     => __( 'Returned to the seller', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'-6'  => [
				'label'     => __( 'Home delivery return requested', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'-9'  => [
				'label'     => __( 'Problem found at the distribution center, waiting for a reply', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'error',
			],
			// 退貨／賠償
			'-2'  => [
				'label'     => __( 'Return arrived at the original store', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'-3'  => [
				'label'     => __( 'Return checked in at the distribution center', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'-4'  => [
				'label'     => __( 'Not collected, returning to the distribution center', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-unclaimed',
				'type'      => 'returning',
			],
			'-5'  => [
				'label'     => __( 'Not collected, about to be returned', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-unclaimed',
				'type'      => 'returning',
			],
			'-7'  => [
				'label'     => __( 'Compensation claim filed or approved', 'moksa-for-woocommerce' ),
				'wc_status' => 'failed',
				'type'      => 'error',
			],
			'-10' => [
				'label'     => __( 'Parcel destroyed or discarded', 'moksa-for-woocommerce' ),
				'wc_status' => 'failed',
				'type'      => 'error',
			],
			'-11' => [
				'label'     => __( 'Parcel destroyed or discarded', 'moksa-for-woocommerce' ),
				'wc_status' => 'failed',
				'type'      => 'error',
			],
			'10'  => [
				'label'     => __( 'About to be returned', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'12'  => [
				'label'     => __( 'Return store closed, choose another store', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'error',
			],
			'13'  => [
				'label'     => __( 'Not collected by the seller, returning to the distribution center', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'14'  => [
				'label'     => __( 'Request a home delivery return', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'error',
			],
			'15'  => [
				'label'     => __( 'Parcel destroyed or discarded', 'moksa-for-woocommerce' ),
				'wc_status' => 'failed',
				'type'      => 'error',
			],
			'16'  => [
				'label'     => __( 'Confirm the remittance account', 'moksa-for-woocommerce' ),
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
