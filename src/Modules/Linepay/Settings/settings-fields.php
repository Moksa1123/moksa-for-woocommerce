<?php

defined( 'ABSPATH' ) || exit;

return array(

	'enabled'     => array(
		'title'   => __( '啟用', 'moksa-for-woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( '啟用此付款方式', 'moksa-for-woocommerce' ),
		'default' => 'no',
	),
	'title'       => array(
		'title'       => __( '結帳頁顯示名稱', 'moksa-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( '顧客在結帳頁看到的名稱。', 'moksa-for-woocommerce' ),
		'default'     => __( 'LINE Pay', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'description' => array(
		'title'       => __( '結帳頁說明文字', 'moksa-for-woocommerce' ),
		'type'        => 'textarea',
		'description' => __( '顧客在結帳頁看到的說明（顯示在付款方式名稱下方）。', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
);
