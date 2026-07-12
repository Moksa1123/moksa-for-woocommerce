<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress Abilities API — mo-ectools/find-order-by-number。
 *
 * 唯讀 ability：用發票 / 物流 / 金流號碼查訂單。供 WP 命令面板、REST、
 * 以及（商家開啟 WC MCP 後）AI 共用。需 WP 6.9+ 核心 Abilities API。
 */
final class Ability {

	const ABILITY = 'mo-ectools/find-order-by-number';

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'mo-ectools' ) ) {
			return;
		}
		wp_register_ability_category(
			'mo-ectools',
			[
				'label'       => __( 'Moksa for WooCommerce', 'mo-ectools' ),
				'description' => __( '台灣金流 / 物流 / 電子發票能力', 'mo-ectools' ),
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
				'label'               => __( '依號碼查訂單', 'mo-ectools' ),
				'description'         => __( '用電子發票號碼 / 物流單號 / 金流交易序號查詢符合的 WooCommerce 訂單，回傳訂單編號、買家、狀態與後台連結。直接給訂單編號（如 2855）即可查該訂單狀態。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'number' => [
							'type'        => 'string',
							'description' => __( '要查的訂單編號，或發票 / 物流 / 金流號碼', 'mo-ectools' ),
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
			'mo-ectools/get-order-details',
			[
				'label'               => __( '查訂單明細', 'mo-ectools' ),
				'description'         => __( '取單筆訂單的完整明細：狀態、買家、品項、金額、付款方式、配送方式、取貨門市，以及電子發票號碼（含是否已開立）、物流單號、金流交易序號。用來回答「這筆發票開了嗎 / 物流單號多少 / 取哪間門市」。可給訂單編號或任一號碼。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( '訂單編號，或發票 / 物流 / 金流號碼', 'mo-ectools' ),
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
			'mo-ectools/query-orders',
			[
				'label'               => __( '查訂單數量 / 狀態', 'mo-ectools' ),
				'description'         => __( '查訂單「數量 / 統計」:回各狀態筆數分布,或某狀態的訂單筆數。常見狀態 slug:processing(處理中 / 待出貨)、pending(待付款)、on-hold(保留中)、completed(已完成)、cancelled(已取消)、refunded(已退款)。status 用 all(或省略)回各狀態分布。要實際訂單清單請改用 list-orders;查某顧客的訂單用 find-customer-orders。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'status' => [
							'type'        => 'string',
							'description' => __( 'WooCommerce 訂單狀態 slug;用 all 取得各狀態筆數分布,或指定如 processing(處理中 / 待出貨)、pending(待付款)、completed(已完成)、cancelled(已取消)、refunded(已退款)', 'mo-ectools' ),
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
			'mo-ectools/update-order-status',
			[
				'label'               => __( '更改訂單狀態', 'mo-ectools' ),
				'description'         => __( '把指定訂單改成新的狀態(如 processing 處理中、completed 已完成、cancelled 已取消)。這是破壞性操作 —— 呼叫此工具只會「提出」變更,系統會要求使用者按「確認執行」後才真正變更,你不需要自己再追問確認。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order'  => [
							'type'        => 'string',
							'description' => __( '訂單編號(如 2896)', 'mo-ectools' ),
						],
						'status' => [
							'type'        => 'string',
							'description' => __( '目標狀態 slug,如 processing / completed / cancelled / on-hold / refunded', 'mo-ectools' ),
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
			'mo-ectools/batch-create-shipment',
			[
				'label'               => __( '批次建立託運單', 'mo-ectools' ),
				'description'         => __( '一次為「多筆」訂單建立託運單並取得物流單號(依各訂單運送方式自動判斷物流商)。破壞性、不可逆 —— 呼叫只會「提出」,使用者按「確認執行」後才建單,你不需要自己再追問。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'orders' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( '訂單編號陣列(如 ["2867","2664"])', 'mo-ectools' ),
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
			'mo-ectools/find-customer-orders',
			[
				'label'               => __( '查顧客訂單', 'mo-ectools' ),
				'description'         => __( '用顧客的 email、電話或姓名查他的所有訂單(回傳訂單清單與已付款累計)。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'query' => [
							'type'        => 'string',
							'description' => __( '顧客 email / 電話 / 姓名', 'mo-ectools' ),
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
			'mo-ectools/sales-summary',
			[
				'label'               => __( '營收訂單統計', 'mo-ectools' ),
				'description'         => __( '某期間的訂單數、已付款營收、各狀態分布、平均客單價。period 選填(預設本月 this_month):today / yesterday / this_month / last_month / this_year / custom(custom 時再給 date_from、date_to,格式 YYYY-MM-DD)。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'period'    => [
							'type'        => 'string',
							'enum'        => [ 'today', 'yesterday', 'this_month', 'last_month', 'this_year', 'custom' ],
							'default'     => 'this_month',
							'description' => __( '期間(選填,預設 this_month)。今天=today、昨天=yesterday、本月=this_month、上個月=last_month、今年=this_year、自訂區間=custom', 'mo-ectools' ),
						],
						'date_from' => [
							'type'        => 'string',
							'description' => __( 'custom 時的起始日期 YYYY-MM-DD', 'mo-ectools' ),
						],
						'date_to'   => [
							'type'        => 'string',
							'description' => __( 'custom 時的結束日期 YYYY-MM-DD', 'mo-ectools' ),
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
			'mo-ectools/list-shipping-zones',
			[
				'label'               => __( '列出運送區域與方式', 'mo-ectools' ),
				'description'         => __( '列出 WooCommerce 運送區域內所有運送方式(7-11 取貨 / 黑貓常溫冷凍 等)與啟用狀態。切換前先用這個確認方式名稱。免參數。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'scope' => [
							'type'        => 'string',
							'default'     => 'all',
							'description' => __( '選填,可省略', 'mo-ectools' ),
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
			'mo-ectools/toggle-shipping-method',
			[
				'label'               => __( '啟用/停用運送方式', 'mo-ectools' ),
				'description'         => __( '啟用或停用 WooCommerce 運送區域內的某個運送方式(如「速買配 黑貓常溫」「綠界 7-11 取貨」)。method 直接給方式名稱即可。這是破壞性操作(影響前台結帳可選的運送方式)—— 呼叫只會「提出」,使用者按「確認執行」後才生效,你不需要自己再追問確認。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'method' => [
							'type'        => 'string',
							'description' => __( '運送方式名稱(如 速買配 黑貓常溫)', 'mo-ectools' ),
						],
						'enable' => [
							'type'        => 'boolean',
							'description' => __( 'true=啟用、false=停用', 'mo-ectools' ),
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
			'mo-ectools/get-payment-status',
			[
				'label'               => __( '查付款狀態', 'mo-ectools' ),
				'description'         => __( '查某訂單的付款狀態:付款方式、是否已付款、交易序號、卡末四、ATM 虛擬帳號、超商繳費代碼。藍新金流訂單會額外向藍新做即時查詢(B02)。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( '訂單編號', 'mo-ectools' ),
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
			'mo-ectools/get-plugin-settings',
			[
				'label'               => __( '彙整外掛設定', 'mo-ectools' ),
				'description'         => __( '彙整目前非敏感設定:哪些金流/物流/發票管道啟用、各模組測試或正式模式、發票開立時機、訂單查號可搜尋欄位、AI 助手是否啟用。不含任何憑證。免參數。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'scope' => [
							'type'        => 'string',
							'default'     => 'all',
							'description' => __( '選填,可省略', 'mo-ectools' ),
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
			'mo-ectools/resend-payment-email',
			[
				'label'               => __( '重寄付款資訊信', 'mo-ectools' ),
				'description'         => __( '重寄付款資訊信(ATM 虛擬帳號 / 超商繳費代碼等)給訂單的顧客。只有有這類付款資訊的訂單可寄(信用卡訂單沒有)。這是破壞性操作(會寄信給顧客)—— 呼叫只會「提出」,使用者按「確認執行」後才寄出,你不需要自己再追問確認。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( '訂單編號', 'mo-ectools' ),
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
			'mo-ectools/get-tracking-link',
			[
				'label'               => __( '取貨態追蹤連結', 'mo-ectools' ),
				'description'         => __( '取某訂單的物流貨態追蹤連結(各家物流的公開查詢頁 + 物流單號)。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( '訂單編號', 'mo-ectools' ),
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
			'mo-ectools/list-invoice-channels',
			[
				'label'               => __( '列出發票開立方式', 'mo-ectools' ),
				'description'         => __( '列出某家電子發票(綠界/ezPay/速買配/PayNow/Amego)支援的開立方式(會員載具/手機條碼/自然人憑證/紙本/捐贈/統編)與啟用狀態。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'provider' => [
							'type'        => 'string',
							'description' => __( '發票模組名稱或 slug(如 綠界 / ecpay)', 'mo-ectools' ),
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
			'mo-ectools/toggle-invoice-channel',
			[
				'label'               => __( '啟用/停用發票開立方式', 'mo-ectools' ),
				'description'         => __( '啟用或停用某家電子發票的一或多種開立方式(會員載具、手機條碼、自然人憑證、紙本、捐贈、統編)。channels 直接給名稱即可。這是破壞性操作 —— 呼叫只會「提出」,使用者按「確認執行」後才生效,你不需要自己再追問確認。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'provider' => [
							'type'        => 'string',
							'description' => __( '發票模組名稱或 slug(如 綠界 / ecpay)', 'mo-ectools' ),
						],
						'channels' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( '開立方式名稱陣列(如 ["手機條碼","捐贈"])', 'mo-ectools' ),
						],
						'enable'   => [
							'type'        => 'boolean',
							'description' => __( 'true=啟用、false=停用', 'mo-ectools' ),
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
			'mo-ectools/list-payment-methods',
			[
				'label'               => __( '列出金流付款方式', 'mo-ectools' ),
				'description'         => __( '列出某個金流(綠界/藍新/PAYUNi/速買配/PayNow/PChomePay)所有「個別付款方式」與啟用狀態。切換前先用這個確認有哪些方式。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'provider' => [
							'type'        => 'string',
							'description' => __( '金流名稱或 slug(如 綠界 / ecpay)', 'mo-ectools' ),
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
			'mo-ectools/toggle-payment-method',
			[
				'label'               => __( '啟用/停用金流付款方式', 'mo-ectools' ),
				'description'         => __( '啟用或停用某個金流裡的一或多個「個別付款方式」(如綠界的信用卡一次付清、Apple Pay、ATM)。methods 直接給方式名稱即可。這是破壞性操作 —— 呼叫只會「提出」,使用者按「確認執行」後才生效,你不需要自己再追問確認。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'provider' => [
							'type'        => 'string',
							'description' => __( '金流名稱或 slug(如 綠界 / ecpay)', 'mo-ectools' ),
						],
						'methods'  => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( '付款方式名稱陣列(如 ["信用卡一次付清","Apple Pay"])', 'mo-ectools' ),
						],
						'enable'   => [
							'type'        => 'boolean',
							'description' => __( 'true=啟用、false=停用', 'mo-ectools' ),
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
			'mo-ectools/issue-allowance',
			[
				'label'               => __( '開立發票折讓單', 'mo-ectools' ),
				'description'         => __( '為訂單已開立的電子發票開立折讓單(部分退款用),需指定折讓金額。僅綠界/ezPay/Amego 支援。破壞性、不可逆 —— 呼叫只會「提出」,使用者按「確認執行」後才開立,你不需要自己再追問。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order'  => [
							'type'        => 'string',
							'description' => __( '訂單編號', 'mo-ectools' ),
						],
						'amount' => [
							'type'        => 'integer',
							'description' => __( '折讓金額(整數,不可超過訂單金額)', 'mo-ectools' ),
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
			'mo-ectools/list-orders',
			[
				'label'               => __( '進階訂單列表', 'mo-ectools' ),
				'description'         => __( '回傳「實際訂單清單」:依狀態、日期區間(date_from / date_to,YYYY-MM-DD)、金流方式篩選,回精簡清單(預設 20 筆、上限 50)。只要各狀態的筆數 / 數量請改用 query-orders。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'status'         => [
							'type'        => 'string',
							'description' => __( '訂單狀態 slug(如 processing / completed),不給則全部', 'mo-ectools' ),
						],
						'date_from'      => [
							'type'        => 'string',
							'description' => __( '起始日期 YYYY-MM-DD', 'mo-ectools' ),
						],
						'date_to'        => [
							'type'        => 'string',
							'description' => __( '結束日期 YYYY-MM-DD', 'mo-ectools' ),
						],
						'payment_method' => [
							'type'        => 'string',
							'description' => __( '金流方式 id(選填)', 'mo-ectools' ),
						],
						'limit'          => [
							'type'        => 'integer',
							'description' => __( '最多幾筆(預設 20,上限 50)', 'mo-ectools' ),
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
			'mo-ectools/list-channels',
			[
				'label'               => __( '列出金流/物流/發票管道', 'mo-ectools' ),
				'description'         => __( '列出金流、物流、電子發票管道與其啟用狀態(含 slug)。category 用 all 取全部,或 payment / shipping / invoice 篩選。唯讀。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'category' => [
							'type'        => 'string',
							'enum'        => [ 'all', 'payment', 'shipping', 'invoice' ],
							'default'     => 'all',
							'description' => __( '選填,預設 all(全部);或 payment / shipping / invoice 篩選', 'mo-ectools' ),
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
			'mo-ectools/toggle-channel',
			[
				'label'               => __( '啟用/停用管道', 'mo-ectools' ),
				'description'         => __( '啟用或停用某個金流/物流/電子發票管道。channel 可直接給管道名稱(如「速買配物流」)或 slug,不必先查清單。這是破壞性操作(停用會影響結帳可用方式、啟用未設定憑證的管道會在前台出錯)—— 呼叫只會「提出」,使用者按「確認執行」後才生效,你不需要自己再追問。不處理憑證與測試/正式切換。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'channel' => [
							'type'        => 'string',
							'description' => __( '管道名稱或 slug(如「速買配物流」或 smilepay_shipping)', 'mo-ectools' ),
						],
						'enable'  => [
							'type'        => 'boolean',
							'description' => __( 'true=啟用、false=停用', 'mo-ectools' ),
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
			'mo-ectools/add-donation-org',
			[
				'label'               => __( '新增發票捐贈單位', 'mo-ectools' ),
				'description'         => __( '新增一個電子發票捐贈單位(社福團體名稱 + 愛心碼)到啟用中發票模組的捐贈名單,之後結帳的捐贈選單就能選。愛心碼為 3-7 碼數字。呼叫只會「提出」,使用者按「確認執行」後才寫入,你不需要自己再追問。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'name' => [
							'type'        => 'string',
							'description' => __( '捐贈單位 / 社福團體名稱', 'mo-ectools' ),
						],
						'code' => [
							'type'        => 'string',
							'description' => __( '愛心碼(3-7 碼數字)', 'mo-ectools' ),
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
			'mo-ectools/create-shipment',
			[
				'label'               => __( '建立託運單', 'mo-ectools' ),
				'description'         => __( '為訂單向物流商建立託運單並取得物流單號(依訂單的運送方式自動判斷物流商)。這是破壞性、不可逆的操作(會向物流商真實下單)—— 呼叫此工具只會「提出」,使用者按「確認執行」後才真正建單,你不需要自己再追問確認。建單後才能列印物流單。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( '訂單編號(如 2896)', 'mo-ectools' ),
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

	private static function register_add_note(): void {
		wp_register_ability(
			'mo-ectools/add-order-note',
			[
				'label'               => __( '新增訂單備註', 'mo-ectools' ),
				'description'         => __( '為指定訂單加一則內部備註(記事用,不寄給客戶)。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( '訂單編號', 'mo-ectools' ),
						],
						'note'  => [
							'type'        => 'string',
							'description' => __( '備註內容', 'mo-ectools' ),
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
				'message' => __( '權限不足。', 'mo-ectools' ),
			];
		}
		$ref   = is_array( $input ) && isset( $input['order'] ) ? (string) $input['order'] : '';
		$note  = is_array( $input ) && isset( $input['note'] ) ? trim( sanitize_textarea_field( (string) $input['note'] ) ) : '';
		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return [
				'ok'      => false,
				'message' => __( '找不到訂單。', 'mo-ectools' ),
			];
		}
		if ( '' === $note ) {
			return [
				'ok'      => false,
				'message' => __( '備註內容不可空白。', 'mo-ectools' ),
			];
		}
		$order->add_order_note( $note );
		return [
			'ok'      => true,
			/* translators: %s: order number */
			'message' => sprintf( __( '已為訂單 #%s 加上備註。', 'mo-ectools' ), $order->get_order_number() ),
		];
	}

	private static function register_print_label(): void {
		wp_register_ability(
			'mo-ectools/print-shipping-label',
			[
				'label'               => __( '列印物流單', 'mo-ectools' ),
				'description'         => __( '為一筆或多筆訂單列印物流標籤(託運單)。自動依各訂單的物流商分組。呼叫此工具只會「提出」,使用者按「確認執行」後會開啟列印頁。訂單需已建立託運單才有標籤可印。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'orders' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( '要列印的訂單編號陣列(如 ["2867","2664"])', 'mo-ectools' ),
						],
						'paper'  => [
							'type'        => 'string',
							'description' => __( '紙張:1=A4(預設)、2=A6 標籤機', 'mo-ectools' ),
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
			'mo-ectools/issue-invoice',
			[
				'label'               => __( '開立電子發票', 'mo-ectools' ),
				'description'         => __( '為指定訂單開立電子發票(自動依訂單設定的發票商與發票類型)。這是破壞性、不可逆的稅務操作 —— 呼叫此工具只會「提出」,系統會要求使用者按「確認執行」後才真正開立,你不需要自己再追問確認。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order' => [
							'type'        => 'string',
							'description' => __( '訂單編號(如 2896)', 'mo-ectools' ),
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
			'mo-ectools/void-invoice',
			[
				'label'               => __( '作廢電子發票', 'mo-ectools' ),
				'description'         => __( '作廢指定訂單已開立的電子發票,需提供作廢原因。這是破壞性、不可逆的稅務操作 —— 呼叫此工具只會「提出」,系統會要求使用者按「確認執行」後才真正作廢,你不需要自己再追問確認。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'order'  => [
							'type'        => 'string',
							'description' => __( '訂單編號(如 2896)', 'mo-ectools' ),
						],
						'reason' => [
							'type'        => 'string',
							'description' => __( '作廢原因(如:客戶取消、開立錯誤)', 'mo-ectools' ),
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
			'mo-ectools/batch-update-order-status',
			[
				'label'               => __( '批次更改訂單狀態', 'mo-ectools' ),
				'description'         => __( '把「多筆」訂單一次改成同一個新狀態(如 processing 處理中、completed 已完成、cancelled 已取消)。這是破壞性操作 —— 呼叫此工具只會「提出」變更,系統會要求使用者按「確認執行」後才真正變更,你不需要自己再追問確認。只要使用者一次提到兩筆以上訂單就用這個。', 'mo-ectools' ),
				'category'            => 'mo-ectools',
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'orders' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( '要變更的訂單編號陣列(如 ["2896","2897","2900"])', 'mo-ectools' ),
						],
						'status' => [
							'type'        => 'string',
							'description' => __( '目標狀態 slug,如 processing / completed / cancelled / on-hold / refunded', 'mo-ectools' ),
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
	 * 註冊時 gate MCP 暴露：設定關閉時，把破壞性 mo-ectools 能力的 meta.mcp.public 設為
	 * false（WordPress 核心 MCP Adapter 據此不暴露），並記錄供 deprecated WC endpoint 同步排除。
	 * 破壞性能力即使暴露也只會「提議」（execute_callback 一律是 propose-only 的 *prepare），
	 * 真正變更在非-ability 的 *apply（僅站內確認 REST 可達）。此 gate 為防禦縱深。
	 *
	 * @param mixed $args ability 註冊參數。
	 * @param mixed $name ability 名稱。
	 * @return mixed
	 */
	public static function gate_mcp_exposure( $args, $name ) {
		if ( ! is_string( $name ) || 0 !== strpos( $name, 'mo-ectools/' ) || ! is_array( $args ) ) {
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
		if ( is_string( $ability_id ) && 0 === strpos( $ability_id, 'mo-ectools/' ) ) {
			return empty( self::$mcp_hidden[ $ability_id ] );
		}
		return $include;
	}
}
