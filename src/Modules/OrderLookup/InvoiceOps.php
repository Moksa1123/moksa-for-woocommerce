<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

use MoksaWeb\Mowc\Modules\AmegoInvoice\Operations\Allowance as AmegoAllowance;
use MoksaWeb\Mowc\Modules\AmegoInvoice\Operations\Invalid as AmegoInvalid;
use MoksaWeb\Mowc\Modules\AmegoInvoice\Operations\Issue as AmegoIssue;
use MoksaWeb\Mowc\Modules\EcpayInvoice\Operations\Allowance as EcpayAllowance;
use MoksaWeb\Mowc\Modules\EcpayInvoice\Operations\Invalid as EcpayInvalid;
use MoksaWeb\Mowc\Modules\EcpayInvoice\Operations\Issue as EcpayIssue;
use MoksaWeb\Mowc\Modules\EzpayInvoice\Operations\Allowance as EzpayAllowance;
use MoksaWeb\Mowc\Modules\EzpayInvoice\Operations\Invalid as EzpayInvalid;
use MoksaWeb\Mowc\Modules\EzpayInvoice\Operations\Issue as EzpayIssue;
use MoksaWeb\Mowc\Modules\PaynowInvoice\Operations\Invalid as PaynowInvalid;
use MoksaWeb\Mowc\Modules\PaynowInvoice\Operations\Issue as PaynowIssue;
use MoksaWeb\Mowc\Modules\SmilepayInvoice\Operations\Invalid as SmilepayInvalid;
use MoksaWeb\Mowc\Modules\SmilepayInvoice\Operations\Issue as SmilepayIssue;
use MoksaWeb\Mowc\Order\Meta\Keys;

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
			'ecpay'    => array( EcpayIssue::class, EcpayInvalid::class, Keys::ECPAY_INVOICE_NUMBER, __( '綠界', 'mo-ectools' ), EcpayAllowance::class ),
			'ezpay'    => array( EzpayIssue::class, EzpayInvalid::class, Keys::EZPAY_INVOICE_NUMBER, __( 'ezPay 簡單付', 'mo-ectools' ), EzpayAllowance::class ),
			'smilepay' => array( SmilepayIssue::class, SmilepayInvalid::class, Keys::SMILEPAY_INVOICE_NUMBER, __( '速買配', 'mo-ectools' ), null ),
			'paynow'   => array( PaynowIssue::class, PaynowInvalid::class, Keys::PAYNOW_INVOICE_NUMBER, __( 'PayNow', 'mo-ectools' ), null ),
			'amego'    => array( AmegoIssue::class, AmegoInvalid::class, Keys::AMEGO_INVOICE_NUMBER, __( 'Amego', 'mo-ectools' ), AmegoAllowance::class ),
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
			'b2b'        => __( '公司(統編)', 'mo-ectools' ),
			'b2c_donate' => __( '捐贈', 'mo-ectools' ),
			'paper'      => __( '紙本', 'mo-ectools' ),
			default      => __( '個人(載具)', 'mo-ectools' ),
		};
	}

	/**
	 * @param mixed $args { order: string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function issue_prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$order = self::order_from( $args );
		if ( ! $order ) {
			return new \WP_Error( 'mo_ai_no_order', __( '找不到訂單。', 'mo-ectools' ) );
		}
		$provider = self::resolve_provider( $order );
		if ( '' === $provider ) {
			return new \WP_Error( 'mo_ai_no_invoice_module', __( '沒有啟用任何電子發票模組。', 'mo-ectools' ) );
		}
		$map      = self::providers();
		$existing = (string) $order->get_meta( $map[ $provider ][2] );

		$summary = sprintf(
			/* translators: 1: order number, 2: provider name, 3: invoice type */
			__( '為訂單 #%1$s 開立電子發票(發票商:%2$s,類型:%3$s)。', 'mo-ectools' ),
			$order->get_order_number(),
			$map[ $provider ][3],
			self::type_label( $order )
		);
		if ( '' !== $existing ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: existing invoice number */
				__( '(注意:此訂單已有發票號 %s,若已作廢才會重開。)', 'mo-ectools' ),
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
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$order    = wc_get_order( (int) ( $params['order_id'] ?? 0 ) );
		$provider = (string) ( $params['provider'] ?? '' );
		$map      = self::providers();
		if ( ! $order || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'mo_ai_no_order', __( '找不到訂單或發票商。', 'mo-ectools' ) );
		}

		$result = call_user_func( array( $map[ $provider ][0], 'run' ), $order );
		if ( empty( $result['ok'] ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'mo_ai_issue_failed', sprintf( __( '開立失敗:%s', 'mo-ectools' ), (string) ( $result['message'] ?? '' ) ) );
		}
		$inv = (string) ( $result['invoice_no'] ?? $order->get_meta( $map[ $provider ][2] ) );
		return sprintf(
			/* translators: 1: order number, 2: invoice number, 3: provider */
			__( '✅ 已為訂單 #%1$s 開立電子發票:%2$s(發票商:%3$s)。', 'mo-ectools' ),
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
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$order = self::order_from( $args );
		if ( ! $order ) {
			return new \WP_Error( 'mo_ai_no_order', __( '找不到訂單。', 'mo-ectools' ) );
		}
		$reason = is_array( $args ) && isset( $args['reason'] ) ? trim( (string) $args['reason'] ) : '';
		if ( '' === $reason ) {
			return new \WP_Error( 'mo_ai_no_reason', __( '請提供作廢原因。', 'mo-ectools' ) );
		}

		$map      = self::providers();
		$provider = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		if ( '' === $provider || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'mo_ai_no_invoice', __( '此訂單沒有發票記錄可作廢。', 'mo-ectools' ) );
		}
		$inv = (string) $order->get_meta( $map[ $provider ][2] );
		if ( '' === $inv ) {
			return new \WP_Error( 'mo_ai_no_invoice', __( '此訂單沒有可作廢的發票。', 'mo-ectools' ) );
		}

		return array(
			'order_id' => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'provider' => $provider,
			'invoice'  => $inv,
			'reason'   => $reason,
			'summary'  => sprintf(
				/* translators: 1: order number, 2: invoice number, 3: provider, 4: reason */
				__( '作廢訂單 #%1$s 的電子發票 %2$s(發票商:%3$s),原因:%4$s。', 'mo-ectools' ),
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
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$order    = wc_get_order( (int) ( $params['order_id'] ?? 0 ) );
		$provider = (string) ( $params['provider'] ?? '' );
		$reason   = (string) ( $params['reason'] ?? '' );
		$map      = self::providers();
		if ( ! $order || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'mo_ai_no_order', __( '找不到訂單或發票商。', 'mo-ectools' ) );
		}

		$result = call_user_func( array( $map[ $provider ][1], 'run' ), $order, $reason );
		if ( empty( $result['ok'] ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'mo_ai_void_failed', sprintf( __( '作廢失敗:%s', 'mo-ectools' ), (string) ( $result['message'] ?? '' ) ) );
		}
		return sprintf(
			/* translators: 1: order number, 2: invoice number */
			__( '✅ 已作廢訂單 #%1$s 的電子發票 %2$s。', 'mo-ectools' ),
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
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$order = self::order_from( $args );
		if ( ! $order ) {
			return new \WP_Error( 'mo_ai_no_order', __( '找不到訂單。', 'mo-ectools' ) );
		}
		$amount = is_array( $args ) && isset( $args['amount'] ) ? absint( preg_replace( '/[^0-9]/', '', (string) $args['amount'] ) ) : 0;
		if ( $amount <= 0 ) {
			return new \WP_Error( 'mo_ai_bad_amount', __( '請提供大於 0 的折讓金額。', 'mo-ectools' ) );
		}

		$map      = self::providers();
		$provider = (string) $order->get_meta( Keys::INVOICE_PROVIDER );
		if ( '' === $provider || ! isset( $map[ $provider ] ) ) {
			return new \WP_Error( 'mo_ai_no_invoice', __( '此訂單沒有發票記錄。', 'mo-ectools' ) );
		}
		if ( null === $map[ $provider ][4] ) {
			/* translators: %s: provider name */
			return new \WP_Error( 'mo_ai_no_allowance', sprintf( __( '%s 不支援折讓單。', 'mo-ectools' ), $map[ $provider ][3] ) );
		}
		$inv = (string) $order->get_meta( $map[ $provider ][2] );
		if ( '' === $inv ) {
			return new \WP_Error( 'mo_ai_no_invoice', __( '此訂單沒有已開立的發票可折讓。', 'mo-ectools' ) );
		}
		$total = (int) round( (float) $order->get_total() );
		if ( $amount > $total ) {
			return new \WP_Error(
				'mo_ai_amount_over',
				sprintf(
					/* translators: 1: amount, 2: order total */
					__( '折讓金額 %1$d 超過訂單金額 %2$d。', 'mo-ectools' ),
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
				__( '為訂單 #%1$s 的發票 %2$s 開立折讓單,金額 NT$%3$d(發票商:%4$s)。', 'mo-ectools' ),
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
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$order    = wc_get_order( (int) ( $params['order_id'] ?? 0 ) );
		$provider = (string) ( $params['provider'] ?? '' );
		$amount   = (int) ( $params['amount'] ?? 0 );
		$map      = self::providers();
		if ( ! $order || ! isset( $map[ $provider ] ) || null === $map[ $provider ][4] || $amount <= 0 ) {
			return new \WP_Error( 'mo_ai_bad_input', __( '資料不完整,無法開立折讓單。', 'mo-ectools' ) );
		}

		$result = call_user_func( array( $map[ $provider ][4], 'run' ), $order, $amount );
		if ( empty( $result['ok'] ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'mo_ai_allowance_failed', sprintf( __( '折讓失敗:%s', 'mo-ectools' ), (string) ( $result['message'] ?? '' ) ) );
		}
		return sprintf(
			/* translators: 1: order number, 2: amount */
			__( '✅ 已為訂單 #%1$s 開立 NT$%2$d 折讓單。', 'mo-ectools' ),
			$order->get_order_number(),
			$amount
		);
	}
}
