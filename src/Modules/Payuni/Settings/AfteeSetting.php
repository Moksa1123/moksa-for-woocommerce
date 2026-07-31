<?php


defined( 'ABSPATH' ) || exit;


return array(

	'enabled'                    => array(
		'title'   => __( 'Enable', 'moksa-for-woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable this payment method', 'moksa-for-woocommerce' ),
		'default' => 'no',
	),
	'title'                      => array(
		'title'       => __( 'Title', 'moksa-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'The name customers see at checkout.', 'moksa-for-woocommerce' ),
		'default'     => __( 'PAYUNi AFTEE Payment', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'description'                => array(
		'title'       => __( 'Description', 'moksa-for-woocommerce' ),
		'type'        => 'textarea',
		'description' => __( 'The description customers see at checkout, below the payment method name.', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'min_amount'                 => array(
		'title'       => __( 'Minimum order amount', 'moksa-for-woocommerce' ),
		'type'        => 'number',
		'description' => __( 'Orders below this amount will not see this payment method.', 'moksa-for-woocommerce' ),
	),
	'incomplete_payment_message' => array(
		'title'       => __( 'Unfinished payment message', 'moksa-for-woocommerce' ),
		'type'        => 'textarea',
		'description' => __( 'The message customers see on the order received page while the payment is still outstanding.', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),

);
