<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


return array(
	'title'                    => array(
		'title'       => __( 'Delivery method name', 'moksa-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'The name customers see at checkout.', 'moksa-for-woocommerce' ),
		'default'     => __( 'PAYUNi T-Cat home delivery (ambient)', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'description'              => array(
		'title'       => __( 'Delivery method description', 'moksa-for-woocommerce' ),
		'type'        => 'textarea',
		'description' => __( 'The extra description customers see at checkout.', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'cost'                     => array(
		'title'   => __( 'Shipping', 'moksa-for-woocommerce' ),
		'type'    => 'number',
		'default' => 0,
		'min'     => 0,
		'step'    => 1,
	),
	'free_shipping_requires'   => array(
		'title'   => __( 'Free shipping requires', 'moksa-for-woocommerce' ),
		'type'    => 'select',
		'class'   => 'wc-enhanced-select',
		'default' => '',
		'options' => array(
			''           => __( 'Free shipping off', 'moksa-for-woocommerce' ),
			'coupon'     => __( 'A free shipping coupon', 'moksa-for-woocommerce' ),
			'min_amount' => __( 'A minimum order amount', 'moksa-for-woocommerce' ),
			'either'     => __( 'A minimum order amount or a coupon', 'moksa-for-woocommerce' ),
			'both'       => __( 'A minimum order amount and a coupon', 'moksa-for-woocommerce' ),
		),
	),
	'free_shipping_min_amount' => array(
		'title'       => __( 'Minimum order amount for free shipping', 'moksa-for-woocommerce' ),
		'type'        => 'price',
		'default'     => 0,
		'placeholder' => wc_format_localized_price( '0' ),
		'description' => __( 'Orders must reach this amount to ship free.', 'moksa-for-woocommerce' ),
		'desc_tip'    => true,
	),
	'ignore_discounts'         => array(
		'title'       => __( 'Coupon discounts', 'moksa-for-woocommerce' ),
		'label'       => __( 'Compare the amount before coupon discounts', 'moksa-for-woocommerce' ),
		'type'        => 'checkbox',
		'description' => __( 'When ticked, the free shipping threshold is measured against the order total before coupon discounts.', 'moksa-for-woocommerce' ),
		'default'     => 'yes',
		'desc_tip'    => true,
	),
	'package_spec'             => array(
		'title'   => __( 'Parcel size (dimensions in cm)', 'moksa-for-woocommerce' ),
		'type'    => 'select',
		'class'   => 'wc-enhanced-select',
		'default' => '',
		'options' => array(
			'1' => '60',
			'2' => '90',
			'3' => '120',
			'4' => '150',
		),
	),
);
