<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping\Operations;

defined( 'ABSPATH' ) || exit;

final class StatusMapper {


	public static function map( string $retld ): array {
		$lookup = [
			// Forward shipping (取貨流程)
			'0_1' => [
				'label'     => __( '建單成功', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'shipping',
			],
			'0_2' => [
				'label'     => __( '已建立託運單', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'shipping',
			],
			'0_3' => [
				'label'     => __( '到店待出貨', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'shipping',
			],
			'1'   => [
				'label'     => __( '已交寄物流', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-shipped',
				'type'      => 'shipping',
			],
			'2'   => [
				'label'     => __( '配送中', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-shipped',
				'type'      => 'shipping',
			],
			'3'   => [
				'label'     => __( '已到店待取', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-cvs-arrived',
				'type'      => 'shipping',
			],
			'4'   => [
				'label'     => __( '顧客已取貨', 'moksa-for-woocommerce' ),
				'wc_status' => 'completed',
				'type'      => 'shipping',
			],
			'11'  => [
				'label'     => __( '配送異常', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'error',
			],
			'5'   => [
				'label'     => __( '已取消', 'moksa-for-woocommerce' ),
				'wc_status' => 'cancelled',
				'type'      => 'error',
			],
			'6'   => [
				'label'     => __( '逾期未取', 'moksa-for-woocommerce' ),
				'wc_status' => 'moksa-store-closed',
				'type'      => 'error',
			],
			// Negative codes (錯誤 / 退貨)
			'-1'  => [
				'label'     => __( '建單失敗', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'error',
			],
			'-6'  => [
				'label'     => __( '退貨 — 物流退單', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-9'  => [
				'label'     => __( '退貨 — 商品破損', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-2'  => [
				'label'     => __( '退貨 — 顧客拒收', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-3'  => [
				'label'     => __( '退貨 — 貨況異常', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-4'  => [
				'label'     => __( '退貨 — 顧客逾期未取', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-5'  => [
				'label'     => __( '退貨 — 顧客取消訂單', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'-7'  => [
				'label'     => __( '退貨配送中', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'-10' => [
				'label'     => __( '退貨已到商家', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'-11' => [
				'label'     => __( '退貨完成', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			// 退貨流程（Forward 進行中的退貨）
			'10'  => [
				'label'     => __( '退貨建單成功', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'12'  => [
				'label'     => __( '退貨配送中', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'13'  => [
				'label'     => __( '退貨已到店', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'14'  => [
				'label'     => __( '退貨已寄回', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'returning',
			],
			'15'  => [
				'label'     => __( '退貨完成', 'moksa-for-woocommerce' ),
				'wc_status' => 'refunded',
				'type'      => 'returning',
			],
			'16'  => [
				'label'     => __( '退貨異常 / 已取消', 'moksa-for-woocommerce' ),
				'wc_status' => '',
				'type'      => 'error',
			],
		];
		return $lookup[ $retld ] ?? [
			/* translators: %s: NewebPay Retld status code */
			'label'     => sprintf( __( '未知狀態（Retld %s）', 'moksa-for-woocommerce' ), $retld ),
			'wc_status' => '',
			'type'      => 'unknown',
		];
	}
}
