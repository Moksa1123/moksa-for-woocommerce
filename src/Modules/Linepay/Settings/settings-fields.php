<?php

defined( 'ABSPATH' ) || exit;

return array(

	'enabled'     => array(
		'title'   => __( '啟用', 'mo-ectools' ),
		'type'    => 'checkbox',
		'label'   => __( '啟用此付款方式', 'mo-ectools' ),
		'default' => 'no',
	),
	'title'       => array(
		'title'       => __( '結帳頁顯示名稱', 'mo-ectools' ),
		'type'        => 'text',
		'description' => __( '顧客在結帳頁看到的名稱。', 'mo-ectools' ),
		'default'     => __( 'LINE Pay', 'mo-ectools' ),
		'desc_tip'    => true,
	),
	'description' => array(
		'title'       => __( '結帳頁說明文字', 'mo-ectools' ),
		'type'        => 'textarea',
		'description' => __( '顧客在結帳頁看到的說明（顯示在付款方式名稱下方）。', 'mo-ectools' ),
		'desc_tip'    => true,
	),
);
