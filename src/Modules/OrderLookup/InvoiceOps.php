<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

use Moksafowo\Modules\AmegoInvoice\Operations\Allowance as AmegoAllowance;
use Moksafowo\Modules\AmegoInvoice\Operations\Invalid as AmegoInvalid;
use Moksafowo\Modules\AmegoInvoice\Operations\Issue as AmegoIssue;
use Moksafowo\Modules\EcpayInvoice\Operations\Allowance as EcpayAllowance;
use Moksafowo\Modules\EcpayInvoice\Operations\Invalid as EcpayInvalid;
use Moksafowo\Modules\EcpayInvoice\Operations\Issue as EcpayIssue;
use Moksafowo\Modules\EzpayInvoice\Operations\Allowance as EzpayAllowance;
use Moksafowo\Modules\EzpayInvoice\Operations\Invalid as EzpayInvalid;
use Moksafowo\Modules\EzpayInvoice\Operations\Issue as EzpayIssue;
use Moksafowo\Modules\PaynowInvoice\Operations\Invalid as PaynowInvalid;
use Moksafowo\Modules\PaynowInvoice\Operations\Issue as PaynowIssue;
use Moksafowo\Modules\SmilepayInvoice\Operations\Invalid as SmilepayInvalid;
use Moksafowo\Modules\SmilepayInvoice\Operations\Issue as SmilepayIssue;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 破壞性操作:電子發票開立 / 作廢。依訂單 INVOICE_PROVIDER 路由到對應 provider 的
 * Operations\Issue / Invalid;空則取啟用中的發票模組(優先序 ecpay > ezpay > smilepay
 * > paynow > amego)。一律走人工確認關卡(prepare 只描述、apply 才呼叫真實 API)。
 *
 * 發票是不可逆稅務文件,CAP 設 manage_woocommerce(比查詢高一階)。
 */
final class InvoiceOps {

	const CAP = 'manage_woocommerce';

	/**
	 * provider => [ IssueClass, InvalidClass, 發票號 meta key, 顯示名, AllowanceClass|null ]。
	 *
	 * @return array<string, array{0:class-string, 1:class-string, 2:string, 3:string, 4:?class-string}>
	 */
	private static function providers(): array {
		return array(
			'ecpay'    => array( EcpayIssue::class, EcpayInvalid::class, Keys::ECPAY_INVOICE_NUMBER, __( 'ECPay', 'moksa-for-woocommerce' ), EcpayAllowance::class ),
			'ezpay'    => array( EzpayIssue::class, EzpayInvalid::class, Keys::EZPAY_INVOICE_NUMBER, __( 'ezPay', 'moksa-for-woocommerce' ), EzpayAllowance::class ),
			'smilepay' => array( SmilepayIssue::class, SmilepayInvalid::class, Keys::SMILEPAY_INVOICE_NUMBER, __( 'SmilePay', 'moksa-for-woocommerce' ), null ),
			'paynow'   => array( PaynowIssue::class, PaynowInvalid::class, Keys::PAYNOW_INVOICE_NUMBER, __( 'PayNow', 'moksa-for-woocommerce' ), null ),
			'amego'    => array( AmegoIssue::class, AmegoInvalid::class, Keys::AMEGO_INVOICE_NUMBER, __( 'Amego', 'moksa-for-woocommerce' ), AmegoAllowance::class ),
		);
	}

	private static function enabled( string $provider ): bool {
		return 'yes' === get_option( 'moksafowo_' . $provider . '_invoice_enabled', 'no' );
	}

	/**
	 * 決定該訂單用哪家 provider。
	 *
	 * @param \WC_Order $order 訂單。
	 * @return string provider slug,找不到啟用模組回空字串。
	 */
	private static function resolve_provider( \WC_Order $order ): string {
		$map  = self::providers();
		$meta = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		if ( '' !== $meta && isset( $map[ $meta ] ) && self::enabled( $meta ) ) {
			return $meta;
		}
		foreach ( array( 'ecpay', 'ezpay', 'smilepay', 'paynow', 'amego' ) as $p ) {
			if ( self::enabled( $p ) ) {
				return $p;
			}
		}
		return '';
	}

	private static function order_from( $args ): ?\WC_Order {
		$ref   = is_array( $args ) && isset( $args['order'] ) ? (string) $args['order'] : '';
		$id    = absint( preg_replace( '/[^0-9]/', '', $ref ) );
		$order = $id ? wc_get_order( $id ) : false;
		return ( $order && 'shop_order' === $order->get_type() ) ? $order : null;
	}

	private static function type_label( \WC_Order $order ): string {
		return match ( (string) $order->get_meta( Keys::INVOICE_TYPE ) ) {
			'b2b'        => __( 'Company (tax ID)', 'moksa-for-woocommerce' ),
			'b2c_donate' => __( 'Donation', 'moksa-for-woocommerce' ),
			'paper'      => __( 'Paper', 'moksa-for-woocommerce' ),
			default      => __( 'Individual (carrier)', 'moksa-for-woocommerce' ),
		};
	}

	/**
	 * @param mixed $args { order: string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function issue_prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$order = self::order_from( $args );
		if ( ! $order ) {
			return new \WP_Error( 'moksafowo_ai_no_order', __( 'The order could not be found.', 'moksa-for-woocommerce' ) );
		}
		$provider = self::resolve_provider( $order );
		if ( '' === $provider ) {
			return new \WP_Error( 'moksafowo_ai_no_invoice_module', __( 'No e-invoice module is enabled.', 'moksa-for-woocommerce' ) );
		}
		$map      = self::providers();
		$existing = (string) $order->get_meta( $map[ $provider ][2] );

		$summary = sprintf(
			/* translators: 1: order number, 2: provider name, 3: invoice type */
			__( 'Issue an e-invoice for order #%1$s through %2$s, type %3$s.', 'moksa-for-woocommerce' ),
			$order->get_order_number(),
			$map[ $provider ][3],
			self::type_label( $order )
		);
		if ( '' !== $existing ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: existing invoice number */
				__( '(Note: this order already has invoice number %s. A new one is only issued if that has been voided.)', 'moksa-for-woocommerce' ),
				$existing
			);
		}

		return array(
			'order_id' => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'provider' => $provider,
			'summary'  => $summary,
		);
	}

	/**
	 * @param array<string,mixed> $params issue_prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function issue_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$order    = wc_get_order( (int) ( $params['order_id'] ?? 0 ) );
		$provider = (string) ( $params['provider'] ?? '' );
		$map      = self::providers();
		if ( ! $order || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'moksafowo_ai_no_order', __( 'The order or the invoice service could not be found.', 'moksa-for-woocommerce' ) );
		}

		$result = call_user_func( array( $map[ $provider ][0], 'run' ), $order );
		if ( empty( $result['ok'] ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'moksafowo_ai_issue_failed', sprintf( __( 'Could not issue: %s', 'moksa-for-woocommerce' ), (string) ( $result['message'] ?? '' ) ) );
		}
		$inv = (string) ( $result['invoice_no'] ?? $order->get_meta( $map[ $provider ][2] ) );
		return sprintf(
			/* translators: 1: order number, 2: invoice number, 3: provider */
			__( '✅ E-invoice %2$s was issued for order #%1$s through %3$s.', 'moksa-for-woocommerce' ),
			$order->get_order_number(),
			$inv,
			$map[ $provider ][3]
		);
	}

	/**
	 * @param mixed $args { order: string, reason: string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function void_prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$order = self::order_from( $args );
		if ( ! $order ) {
			return new \WP_Error( 'moksafowo_ai_no_order', __( 'The order could not be found.', 'moksa-for-woocommerce' ) );
		}
		$reason = is_array( $args ) && isset( $args['reason'] ) ? trim( (string) $args['reason'] ) : '';
		if ( '' === $reason ) {
			return new \WP_Error( 'moksafowo_ai_no_reason', __( 'Please provide a reason for voiding.', 'moksa-for-woocommerce' ) );
		}

		$map      = self::providers();
		$provider = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		if ( '' === $provider || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'moksafowo_ai_no_invoice', __( 'This order has no invoice to void.', 'moksa-for-woocommerce' ) );
		}
		$inv = (string) $order->get_meta( $map[ $provider ][2] );
		if ( '' === $inv ) {
			return new \WP_Error( 'moksafowo_ai_no_invoice', __( 'This order has no invoice to void.', 'moksa-for-woocommerce' ) );
		}

		return array(
			'order_id' => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'provider' => $provider,
			'invoice'  => $inv,
			'reason'   => $reason,
			'summary'  => sprintf(
				/* translators: 1: order number, 2: invoice number, 3: provider, 4: reason */
				__( 'Void e-invoice %2$s on order #%1$s through %3$s. Reason: %4$s.', 'moksa-for-woocommerce' ),
				$order->get_order_number(),
				$inv,
				$map[ $provider ][3],
				$reason
			),
		);
	}

	/**
	 * @param array<string,mixed> $params void_prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function void_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$order    = wc_get_order( (int) ( $params['order_id'] ?? 0 ) );
		$provider = (string) ( $params['provider'] ?? '' );
		$reason   = (string) ( $params['reason'] ?? '' );
		$map      = self::providers();
		if ( ! $order || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'moksafowo_ai_no_order', __( 'The order or the invoice service could not be found.', 'moksa-for-woocommerce' ) );
		}

		$result = call_user_func( array( $map[ $provider ][1], 'run' ), $order, $reason );
		if ( empty( $result['ok'] ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'moksafowo_ai_void_failed', sprintf( __( 'Could not void: %s', 'moksa-for-woocommerce' ), (string) ( $result['message'] ?? '' ) ) );
		}
		return sprintf(
			/* translators: 1: order number, 2: invoice number */
			__( '✅ E-invoice %2$s on order #%1$s was voided.', 'moksa-for-woocommerce' ),
			$order->get_order_number(),
			(string) ( $params['invoice'] ?? '' )
		);
	}

	/**
	 * @param mixed $args { order: string, amount: int }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function allowance_prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$order = self::order_from( $args );
		if ( ! $order ) {
			return new \WP_Error( 'moksafowo_ai_no_order', __( 'The order could not be found.', 'moksa-for-woocommerce' ) );
		}
		$amount = is_array( $args ) && isset( $args['amount'] ) ? absint( preg_replace( '/[^0-9]/', '', (string) $args['amount'] ) ) : 0;
		if ( $amount <= 0 ) {
			return new \WP_Error( 'moksafowo_ai_bad_amount', __( 'Please provide an allowance amount greater than 0.', 'moksa-for-woocommerce' ) );
		}

		$map      = self::providers();
		$provider = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		if ( '' === $provider || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'moksafowo_ai_no_invoice', __( 'This order has no invoice.', 'moksa-for-woocommerce' ) );
		}
		if ( null === $map[ $provider ][4] ) {
			/* translators: %s: provider name */
			return new \WP_Error( 'moksafowo_ai_no_allowance', sprintf( __( '%s does not support allowances.', 'moksa-for-woocommerce' ), $map[ $provider ][3] ) );
		}
		$inv = (string) $order->get_meta( $map[ $provider ][2] );
		if ( '' === $inv ) {
			return new \WP_Error( 'moksafowo_ai_no_invoice', __( 'This order has no issued invoice to raise an allowance against.', 'moksa-for-woocommerce' ) );
		}
		$total = (int) round( (float) $order->get_total() );
		if ( $amount > $total ) {
			return new \WP_Error(
				'moksafowo_ai_amount_over',
				sprintf(
					/* translators: 1: amount, 2: order total */
					__( 'The allowance amount %1$d is larger than the order total %2$d.', 'moksa-for-woocommerce' ),
					$amount,
					$total
				)
			);
		}

		return array(
			'order_id' => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'provider' => $provider,
			'invoice'  => $inv,
			'amount'   => $amount,
			'summary'  => sprintf(
				/* translators: 1: order number, 2: invoice number, 3: amount, 4: provider */
				__( 'Issue an allowance of NT$%3$d against invoice %2$s on order #%1$s through %4$s.', 'moksa-for-woocommerce' ),
				$order->get_order_number(),
				$inv,
				$amount,
				$map[ $provider ][3]
			),
		);
	}

	/**
	 * @param array<string,mixed> $params allowance_prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function allowance_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$order    = wc_get_order( (int) ( $params['order_id'] ?? 0 ) );
		$provider = (string) ( $params['provider'] ?? '' );
		$amount   = (int) ( $params['amount'] ?? 0 );
		$map      = self::providers();
		if ( ! $order || ! isset( $map[ $provider ] ) || null === $map[ $provider ][4] || $amount <= 0 ) {
			return new \WP_Error( 'moksafowo_ai_bad_input', __( 'The details are incomplete, so no allowance was issued.', 'moksa-for-woocommerce' ) );
		}

		$result = call_user_func( array( $map[ $provider ][4], 'run' ), $order, $amount );
		if ( empty( $result['ok'] ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'moksafowo_ai_allowance_failed', sprintf( __( 'Could not issue the allowance: %s', 'moksa-for-woocommerce' ), (string) ( $result['message'] ?? '' ) ) );
		}
		return sprintf(
			/* translators: 1: order number, 2: amount */
			__( '✅ An allowance of NT$%2$d was issued for order #%1$s.', 'moksa-for-woocommerce' ),
			$order->get_order_number(),
			$amount
		);
	}
}
