<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice\Operations;

use Moksafowo\Modules\EcpayInvoice\Api\Helper;
use Moksafowo\Modules\Shared\Caps;
use Moksafowo\Modules\Shared\Compat\LegacyOrderData;
use Moksafowo\Order\Meta\Keys;
use Moksafowo\Refund\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * 對綠界官方外掛或 woomp 開的舊發票開折讓（部分退款用）。用 Moksa 綠界發票設定的商店帳號送出，
 * 兩邊要是同一組綠界帳號；折讓紀錄存在 Moksa 自己的 meta，舊外掛的資料不改。
 * 官方：B2CInvoice/Allowance（一般開立折讓）、B2CInvoice/AllowanceInvalid（作廢折讓）。
 */
final class LegacyAllowance {

	public const AJAX_ISSUE = 'moksafowo_ecpay_legacy_invoice_allowance';
	public const AJAX_VOID  = 'moksafowo_ecpay_legacy_allowance_void';

	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ISSUE, [ __CLASS__, 'ajax_issue' ] );
		add_action( 'wp_ajax_' . self::AJAX_VOID, [ __CLASS__, 'ajax_void' ] );
	}

	/**
	 * @return array<int, array{no: string, amount: int, voided: bool}>
	 */
	public static function allowances( \WC_Order $order ): array {
		$list = $order->get_meta( Keys::LEGACY_INVOICE_ALLOWANCES );
		return is_array( $list ) ? array_values( $list ) : [];
	}

	/** 還有效的折讓總額。 */
	public static function open_total( \WC_Order $order ): int {
		$sum = 0;
		foreach ( self::allowances( $order ) as $row ) {
			if ( empty( $row['voided'] ) ) {
				$sum += (int) ( $row['amount'] ?? 0 );
			}
		}
		return $sum;
	}

	public static function ajax_issue(): void {
		check_ajax_referer( self::AJAX_ISSUE, 'nonce' );
		$order = self::ajax_order( isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0, Caps::INVOICE_ALLOWANCE );
		// 只收純數字（「1,000」「-300」之類一律退回，不要被轉成別的金額送出去）。
		$raw    = isset( $_POST['amount'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : '';
		$amount = ctype_digit( $raw ) ? (int) $raw : 0;
		$result = self::locked( $order->get_id(), static fn( \WC_Order $fresh ) => self::run( $fresh, $amount ) );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public static function ajax_void(): void {
		check_ajax_referer( self::AJAX_VOID, 'nonce' );
		$order  = self::ajax_order( isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0, Caps::INVOICE_VOID );
		$no     = isset( $_POST['allowance_no'] ) ? sanitize_text_field( wp_unslash( $_POST['allowance_no'] ) ) : '';
		$result = self::locked( $order->get_id(), static fn( \WC_Order $fresh ) => self::void( $fresh, $no ) );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	/** 呼叫前已 check_ajax_referer。 */
	private static function ajax_order( int $order_id, string $cap ): \WC_Order {
		if ( ! Caps::user_can( $cap ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}
		return $order;
	}

	/**
	 * 同一張訂單的發票動作一次只跑一個（連點兩下不會開兩張折讓，折讓和作廢也不會同時跑）；
	 * 拿到鎖之後才重新讀訂單，用的是最新的折讓清單。
	 *
	 * @param int      $order_id 訂單 ID。
	 * @param callable $action   收到重新讀取的訂單，回傳 ok／message。
	 * @return array{ok: bool, message: string}
	 */
	public static function locked( int $order_id, callable $action ): array {
		if ( ! Guard::lock( $order_id, 'invoice' ) ) {
			return [
				'ok'      => false,
				'message' => __( 'Another invoice action is running on this order. Please try again in a moment.', 'moksa-for-woocommerce' ),
			];
		}
		try {
			$fresh = wc_get_order( $order_id );
			if ( ! $fresh instanceof \WC_Order ) {
				return [
					'ok'      => false,
					'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ),
				];
			}
			return $action( $fresh );
		} finally {
			Guard::unlock( $order_id, 'invoice' );
		}
	}

	/**
	 * @return array{ok: bool, message: string, allowance_no?: string}
	 */
	public static function run( \WC_Order $order, int $amount ): array {
		$legacy = LegacyOrderData::invoice( $order );
		if ( null === $legacy || ! LegacyOrderData::ecpay_actionable( $legacy ) ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no ECPay invoice from an earlier plugin that can take an allowance here.', 'moksa-for-woocommerce' ),
			];
		}
		$left = (int) round( (float) $order->get_total() ) - self::open_total( $order );
		if ( $amount <= 0 || $amount > $left ) {
			return [
				'ok'      => false,
				/* translators: %d: amount still available for allowances */
				'message' => sprintf( __( 'Enter an allowance amount between 1 and %d.', 'moksa-for-woocommerce' ), max( 0, $left ) ),
			];
		}
		$date = LegacyInvoiceDate::resolve( $order, $legacy );
		if ( '' === $date ) {
			return [
				'ok'      => false,
				'message' => __( 'The issue date of this invoice could not be found, and ECPay needs it. Handle this invoice in the ECPay dashboard instead.', 'moksa-for-woocommerce' ),
			];
		}

		$result = Helper::post(
			'/B2CInvoice/Allowance',
			[
				'MerchantID'      => Helper::merchant_id(),
				'InvoiceNo'       => $legacy['number'],
				'InvoiceDate'     => $date,
				'AllowanceNotify' => '' !== (string) $order->get_billing_email() ? 'E' : 'N',
				'CustomerName'    => trim( $order->get_billing_last_name() . $order->get_billing_first_name() ),
				'NotifyMail'      => $order->get_billing_email(),
				'AllowanceAmount' => $amount,
				'Items'           => [
					[
						'ItemSeq'     => 1,
						'ItemName'    => __( 'Refund', 'moksa-for-woocommerce' ),
						'ItemCount'   => 1,
						'ItemWord'    => '式',
						'ItemPrice'   => $amount,
						'ItemTaxType' => '1',
						'ItemAmount'  => $amount,
					],
				],
			]
		);
		if ( ! $result['ok'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: invoice number, 2: plugin name, 3: error message */
					__( 'The allowance on invoice %1$s from the %2$s failed: %3$s', 'moksa-for-woocommerce' ),
					$legacy['number'],
					$legacy['source'],
					$result['message']
				)
			);
			return [
				'ok'      => false,
				'message' => (string) $result['message'],
			];
		}

		$no = sanitize_text_field( (string) ( $result['data']['IA_Allow_No'] ?? '' ) );
		if ( '' === $no ) {
			// 綠界說成功卻沒回折讓單號：記不下來就無法在這裡作廢，請店家到綠界後台確認。
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: invoice number */
					__( 'ECPay accepted an allowance of %1$d on invoice %2$s but returned no allowance number. Check it in the ECPay dashboard.', 'moksa-for-woocommerce' ),
					$amount,
					$legacy['number']
				)
			);
			return [
				'ok'      => false,
				'message' => __( 'ECPay returned no allowance number. Check the allowance in the ECPay dashboard.', 'moksa-for-woocommerce' ),
			];
		}
		$list   = self::allowances( $order );
		$list[] = [
			'no'     => $no,
			'amount' => $amount,
			'voided' => false,
		];
		$order->update_meta_data( Keys::LEGACY_INVOICE_ALLOWANCES, $list );
		$order->add_order_note(
			sprintf(
				/* translators: 1: allowance number, 2: amount, 3: invoice number */
				__( 'ECPay allowance %1$s was issued for %2$d on invoice %3$s', 'moksa-for-woocommerce' ),
				$no,
				$amount,
				$legacy['number']
			)
		);
		$order->save();
		return [
			'ok'           => true,
			'message'      => (string) $result['message'],
			'allowance_no' => $no,
		];
	}

	/**
	 * @return array{ok: bool, message: string}
	 */
	public static function void( \WC_Order $order, string $allowance_no ): array {
		$legacy = LegacyOrderData::invoice( $order );
		$found  = false;
		foreach ( self::allowances( $order ) as $row ) {
			$found = $found || ( (string) ( $row['no'] ?? '' ) === $allowance_no && empty( $row['voided'] ) );
		}
		// 外部標記（ERP開立）蓋過舊發票時，號碼不是真的字軌，絕不能拿去打綠界。
		if ( null === $legacy || ! LegacyOrderData::ecpay_actionable( $legacy ) || '' === $allowance_no || ! $found ) {
			return [
				'ok'      => false,
				'message' => __( 'This order has no allowance to void.', 'moksa-for-woocommerce' ),
			];
		}
		$result = Helper::post(
			'/B2CInvoice/AllowanceInvalid',
			[
				'MerchantID'  => Helper::merchant_id(),
				'InvoiceNo'   => $legacy['number'],
				'AllowanceNo' => $allowance_no,
				'Reason'      => 'void',
			]
		);
		if ( ! $result['ok'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: allowance number, 2: error message */
					__( 'ECPay allowance %1$s could not be voided: %2$s', 'moksa-for-woocommerce' ),
					$allowance_no,
					$result['message']
				)
			);
			return [
				'ok'      => false,
				'message' => (string) $result['message'],
			];
		}
		$list = self::allowances( $order );
		foreach ( $list as &$row ) {
			if ( (string) ( $row['no'] ?? '' ) === $allowance_no ) {
				$row['voided'] = true;
			}
		}
		unset( $row );
		$order->update_meta_data( Keys::LEGACY_INVOICE_ALLOWANCES, $list );
		$order->add_order_note(
			sprintf(
				/* translators: %s: allowance number */
				__( 'ECPay allowance %s was voided', 'moksa-for-woocommerce' ),
				$allowance_no
			)
		);
		$order->save();
		return [
			'ok'      => true,
			'message' => (string) $result['message'],
		];
	}
}
