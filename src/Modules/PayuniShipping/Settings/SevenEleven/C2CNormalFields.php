<?php

namespace Moksafowo\Modules\PayuniShipping\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


return array(
	'title'                    => array(
		'title'       => __( '配送方式名稱', 'moksa-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( '結帳頁顯示給顧客看的名稱。', 'moksa-for-woocommerce' ),
		'default'     => __( 'PAYUNi 7-11 超商取貨（個人寄件）', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'description'              => array(
		'title'       => __( '配送方式說明', 'moksa-for-woocommerce' ),
		'type'        => 'textarea',
		'description' => __( '結帳頁顯示給顧客看的補充說明。', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'cost'                     => array(
		'title'   => __( '運費', 'moksa-for-woocommerce' ),
		'type'    => 'number',
		'default' => 0,
		'min'     => 0,
		'step'    => 1,
	),
	'free_shipping_requires'   => array(
		'title'   => __( '免運條件', 'moksa-for-woocommerce' ),
		'type'    => 'select',
		'class'   => 'wc-enhanced-select',
		'default' => '',
		'options' => array(
			''           => __( '不啟用免運', 'moksa-for-woocommerce' ),
			'coupon'     => __( '使用免運優惠券', 'moksa-for-woocommerce' ),
			'min_amount' => __( '訂單滿額', 'moksa-for-woocommerce' ),
			'either'     => __( '訂單滿額或使用優惠券', 'moksa-for-woocommerce' ),
			'both'       => __( '訂單滿額且使用優惠券', 'moksa-for-woocommerce' ),
		),
	),
	'free_shipping_min_amount' => array(
		'title'       => __( '免運最低訂單金額', 'moksa-for-woocommerce' ),
		'type'        => 'price',
		'default'     => 0,
		'placeholder' => wc_format_localized_price( '0' ),
		'description' => __( '訂單滿這個金額才免運。', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'ignore_discounts'         => array(
		'title'       => __( '優惠券折扣', 'moksa-for-woocommerce' ),
		'label'       => __( '以折扣前金額判斷是否滿額免運', 'moksa-for-woocommerce' ),
		'type'        => 'checkbox',
		'description' => __( '勾選後，免運門檻以套用優惠券折扣前的訂單金額計算。', 'moksa-for-woocommerce' ),
		'default'     => 'yes',
		'desc_tip'    => true,
	),
);
