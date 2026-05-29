<?php
namespace MoksaWeb\Mowc\Modules\PayuniShipping\Settings;

defined( 'ABSPATH' ) || exit;

return array(
	'title'                    => array(
		'title'       => __( 'Title', 'mo-ectools' ),
		'type'        => 'text',
		'description' => __( 'This controls the title which the user sees during checkout.', 'mo-ectools' ),
		'default'     => __( 'PAYUNi Shipping 7-11 B2C Frozen', 'mo-ectools' ),
		'desc_tip'    => true,
	),
	'description'              => array(
		'title'       => __( 'Description', 'mo-ectools' ),
		'type'        => 'textarea',
		'description' => __( 'This controls the description which the user sees during checkout.', 'mo-ectools' ),
		'desc_tip'    => true,
	),
	'cost'                     => array(
		'title'   => __( 'Shipping Cost', 'mo-ectools' ),
		'type'    => 'number',
		'default' => 0,
		'min'     => 0,
		'step'    => 1,
	),
	'free_shipping_requires'   => array(
		'title'   => __( 'Free shipping requires', 'mo-ectools' ),
		'type'    => 'select',
		'class'   => 'wc-enhanced-select',
		'default' => '',
		'options' => array(
			''           => __( '不啟用免運', 'mo-ectools' ),
			'coupon'     => __( 'A valid free shipping coupon', 'mo-ectools' ),
			'min_amount' => __( 'A minimum order amount', 'mo-ectools' ),
			'either'     => __( 'A minimum order amount OR a coupon', 'mo-ectools' ),
			'both'       => __( 'A minimum order amount AND a coupon', 'mo-ectools' ),
		),
	),
	'free_shipping_min_amount' => array(
		'title'       => __( 'Minimum order amount for free shipping', 'mo-ectools' ),
		'type'        => 'price',
		'default'     => 0,
		'placeholder' => wc_format_localized_price( '0' ),
		'description' => __( 'Users will need to spend this amount to get free shipping.', 'mo-ectools' ),
		'desc_tip'    => true,
	),
	'ignore_discounts'         => array(
		'title'       => __( 'Coupons discounts', 'mo-ectools' ),
		'label'       => __( 'Apply minimum order rule before coupon discount', 'mo-ectools' ),
		'type'        => 'checkbox',
		'description' => __( 'If checked, free shipping would be available based on pre-discount order amount.', 'mo-ectools' ),
		'default'     => 'yes',
		'desc_tip'    => true,
	),
);
