<?php

defined( 'ABSPATH' ) || exit;

return array(

	'enabled'     => array(
		'title'   => __( 'Enable', 'moksa-for-woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable this payment method', 'moksa-for-woocommerce' ),
		'default' => 'no',
	),
	'title'       => array(
		'title'       => __( 'Title', 'moksa-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'The name customers see at checkout.', 'moksa-for-woocommerce' ),
		'default'     => __( 'LINE Pay', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'description' => array(
		'title'       => __( 'Description', 'moksa-for-woocommerce' ),
		'type'        => 'textarea',
		'description' => __( 'The description customers see at checkout, below the payment method name.', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
);
