<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\AiAssistant;

use Moksafowo\Modules\OrderLookup\BatchUpdateOrderStatus;
use Moksafowo\Modules\OrderLookup\ChangePickupStore;
use Moksafowo\Modules\OrderLookup\ChannelOps;
use Moksafowo\Modules\OrderLookup\DonationOrgOps;
use Moksafowo\Modules\OrderLookup\InvoiceChannelOps;
use Moksafowo\Modules\OrderLookup\InvoiceOps;
use Moksafowo\Modules\OrderLookup\PaymentMethodOps;
use Moksafowo\Modules\OrderLookup\PrintShippingLabel;
use Moksafowo\Modules\OrderLookup\ResendPaymentEmail;
use Moksafowo\Modules\OrderLookup\ShipmentOps;
use Moksafowo\Modules\OrderLookup\ShipmentRecordOps;
use Moksafowo\Modules\OrderLookup\ShippingZoneOps;
use Moksafowo\Modules\OrderLookup\UpdateOrderStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Moksa AI 共用設定 —— ability 白名單 + 系統提示 + 破壞性動作處理表。浮動窗與 REST 共用。
 */
final class Config {

	const CAP  = 'edit_shop_orders';
	const NAME = 'Moksa AI';

	/**
	 * 暴露給 AI 當工具的 ability 白名單(含破壞性的;破壞性會被 Agent 攔下走確認關卡)。
	 *
	 * @return string[]
	 */
	public static function abilities(): array {
		$abilities = array(
			'moksa-for-woocommerce/find-order-by-number',
			'moksa-for-woocommerce/get-order-details',
			'moksa-for-woocommerce/query-orders',
			'moksa-for-woocommerce/update-order-status',
			'moksa-for-woocommerce/batch-update-order-status',
			'moksa-for-woocommerce/issue-invoice',
			'moksa-for-woocommerce/void-invoice',
			'moksa-for-woocommerce/print-shipping-label',
			'moksa-for-woocommerce/create-shipment',
			'moksa-for-woocommerce/change-pickup-store',
			'moksa-for-woocommerce/list-shipments',
			'moksa-for-woocommerce/delete-shipment',
			'moksa-for-woocommerce/add-order-note',
			'moksa-for-woocommerce/list-channels',
			'moksa-for-woocommerce/toggle-channel',
			'moksa-for-woocommerce/add-donation-org',
			'moksa-for-woocommerce/issue-allowance',
			'moksa-for-woocommerce/list-orders',
			'moksa-for-woocommerce/list-payment-methods',
			'moksa-for-woocommerce/toggle-payment-method',
			'moksa-for-woocommerce/list-invoice-channels',
			'moksa-for-woocommerce/toggle-invoice-channel',
			'moksa-for-woocommerce/resend-payment-email',
			'moksa-for-woocommerce/get-tracking-link',
			'moksa-for-woocommerce/get-payment-status',
			'moksa-for-woocommerce/query-payment',
			'moksa-for-woocommerce/get-plugin-settings',
			'moksa-for-woocommerce/list-shipping-zones',
			'moksa-for-woocommerce/toggle-shipping-method',
			'moksa-for-woocommerce/batch-create-shipment',
			'moksa-for-woocommerce/find-customer-orders',
			'moksa-for-woocommerce/sales-summary',
		);
		return (array) apply_filters( 'moksafowo_ai_assistant_abilities', $abilities );
	}

	/**
	 * 破壞性動作處理表:ability 名稱 => [ prepare 驗證描述, apply 真正執行 ]。
	 * AI 呼叫這些 ability 時會被攔下,改走「人工確認」關卡。
	 *
	 * @return array<string, array{prepare:callable, apply:callable}>
	 */
	public static function destructive_handlers(): array {
		$handlers = array(
			'moksa-for-woocommerce/update-order-status'    => array(
				'prepare' => array( UpdateOrderStatus::class, 'prepare' ),
				'apply'   => array( UpdateOrderStatus::class, 'apply' ),
			),
			'moksa-for-woocommerce/batch-update-order-status' => array(
				'prepare' => array( BatchUpdateOrderStatus::class, 'prepare' ),
				'apply'   => array( BatchUpdateOrderStatus::class, 'apply' ),
			),
			'moksa-for-woocommerce/issue-invoice'          => array(
				'prepare' => array( InvoiceOps::class, 'issue_prepare' ),
				'apply'   => array( InvoiceOps::class, 'issue_apply' ),
			),
			'moksa-for-woocommerce/void-invoice'           => array(
				'prepare' => array( InvoiceOps::class, 'void_prepare' ),
				'apply'   => array( InvoiceOps::class, 'void_apply' ),
			),
			'moksa-for-woocommerce/print-shipping-label'   => array(
				'prepare' => array( PrintShippingLabel::class, 'prepare' ),
				'apply'   => array( PrintShippingLabel::class, 'apply' ),
			),
			'moksa-for-woocommerce/create-shipment'        => array(
				'prepare' => array( ShipmentOps::class, 'prepare' ),
				'apply'   => array( ShipmentOps::class, 'apply' ),
			),
			'moksa-for-woocommerce/change-pickup-store'    => array(
				'prepare' => array( ChangePickupStore::class, 'prepare' ),
				'apply'   => array( ChangePickupStore::class, 'apply' ),
			),
			'moksa-for-woocommerce/delete-shipment'        => array(
				'prepare' => array( ShipmentRecordOps::class, 'prepare' ),
				'apply'   => array( ShipmentRecordOps::class, 'apply' ),
			),
			'moksa-for-woocommerce/batch-create-shipment'  => array(
				'prepare' => array( ShipmentOps::class, 'batch_prepare' ),
				'apply'   => array( ShipmentOps::class, 'batch_apply' ),
			),
			'moksa-for-woocommerce/toggle-channel'         => array(
				'prepare' => array( ChannelOps::class, 'toggle_prepare' ),
				'apply'   => array( ChannelOps::class, 'toggle_apply' ),
			),
			'moksa-for-woocommerce/add-donation-org'       => array(
				'prepare' => array( DonationOrgOps::class, 'prepare' ),
				'apply'   => array( DonationOrgOps::class, 'apply' ),
			),
			'moksa-for-woocommerce/issue-allowance'        => array(
				'prepare' => array( InvoiceOps::class, 'allowance_prepare' ),
				'apply'   => array( InvoiceOps::class, 'allowance_apply' ),
			),
			'moksa-for-woocommerce/toggle-payment-method'  => array(
				'prepare' => array( PaymentMethodOps::class, 'toggle_prepare' ),
				'apply'   => array( PaymentMethodOps::class, 'toggle_apply' ),
			),
			'moksa-for-woocommerce/toggle-invoice-channel' => array(
				'prepare' => array( InvoiceChannelOps::class, 'toggle_prepare' ),
				'apply'   => array( InvoiceChannelOps::class, 'toggle_apply' ),
			),
			'moksa-for-woocommerce/resend-payment-email'   => array(
				'prepare' => array( ResendPaymentEmail::class, 'prepare' ),
				'apply'   => array( ResendPaymentEmail::class, 'apply' ),
			),
			'moksa-for-woocommerce/toggle-shipping-method' => array(
				'prepare' => array( ShippingZoneOps::class, 'toggle_prepare' ),
				'apply'   => array( ShippingZoneOps::class, 'toggle_apply' ),
			),
		);
		return (array) apply_filters( 'moksafowo_ai_destructive_handlers', $handlers );
	}

	public static function destructive_abilities(): array {
		return array_keys( self::destructive_handlers() );
	}

	public static function system_instruction(): string {
		return __( 'You are Moksa AI, the WooCommerce admin assistant for a Taiwanese online store. These are your tools: (1) find a single order by order number, e-invoice number, tracking number or payment transaction ID — when the user simply gives a number, such as “order 2855” or “status of 2855?”, treat it as an order number and use this tool; it is the right choice when all that is needed is a status or which order is meant; (2) get the full details of one order — items, totals, payment and shipping method, pickup store, invoice number and whether it has been issued, tracking number and payment transaction ID — use this for questions like “has the invoice been issued”, “what is the tracking number”, “which store is it going to” or “what did they buy”; (3) count orders and see the breakdown by status, or list the orders in one status (awaiting shipment is processing, awaiting payment is pending, finished is completed); (4) change an order status — update-order-status for one order, batch-update-order-status for several; (5) issue an e-invoice with issue-invoice; (6) void an e-invoice with void-invoice, which needs a reason; (7) print shipping labels with print-shipping-label, for one or more orders that already have shipments; (8) create a shipment with create-shipment, which books it with the carrier and returns a tracking number, after which the label can be printed. Tools 4 to 8 are only proposed: the user is asked to press Confirm before anything happens, so never ask for confirmation yourself. (9) add an order note with add-order-note, which is low risk and runs immediately; (10) list payment, shipping and e-invoice channels with list-channels (read-only); (11) enable or disable a channel with toggle-channel — pass the channel name such as “SmilePay shipping” directly, with no need to list them first (destructive, so it goes through confirmation); (12) add an invoice donation charity with add-donation-org, giving a name and love code (destructive, confirmed); (13) issue an invoice allowance with issue-allowance, giving an amount (destructive, confirmed); (14) get an advanced order list with list-orders, filtered by status, date range or payment method (read-only); (15) list a payment service\'s individual methods with list-payment-methods (read-only); (16) enable or disable those methods with toggle-payment-method, such as ECPay\'s one-off card payment or Apple Pay, passing the names directly (destructive, confirmed); (17) list invoice issuing options with list-invoice-channels (read-only); (18) enable or disable them with toggle-invoice-channel — member carrier, mobile barcode, Citizen Digital Certificate, paper, donation or tax ID (destructive, confirmed); (19) resend the payment details email with resend-payment-email, sending the ATM or convenience store details to the customer (destructive, confirmed); (20) get tracking links with get-tracking-link (read-only); (21) check payment status with get-payment-status — method, whether it is paid, transaction ID, last four card digits, ATM and convenience store details, with a live NewebPay query where applicable (read-only); (22) summarize the plugin settings with get-plugin-settings — which channels are on, test or production mode, when invoices are issued, which fields order lookup searches and whether AI is enabled, never credentials (read-only); (23) list shipping zones and methods with list-shipping-zones (read-only); (24) enable or disable a shipping method with toggle-shipping-method, naming the method directly, such as “SmilePay T-Cat ambient” (destructive, confirmed); (25) create shipments in bulk with batch-create-shipment (destructive, confirmed); (26) find a customer\'s orders with find-customer-orders by email, phone or name (read-only); (27) get revenue statistics with sales-summary — revenue, order count, status breakdown and average order value for a period, defaulting to this month (read-only). Tools 11 to 13, 16, 18, 19, 24 and 25 are also only proposed and wait for confirmation. Always look up the real data with a tool before answering, then reply briefly and clearly in the language the merchant is using. Report only the orders that genuinely match the question, and never pad the answer with unrelated ones. If nothing is found, say so plainly rather than inventing anything. If asked for something the tools cannot do, such as printing many shipping labels at once or changing a great many statuses, do not simply say it is impossible — explain that it can be done by ticking the orders in the WooCommerce order list and using the bulk actions menu above, and that you can help with lookups and single status changes. If several things are asked at once, gather everything with the appropriate tools first, then combine the answers into a single reply. Always finish with text; never stop at a tool call without answering.', 'moksa-for-woocommerce' );
	}
}
