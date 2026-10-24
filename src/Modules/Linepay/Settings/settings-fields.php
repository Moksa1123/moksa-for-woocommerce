<?php

defined( 'ABSPATH' ) || exit;

$moksafowo_fields = array(

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

if ( \Moksafowo\Compatibility\Subscriptions::active() ) {
	$moksafowo_fields['subscriptions'] = array(
		'title'       => __( 'Subscriptions', 'moksa-for-woocommerce' ),
		'type'        => 'checkbox',
		'label'       => __( 'Charge WooCommerce Subscriptions renewals automatically with LINE Pay', 'moksa-for-woocommerce' ),
		'description' => __( 'LINE Pay must approve automatic payments for your store before you turn this on.', 'moksa-for-woocommerce' ),
		'default'     => 'no',
	);
}

return $moksafowo_fields;
