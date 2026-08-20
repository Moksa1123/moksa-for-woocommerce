<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress Abilities API — moksa-for-woocommerce/find-order-by-number。
 *
 * 唯讀 ability：用發票 / 物流 / 金流號碼查訂單。供 WP 命令面板、REST、
 * 以及（商家開啟 WC MCP 後）AI 共用。需 WP 6.9+ 核心 Abilities API。
 */
final class Ability {

	const ABILITY = 'moksa-for-woocommerce/find-order-by-number';

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'moksa-for-woocommerce' ) ) {
			return;
		}
		wp_register_ability_category(
			'moksa-for-woocommerce',
			[
				'label'       => __( 'Moksa for WooCommerce', 'moksa-for-woocommerce' ),
				'description' => __( 'Taiwan payment, shipping and e-invoice abilities', 'moksa-for-woocommerce' ),
			]
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			self::ABILITY,
			[
				'label'               => __( 'Find an order by number', 'moksa-for-woocommerce' ),
				'description'         => __( 'Look up matching WooCommerce orders by e-invoice number, tracking number or payment transaction ID, and return the order number, customer, status and admin link. Pass an order number (such as 2855) to check that order directly. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'number' => [
							'type'        => 'string',
							'description' => __( 'The order number to look up, or an invoice, shipping or payment number', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'number' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'orders' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'       => [ 'type' => 'integer' ],
									'number'   => [ 'type' => 'string' ],
									'name'     => [ 'type' => 'string' ],
									'status'   => [ 'type' => 'string' ],
									'total'    => [ 'type' => 'string' ],
									'matched'  => [ 'type' => 'string' ],
									'edit_url' => [ 'type' => 'string' ],
								],
							],
						],
					],
					'required'   => [ 'orders' ],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/get-order-details',
			[
				'label'               => __( 'Get order details', 'moksa-for-woocommerce' ),
				'description'         => __( 'Get the full details of a single order: status, customer, items, totals, payment method, shipping method, pickup store, plus the e-invoice number and whether it has been issued, the tracking number and the payment transaction ID. Use it to answer questions such as “has the invoice been issued”, “what is the tracking number” or “which store is it going to”. Accepts an order number or any of those numbers. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( 'An order number, or an invoice, shipping or payment number', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'number'          => [ 'type' => 'string' ],
						'status'          => [ 'type' => 'string' ],
						'invoice_number'  => [ 'type' => 'string' ],
						'invoice_issued'  => [ 'type' => 'boolean' ],
						'shipping_number' => [ 'type' => 'string' ],
						'payment_number'  => [ 'type' => 'string' ],
						'cvs_store'       => [ 'type' => 'string' ],
						'total'           => [ 'type' => 'string' ],
						'items'           => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_details' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/query-orders',
			[
				'label'               => __( 'Count orders by status', 'moksa-for-woocommerce' ),
				'description'         => __( 'Return order counts: either the number of orders in every status, or the count for one status. Common status slugs: processing, pending, on-hold, completed, cancelled, refunded. Use all (or omit status) to get the breakdown across every status. For an actual list of orders use list-orders instead; to find one customer\'s orders use find-customer-orders. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'status' => [
							'type'        => 'string',
							'description' => __( 'A WooCommerce order status slug. Use all for the breakdown across every status, or name one such as processing, pending, completed, cancelled or refunded', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'status' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'total'     => [ 'type' => 'integer' ],
						'breakdown' => [ 'type' => 'array' ],
						'orders'    => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ QueryOrders::class, 'run' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/update-order-status',
			[
				'label'               => __( 'Change order status', 'moksa-for-woocommerce' ),
				'description'         => __( 'Move an order to a new status such as processing, completed or cancelled. This is a destructive action — calling the tool only proposes the change, and nothing happens until the user presses Confirm, so there is no need to ask for confirmation yourself.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order'  => [
							'type'        => 'string',
							'description' => __( 'The order number, for example 2896', 'moksa-for-woocommerce' ),
						],
						'status' => [
							'type'        => 'string',
							'description' => __( 'The target status slug, such as processing, completed, cancelled, on-hold or refunded', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order', 'status' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'summary' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ UpdateOrderStatus::class, 'prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( UpdateOrderStatus::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		self::register_batch_status();
		self::register_invoice_ops();
		self::register_print_label();
		self::register_add_note();
		self::register_create_shipment();
		self::register_change_pickup_store();
		self::register_shipment_records();
		self::register_query_payment();
		self::register_channel_and_donation();
		self::register_allowance_and_list();
		self::register_payment_methods();
		self::register_invoice_channels();
		self::register_email_and_tracking();
		self::register_status_and_settings();
		self::register_shipping_zones();
		self::register_batch_ship_and_reports();
	}

	private static function register_batch_ship_and_reports(): void {
		wp_register_ability(
			'moksa-for-woocommerce/batch-create-shipment',
			[
				'label'               => __( 'Create shipments in bulk', 'moksa-for-woocommerce' ),
				'description'         => __( 'Create shipments for several orders at once and get their tracking numbers, choosing the carrier automatically from each order\'s shipping method. This is destructive and cannot be undone — calling the tool only proposes it, and nothing is created until the user presses Confirm, so there is no need to ask for confirmation yourself.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'orders' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'An array of order numbers, for example ["2867","2664"]', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'orders' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ ShipmentOps::class, 'batch_prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( ShipmentOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/find-customer-orders',
			[
				'label'               => __( 'Find a customer\'s orders', 'moksa-for-woocommerce' ),
				'description'         => __( 'Look up every order belonging to a customer by email address, phone number or name, and return the list along with the total amount paid. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'query' => [
							'type'        => 'string',
							'description' => __( 'The customer\'s email address, phone number or name', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'query' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'orders' => [ 'type' => 'array' ] ],
				],
				'execute_callback'    => [ FindCustomerOrders::class, 'execute' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/sales-summary',
			[
				'label'               => __( 'Revenue and order statistics', 'moksa-for-woocommerce' ),
				'description'         => __( 'Order count, paid revenue, status breakdown and average order value for a period. The period is optional and defaults to this_month: today, yesterday, this_month, last_month, this_year or custom. With custom, also pass date_from and date_to in YYYY-MM-DD format. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'period'    => [
							'type'        => 'string',
							'enum'        => [ 'today', 'yesterday', 'this_month', 'last_month', 'this_year', 'custom' ],
							'default'     => 'this_month',
							'description' => __( 'The period. Optional, defaults to this_month. One of today, yesterday, this_month, last_month, this_year or custom', 'moksa-for-woocommerce' ),
						],
						'date_from' => [
							'type'        => 'string',
							'description' => __( 'Start date in YYYY-MM-DD format, used with the custom period', 'moksa-for-woocommerce' ),
						],
						'date_to'   => [
							'type'        => 'string',
							'description' => __( 'End date in YYYY-MM-DD format, used with the custom period', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'total_orders' => [ 'type' => 'integer' ],
						'revenue'      => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ SalesSummary::class, 'execute' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_shipping_zones(): void {
		wp_register_ability(
			'moksa-for-woocommerce/list-shipping-zones',
			[
				'label'               => __( 'List shipping zones and methods', 'moksa-for-woocommerce' ),
				'description'         => __( 'List every shipping method in the WooCommerce shipping zones, such as 7-ELEVEN pickup or T-Cat ambient and frozen delivery, along with whether each is enabled. Use it to confirm a method name before switching it. No parameters. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'scope' => [
							'type'        => 'string',
							'default'     => 'all',
							'description' => __( 'Optional, can be omitted', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'zones' => [ 'type' => 'array' ] ],
				],
				'execute_callback'    => [ ShippingZoneOps::class, 'list_zones' ],
				'permission_callback' => static function (): bool {
					return current_user_can( ShippingZoneOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/toggle-shipping-method',
			[
				'label'               => __( 'Enable or disable a shipping method', 'moksa-for-woocommerce' ),
				'description'         => __( 'Enable or disable one shipping method in a WooCommerce shipping zone, for example “SmilePay T-Cat ambient” or “ECPay 7-ELEVEN pickup”. Pass the method name directly. This is a destructive action because it changes what customers can choose at checkout — calling the tool only proposes it, and nothing takes effect until the user presses Confirm, so there is no need to ask for confirmation yourself.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'method' => [
							'type'        => 'string',
							'description' => __( 'The shipping method name, for example SmilePay T-Cat ambient', 'moksa-for-woocommerce' ),
						],
						'enable' => [
							'type'        => 'boolean',
							'description' => __( 'true to enable, false to disable', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'method', 'enable' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ ShippingZoneOps::class, 'toggle_prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( ShippingZoneOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_status_and_settings(): void {
		wp_register_ability(
			'moksa-for-woocommerce/get-payment-status',
			[
				'label'               => __( 'Check payment status', 'moksa-for-woocommerce' ),
				'description'         => __( 'Check the payment status of an order: payment method, whether it is paid, transaction ID, last four card digits, ATM virtual account and convenience store payment code. NewebPay orders are also queried live against NewebPay. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( 'Order number', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'paid'           => [ 'type' => 'boolean' ],
						'payment_method' => [ 'type' => 'string' ],
						'transaction_no' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ PaymentStatus::class, 'execute' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/get-plugin-settings',
			[
				'label'               => __( 'Summarize plugin settings', 'moksa-for-woocommerce' ),
				'description'         => __( 'Summarize the current non-sensitive settings: which payment, shipping and e-invoice channels are enabled, whether each module is in test or production mode, when invoices are issued, which fields order number lookup searches, and whether the AI assistant is on. No credentials are included. No parameters. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'scope' => [
							'type'        => 'string',
							'default'     => 'all',
							'description' => __( 'Optional, can be omitted', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'channels' => [ 'type' => 'array' ] ],
				],
				'execute_callback'    => [ PluginSettings::class, 'execute' ],
				'permission_callback' => static function (): bool {
					return current_user_can( PluginSettings::CAP );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_email_and_tracking(): void {
		wp_register_ability(
			'moksa-for-woocommerce/resend-payment-email',
			[
				'label'               => __( 'Resend the payment details email', 'moksa-for-woocommerce' ),
				'description'         => __( 'Resend the payment details email, such as the ATM virtual account or convenience store payment code, to the customer. Only orders that have those details can be sent; card orders have none. This is a destructive action because it emails the customer — calling the tool only proposes it, and nothing is sent until the user presses Confirm, so there is no need to ask for confirmation yourself.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( 'Order number', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ ResendPaymentEmail::class, 'prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( ResendPaymentEmail::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/get-tracking-link',
			[
				'label'               => __( 'Get tracking links', 'moksa-for-woocommerce' ),
				'description'         => __( 'Get the shipment tracking links for an order: each carrier\'s public tracking page plus the tracking number. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( 'Order number', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'links' => [ 'type' => 'array' ] ],
				],
				'execute_callback'    => [ TrackingLookup::class, 'execute' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_invoice_channels(): void {
		wp_register_ability(
			'moksa-for-woocommerce/list-invoice-channels',
			[
				'label'               => __( 'List invoice issuing options', 'moksa-for-woocommerce' ),
				'description'         => __( 'List the issuing options an e-invoice service (ECPay, ezPay, SmilePay, PayNow or Amego) supports — member carrier, mobile barcode, Citizen Digital Certificate, paper, donation and tax ID — along with whether each is enabled. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'provider' => [
							'type'        => 'string',
							'description' => __( 'The e-invoice module name or slug, for example ECPay or ecpay', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'provider' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'channels' => [ 'type' => 'array' ] ],
				],
				'execute_callback'    => [ InvoiceChannelOps::class, 'list_channels' ],
				'permission_callback' => static function (): bool {
					return current_user_can( InvoiceChannelOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/toggle-invoice-channel',
			[
				'label'               => __( 'Enable or disable invoice issuing options', 'moksa-for-woocommerce' ),
				'description'         => __( 'Enable or disable one or more issuing options for an e-invoice service: member carrier, mobile barcode, Citizen Digital Certificate, paper, donation or tax ID. Pass the option names directly. This is a destructive action — calling the tool only proposes it, and nothing takes effect until the user presses Confirm, so there is no need to ask for confirmation yourself.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'provider' => [
							'type'        => 'string',
							'description' => __( 'The e-invoice module name or slug, for example ECPay or ecpay', 'moksa-for-woocommerce' ),
						],
						'channels' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'An array of issuing option names, for example ["Mobile barcode","Donation"]', 'moksa-for-woocommerce' ),
						],
						'enable'   => [
							'type'        => 'boolean',
							'description' => __( 'true to enable, false to disable', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'provider', 'channels', 'enable' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ InvoiceChannelOps::class, 'toggle_prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( InvoiceChannelOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_payment_methods(): void {
		wp_register_ability(
			'moksa-for-woocommerce/list-payment-methods',
			[
				'label'               => __( 'List payment methods', 'moksa-for-woocommerce' ),
				'description'         => __( 'List every individual payment method offered by a payment service (ECPay, NewebPay, PAYUNi, SmilePay, PayNow or PChomePay) and whether each is enabled. Use it to confirm what is available before switching anything. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'provider' => [
							'type'        => 'string',
							'description' => __( 'The payment service name or slug, for example ECPay or ecpay', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'provider' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'methods' => [ 'type' => 'array' ] ],
				],
				'execute_callback'    => [ PaymentMethodOps::class, 'list_methods' ],
				'permission_callback' => static function (): bool {
					return current_user_can( PaymentMethodOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/toggle-payment-method',
			[
				'label'               => __( 'Enable or disable payment methods', 'moksa-for-woocommerce' ),
				'description'         => __( 'Enable or disable one or more individual payment methods within a payment service, such as ECPay\'s one-off credit card payment, Apple Pay or ATM. Pass the method names directly. This is a destructive action — calling the tool only proposes it, and nothing takes effect until the user presses Confirm, so there is no need to ask for confirmation yourself.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'provider' => [
							'type'        => 'string',
							'description' => __( 'The payment service name or slug, for example ECPay or ecpay', 'moksa-for-woocommerce' ),
						],
						'methods'  => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'An array of payment method names, for example ["Credit card, pay in full","Apple Pay"]', 'moksa-for-woocommerce' ),
						],
						'enable'   => [
							'type'        => 'boolean',
							'description' => __( 'true to enable, false to disable', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'provider', 'methods', 'enable' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ PaymentMethodOps::class, 'toggle_prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( PaymentMethodOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_allowance_and_list(): void {
		wp_register_ability(
			'moksa-for-woocommerce/issue-allowance',
			[
				'label'               => __( 'Issue an invoice allowance', 'moksa-for-woocommerce' ),
				'description'         => __( 'Issue an allowance against an order\'s existing e-invoice, used for partial refunds. An amount is required. Only ECPay, ezPay and Amego support this. It is destructive and cannot be undone — calling the tool only proposes it, and nothing is issued until the user presses Confirm, so there is no need to ask for confirmation yourself.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order'  => [
							'type'        => 'string',
							'description' => __( 'Order number', 'moksa-for-woocommerce' ),
						],
						'amount' => [
							'type'        => 'integer',
							'description' => __( 'The allowance amount as a whole number, which cannot exceed the order total', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order', 'amount' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ InvoiceOps::class, 'allowance_prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( InvoiceOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/list-orders',
			[
				'label'               => __( 'Advanced order list', 'moksa-for-woocommerce' ),
				'description'         => __( 'Return an actual list of orders, filtered by status, date range (date_from and date_to in YYYY-MM-DD format) and payment method. The list is compact and returns 20 orders by default, up to a maximum of 50. If you only need counts per status, use query-orders instead. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'status'         => [
							'type'        => 'string',
							'description' => __( 'An order status slug such as processing or completed. Omit it for every status', 'moksa-for-woocommerce' ),
						],
						'date_from'      => [
							'type'        => 'string',
							'description' => __( 'Start date in YYYY-MM-DD format', 'moksa-for-woocommerce' ),
						],
						'date_to'        => [
							'type'        => 'string',
							'description' => __( 'End date in YYYY-MM-DD format', 'moksa-for-woocommerce' ),
						],
						'payment_method' => [
							'type'        => 'string',
							'description' => __( 'Payment method ID (optional)', 'moksa-for-woocommerce' ),
						],
						'limit'          => [
							'type'        => 'integer',
							'description' => __( 'How many orders to return. Defaults to 20, maximum 50', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'count'  => [ 'type' => 'integer' ],
						'orders' => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ ListOrders::class, 'run' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_channel_and_donation(): void {
		wp_register_ability(
			'moksa-for-woocommerce/list-channels',
			[
				'label'               => __( 'List payment, shipping and e-invoice channels', 'moksa-for-woocommerce' ),
				'description'         => __( 'List the payment, shipping and e-invoice channels with their slugs and whether each is enabled. Use all for everything, or filter with payment, shipping or invoice. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'category' => [
							'type'        => 'string',
							'enum'        => [ 'all', 'payment', 'shipping', 'invoice' ],
							'default'     => 'all',
							'description' => __( 'Optional, defaults to all. Or filter with payment, shipping or invoice', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'channels' => [ 'type' => 'array' ] ],
				],
				'execute_callback'    => [ ChannelOps::class, 'list_channels' ],
				'permission_callback' => static function (): bool {
					return current_user_can( ChannelOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/toggle-channel',
			[
				'label'               => __( 'Enable or disable a channel', 'moksa-for-woocommerce' ),
				'description'         => __( 'Enable or disable a payment, shipping or e-invoice channel. Pass the channel name (such as “SmilePay shipping”) or its slug directly; there is no need to list them first. This is a destructive action, because disabling a channel changes what customers can choose at checkout and enabling one without credentials causes errors on the storefront — calling the tool only proposes it, and nothing takes effect until the user presses Confirm, so there is no need to ask for confirmation yourself. It does not touch credentials or the test and production switch.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'channel' => [
							'type'        => 'string',
							'description' => __( 'The channel name or slug, for example “SmilePay shipping” or smilepay_shipping', 'moksa-for-woocommerce' ),
						],
						'enable'  => [
							'type'        => 'boolean',
							'description' => __( 'true to enable, false to disable', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'channel', 'enable' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ ChannelOps::class, 'toggle_prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( ChannelOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/add-donation-org',
			[
				'label'               => __( 'Add an invoice donation charity', 'moksa-for-woocommerce' ),
				'description'         => __( 'Add a charity, with its name and love code, to the donation list of the enabled e-invoice module so customers can choose it at checkout. A love code is 3 to 7 digits. Calling the tool only proposes it, and nothing is saved until the user presses Confirm, so there is no need to ask for confirmation yourself.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'name' => [
							'type'        => 'string',
							'description' => __( 'The name of the charity', 'moksa-for-woocommerce' ),
						],
						'code' => [
							'type'        => 'string',
							'description' => __( 'The love code, 3 to 7 digits', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'name', 'code' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ DonationOrgOps::class, 'prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( DonationOrgOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_create_shipment(): void {
		wp_register_ability(
			'moksa-for-woocommerce/create-shipment',
			[
				'label'               => __( 'Create a shipment', 'moksa-for-woocommerce' ),
				'description'         => __( 'Create a shipment with the carrier for an order and get its tracking number, choosing the carrier automatically from the order\'s shipping method. This is destructive and cannot be undone, because it places a real booking with the carrier — calling the tool only proposes it, and nothing is created until the user presses Confirm, so there is no need to ask for confirmation yourself. A label can only be printed once the shipment exists.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( 'The order number, for example 2896', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ ShipmentOps::class, 'prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( ShipmentOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_query_payment(): void {
		wp_register_ability(
			'moksa-for-woocommerce/query-payment',
			[
				'label'               => __( 'Look the payment up at the provider', 'moksa-for-woocommerce' ),
				'description'         => __( 'Ask the payment provider directly what happened to the transaction on an order — whether it was authorised, how much was captured and when. Use this when the order and the provider might disagree, for example when a customer says they paid but the order still shows unpaid. Works for ECPay card payments and PAYUNi. For what this site already recorded, use get-payment-status instead, which does not call the provider. Read-only.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( 'The order number, for example 2896', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'provider' => [ 'type' => 'string' ],
						'details'  => [ 'type' => 'object' ],
					],
				],
				'execute_callback'    => [ QueryPayment::class, 'run' ],
				'permission_callback' => static function (): bool {
					return current_user_can( QueryPayment::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_shipment_records(): void {
		wp_register_ability(
			'moksa-for-woocommerce/list-shipments',
			[
				'label'               => __( 'List the shipments on an order', 'moksa-for-woocommerce' ),
				'description'         => __( 'List the shipments already created for an order, with their shipment numbers, tracking numbers and when they were created. Use this before deleting a shipment so you know which number to give.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( 'The order number, for example 2896', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'count'   => [ 'type' => 'integer' ],
						'records' => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ ShipmentRecordOps::class, 'list_records' ],
				'permission_callback' => static function (): bool {
					return current_user_can( ShipmentRecordOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/delete-shipment',
			[
				'label'               => __( 'Delete a shipment from an order', 'moksa-for-woocommerce' ),
				'description'         => __( 'Remove a shipment record from an order so the shipment can be created again — for example after the pickup store was changed. This only removes the record on this site; it does not cancel the booking with the carrier, so never use it for a parcel that is already on its way. This is a destructive action — calling the tool only proposes it, and nothing is deleted until the user presses Confirm, so there is no need to ask for confirmation yourself. If the order has more than one shipment you must say which shipment number to delete.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order'           => [
							'type'        => 'string',
							'description' => __( 'The order number, for example 2896', 'moksa-for-woocommerce' ),
						],
						'shipment_number' => [
							'type'        => 'string',
							'description' => __( 'Which shipment to delete. Can be left out only when the order has exactly one shipment.', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ ShipmentRecordOps::class, 'prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( ShipmentRecordOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_change_pickup_store(): void {
		wp_register_ability(
			'moksa-for-woocommerce/change-pickup-store',
			[
				'label'               => __( 'Change the pickup store', 'moksa-for-woocommerce' ),
				'description'         => __( 'Change which convenience store an order is picked up at, by store number. Use this when a customer asks to collect the parcel somewhere else. This is a destructive action — calling the tool only proposes the change, and nothing happens until the user presses Confirm, so there is no need to ask for confirmation yourself. It does not re-book the shipment: if a shipment was already created it still points at the old store and has to be deleted and created again on the order screen. If the store name and address are not given they are cleared, because keeping the old ones would show the new number next to the old store name.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order'         => [
							'type'        => 'string',
							'description' => __( 'The order number, for example 2896', 'moksa-for-woocommerce' ),
						],
						'store_id'      => [
							'type'        => 'string',
							'description' => __( 'The number of the store to switch to, for example 131386', 'moksa-for-woocommerce' ),
						],
						'store_name'    => [
							'type'        => 'string',
							'description' => __( 'The store name, if the user gave one. Leave it out rather than guessing — a wrong name is worse than none.', 'moksa-for-woocommerce' ),
						],
						'store_address' => [
							'type'        => 'string',
							'description' => __( 'The store address, if the user gave one. Leave it out rather than guessing.', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order', 'store_id' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ ChangePickupStore::class, 'prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( ChangePickupStore::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_add_note(): void {
		wp_register_ability(
			'moksa-for-woocommerce/add-order-note',
			[
				'label'               => __( 'Add an order note', 'moksa-for-woocommerce' ),
				'description'         => __( 'Add an internal note to an order. It is for your own records and is not sent to the customer.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( 'Order number', 'moksa-for-woocommerce' ),
						],
						'note'  => [
							'type'        => 'string',
							'description' => __( 'The note', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order', 'note' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'ok'      => [ 'type' => 'boolean' ],
						'message' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_add_note' ],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_shop_orders' );
				},
				'meta'                => [
					'show_in_rest' => true,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	/**
	 * @param mixed $input { order: string, note: string }。
	 * @return array<string,mixed>
	 */
	public static function execute_add_note( $input ): array {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return [
				'ok'      => false,
				'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ),
			];
		}
		$ref   = is_array( $input ) && isset( $input['order'] ) ? (string) $input['order'] : '';
		$note  = is_array( $input ) && isset( $input['note'] ) ? trim( sanitize_textarea_field( (string) $input['note'] ) ) : '';
		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return [
				'ok'      => false,
				'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ),
			];
		}
		if ( '' === $note ) {
			return [
				'ok'      => false,
				'message' => __( 'The note cannot be empty.', 'moksa-for-woocommerce' ),
			];
		}
		$order->add_order_note( $note );
		return [
			'ok'      => true,
			/* translators: %s: order number */
			'message' => sprintf( __( 'A note was added to order #%s.', 'moksa-for-woocommerce' ), $order->get_order_number() ),
		];
	}

	private static function register_print_label(): void {
		wp_register_ability(
			'moksa-for-woocommerce/print-shipping-label',
			[
				'label'               => __( 'Print shipping labels', 'moksa-for-woocommerce' ),
				'description'         => __( 'Print shipping labels for one or more orders, grouped automatically by carrier. Calling the tool only proposes it; the print page opens once the user presses Confirm. An order needs an existing shipment before it has a label to print.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'orders' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'An array of order numbers to print, for example ["2867","2664"]', 'moksa-for-woocommerce' ),
						],
						'paper'  => [
							'type'        => 'string',
							'description' => __( 'Paper: 1 for A4 (the default) or 2 for an A6 label printer', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'orders' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ PrintShippingLabel::class, 'prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( PrintShippingLabel::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_invoice_ops(): void {
		wp_register_ability(
			'moksa-for-woocommerce/issue-invoice',
			[
				'label'               => __( 'Issue an e-invoice', 'moksa-for-woocommerce' ),
				'description'         => __( 'Issue an e-invoice for an order, using the invoice service and invoice type already set on it. This is a destructive tax operation that cannot be undone — calling the tool only proposes it, and nothing is issued until the user presses Confirm, so there is no need to ask for confirmation yourself.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( 'The order number, for example 2896', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ InvoiceOps::class, 'issue_prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( InvoiceOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);

		wp_register_ability(
			'moksa-for-woocommerce/void-invoice',
			[
				'label'               => __( 'Void an e-invoice', 'moksa-for-woocommerce' ),
				'description'         => __( 'Void the e-invoice already issued for an order. A reason is required. This is a destructive tax operation that cannot be undone — calling the tool only proposes it, and nothing is voided until the user presses Confirm, so there is no need to ask for confirmation yourself.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order'  => [
							'type'        => 'string',
							'description' => __( 'The order number, for example 2896', 'moksa-for-woocommerce' ),
						],
						'reason' => [
							'type'        => 'string',
							'description' => __( 'The reason for voiding, for example a customer cancellation or an issuing mistake', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'order', 'reason' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'summary' => [ 'type' => 'string' ] ],
				],
				'execute_callback'    => [ InvoiceOps::class, 'void_prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( InvoiceOps::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	private static function register_batch_status(): void {
		wp_register_ability(
			'moksa-for-woocommerce/batch-update-order-status',
			[
				'label'               => __( 'Change order status in bulk', 'moksa-for-woocommerce' ),
				'description'         => __( 'Move several orders to the same new status at once, such as processing, completed or cancelled. This is a destructive action — calling the tool only proposes the change, and nothing happens until the user presses Confirm, so there is no need to ask for confirmation yourself. Use this whenever the user mentions two or more orders together.', 'moksa-for-woocommerce' ),
				'category'            => 'moksa-for-woocommerce',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'orders' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'An array of order numbers to change, for example ["2896","2897","2900"]', 'moksa-for-woocommerce' ),
						],
						'status' => [
							'type'        => 'string',
							'description' => __( 'The target status slug, such as processing, completed, cancelled, on-hold or refunded', 'moksa-for-woocommerce' ),
						],
					],
					'required'             => [ 'orders', 'status' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'summary' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ BatchUpdateOrderStatus::class, 'prepare' ],
				'permission_callback' => static function (): bool {
					return current_user_can( BatchUpdateOrderStatus::CAP );
				},
				'meta'                => [
					'show_in_rest' => false,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
						'type'   => 'tool',
					],
				],
			]
		);
	}

	/**
	 * @param mixed $input ability 輸入（{ number: string }）。
	 * @return array<int, array<string, mixed>>
	 */
	public static function execute( $input ): array {
		$number = is_array( $input ) && isset( $input['number'] ) ? (string) $input['number'] : '';
		return [ 'orders' => OrderNumberLookup::resolve( $number, 10 ) ];
	}

	/**
	 * @param mixed $input ability 輸入（{ order: string }）。
	 * @return array<string, mixed> 訂單明細，找不到回空陣列。
	 */
	public static function execute_details( $input ): array {
		$order   = is_array( $input ) && isset( $input['order'] ) ? (string) $input['order'] : '';
		$details = OrderDetails::resolve( $order );
		return null === $details ? [] : $details;
	}

	/** @var array<string,bool> 本次請求被隱藏出 MCP 的破壞性能力清單（由 gate_mcp_exposure 填）。 */
	private static $mcp_hidden = array();

	/**
	 * 是否允許破壞性能力暴露給外部 MCP。預設否 —— 對齊官方「公開 MCP 端點優先唯讀」建議。
	 */
	private static function expose_destructive(): bool {
		return 'yes' === get_option( 'moksafowo_ai_mcp_expose_destructive', 'no' );
	}

	/**
	 * 註冊時 gate MCP 暴露：設定關閉時，把破壞性 moksa-for-woocommerce 能力的 meta.mcp.public 設為
	 * false，並記錄供 deprecated WC endpoint 同步排除。
	 *
	 * **核心不讀 `meta.mcp.public`** —— WP 7.1 的 Abilities API 對 `meta['mcp']` 零引用，
	 * 核心的 `meta['public']` 只用來 seed `show_in_rest`，而我們每個 ability 都顯式寫了
	 * `show_in_rest`，所以那條路輪不到它。
	 *
	 * **但讀它的不只我們自己。** 官方 MCP Adapter（`WordPress/mcp-adapter`，非核心）的
	 * `McpAbilityExposure::is_meta_public()` 第一件事就是讀 `meta.mcp.public`，而且它
	 * **優先於** `meta.public`：`false` 會把一個 public 的能力擋在 MCP 外，`true` 會把
	 * private 的放進去。也就是說站台一旦裝了官方 adapter，這道 gate 就是唯一擋住那 15 個
	 * 破壞性能力被暴露出去的東西 —— **不要因為「好像只有自己讀」就把它拿掉**，
	 * 也別把這個鍵換成核心的 `public`（那會同時廢掉我們自己的 server 與 adapter 兩道）。
	 * 破壞性能力即使暴露也只會「提議」（execute_callback 一律是 propose-only 的 *prepare），
	 * 真正變更在非-ability 的 *apply（僅站內確認 REST 可達）。此 gate 為防禦縱深。
	 *
	 * @param mixed $args ability 註冊參數。
	 * @param mixed $name ability 名稱。
	 * @return mixed
	 */
	public static function gate_mcp_exposure( $args, $name ) {
		if ( ! is_string( $name ) || 0 !== strpos( $name, 'moksa-for-woocommerce/' ) || ! is_array( $args ) ) {
			return $args;
		}
		$destructive = ! empty( $args['meta']['annotations']['destructive'] );
		if ( $destructive && ! self::expose_destructive() ) {
			$args['meta']['mcp']['public'] = false;
			self::$mcp_hidden[ $name ]     = true;
		}
		return $args;
	}

	/**
	 * 把本外掛的 ability 納入 WooCommerce 的（deprecated）MCP server（非 woocommerce/ 前綴需明確納入）。
	 * 與 gate_mcp_exposure 同步：被隱藏的破壞性能力不納入。
	 *
	 * @param mixed  $include    WC 是否納入該 ability。
	 * @param string $ability_id ability id。
	 * @return mixed
	 */
	public static function include_in_mcp( $include, $ability_id ) {
		if ( is_string( $ability_id ) && 0 === strpos( $ability_id, 'moksa-for-woocommerce/' ) ) {
			return empty( self::$mcp_hidden[ $ability_id ] );
		}
		return $include;
	}
}
