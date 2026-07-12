<?php

namespace Moksafowo\Modules\PayuniShipping\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


return array(
	'title'                    => array(
		'title'       => __( '配送方式名稱', 'mo-ectools' ),
		'type'        => 'text',
		'description' => __( '結帳頁顯示給顧客看的名稱。', 'mo-ectools' ),
		'default'     => __( 'PAYUNi 7-11 超商取貨（個人寄件）', 'mo-ectools' ),
		'desc_tip'    => true,
	),
	'description'              => array(
		'title'       => __( '配送方式說明', 'mo-ectools' ),
		'type'        => 'textarea',
		'description' => __( '結帳頁顯示給顧客看的補充說明。', 'mo-ectools' ),
		'desc_tip'    => true,
	),
	'cost'                     => array(
		'title'   => __( '運費', 'mo-ectools' ),
		'type'    => 'number',
		'default' => 0,
		'min'     => 0,
		'step'    => 1,
	),
	'free_shipping_requires'   => array(
		'title'   => __( '免運條件', 'mo-ectools' ),
		'type'    => 'select',
		'class'   => 'wc-enhanced-select',
		'default' => '',
		'options' => array(
			''           => __( '不啟用免運', 'mo-ectools' ),
			'coupon'     => __( '使用免運優惠券', 'mo-ectools' ),
			'min_amount' => __( '訂單滿額', 'mo-ectools' ),
			'either'     => __( '訂單滿額或使用優惠券', 'mo-ectools' ),
			'both'       => __( '訂單滿額且使用優惠券', 'mo-ectools' ),
		),
	),
	'free_shipping_min_amount' => array(
		'title'       => __( '免運最低訂單金額', 'mo-ectools' ),
		'type'        => 'price',
		'default'     => 0,
		'placeholder' => wc_format_localized_price( '0' ),
		'description' => __( '訂單滿這個金額才免運。', 'mo-ectools' ),
		'desc_tip'    => true,
	),
	'ignore_discounts'         => array(
		'title'       => __( '優惠券折扣', 'mo-ectools' ),
		'label'       => __( '以折扣前金額判斷是否滿額免運', 'mo-ectools' ),
		'type'        => 'checkbox',
		'description' => __( '勾選後，免運門檻以套用優惠券折扣前的訂單金額計算。', 'mo-ectools' ),
		'default'     => 'yes',
		'desc_tip'    => true,
	),
);
