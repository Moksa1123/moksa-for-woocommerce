<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * 從 RY Tools 或綠界官方外掛換過來的商店，舊訂單上留著它們寫的發票號、物流單號與門市。
 * 只讀不改：讓 Moksa 看得到這些資料，並把它們當成「已開過票／已建過單」。
 */
final class LegacyOrderData {

	/**
	 * 舊外掛開立的發票，含之後在 Moksa 作廢的（voided=true）。綠界官方在自己那邊作廢時會清空號碼；
	 * RY 作廢時刪除號碼，0 元／負數／延後開立則寫入 zero、negative、delay，這三種都不是發票。
	 *
	 * @return array{number: string, date: string, random: string, source: string, source_key: string, voided: bool}|null
	 */
	public static function invoice_record( \WC_Order $order ): ?array {
		// 外部系統（ERP、POS）開的票：Moksa 的正式標記鍵。只擋開立，作廢折讓由開票的系統自己做。
		$external = sanitize_text_field( (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::INVOICE_EXTERNAL ) );
		if ( '' !== trim( $external ) ) {
			return [
				'number'     => trim( $external ),
				'date'       => '',
				'random'     => '',
				'source'     => __( 'external system', 'moksa-for-woocommerce' ),
				'source_key' => 'external',
				'voided'     => false,
			];
		}
		$voided = '' !== (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::LEGACY_INVOICE_VOIDED_AT );
		$number = trim( (string) $order->get_meta( '_wooecpay_invoice_no' ) );
		if ( '' !== $number ) {
			return [
				'number'     => $number,
				'date'       => (string) $order->get_meta( '_wooecpay_invoice_date' ),
				'random'     => (string) $order->get_meta( '_wooecpay_invoice_random_number' ),
				'source'     => __( 'ECPay official plugin', 'moksa-for-woocommerce' ),
				'source_key' => 'ecpay_official',
				'voided'     => $voided,
			];
		}
		// woomp（好用版擴充）的綠界發票：只存號碼與狀態（1 已開、0 已在 woomp 作廢並清空號碼），
		// 沒存開立日期；日期要用時再從訂單備註或綠界查詢補（woomp_issue_date、LegacyInvoiceDate）。
		$number = trim( (string) $order->get_meta( '_ecpay_invoice_number' ) );
		if ( '' !== $number && '0' !== (string) $order->get_meta( '_ecpay_invoice_status' ) ) {
			// 不是字軌（例如 ERP 在 POS 鏡像單寫的「ERP開立」）：當成外部已開立，只擋開立。
			if ( ! preg_match( '/^[A-Z]{2}\d{8}$/', $number ) ) {
				return [
					'number'     => sanitize_text_field( $number ),
					'date'       => '',
					'random'     => '',
					'source'     => __( 'external system', 'moksa-for-woocommerce' ),
					'source_key' => 'external',
					'voided'     => false,
				];
			}
			return [
				'number'     => $number,
				'date'       => (string) $order->get_meta( \Moksafowo\Order\Meta\Keys::LEGACY_INVOICE_DATE ),
				'random'     => '',
				'source'     => __( 'woomp plugin', 'moksa-for-woocommerce' ),
				'source_key' => 'woomp',
				'voided'     => $voided,
			];
		}
		$number = trim( (string) $order->get_meta( '_invoice_number' ) );
		if ( '' !== $number && ! in_array( $number, [ 'zero', 'negative', 'delay' ], true ) ) {
			return [
				'number'     => $number,
				'date'       => (string) $order->get_meta( '_invoice_date' ),
				'random'     => (string) $order->get_meta( '_invoice_random_number' ),
				'source'     => __( 'RY Tools', 'moksa-for-woocommerce' ),
				'source_key' => 'ry',
				'voided'     => $voided,
			];
		}
		return null;
	}

	/**
	 * 舊外掛開立、目前仍有效的發票。
	 *
	 * @return array{number: string, date: string, random: string, source: string, source_key: string, voided: bool}|null
	 */
	public static function invoice( \WC_Order $order ): ?array {
		$record = self::invoice_record( $order );
		return ( null !== $record && ! $record['voided'] ) ? $record : null;
	}

	/**
	 * woomp 開票時只把開立時間寫進訂單備註（「產生時間: 2026-11-04 10:00:00」，英文介面是 Generate Time），
	 * 找含這張發票號碼的備註，取裡面第一個日期時間。找不到回空字串。
	 */
	public static function woomp_issue_date( \WC_Order $order, string $number ): string {
		if ( '' === $number ) {
			return '';
		}
		// 從最舊的備註找起，而且日期要緊接在「產生時間／Generate Time」標籤後面，
		// 免得抓到店員或其他系統後來寫的、也提到這個號碼的備註裡的日期。
		$notes = array_reverse( wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
		foreach ( $notes as $note ) {
			$text = wp_strip_all_tags( str_ireplace( [ '<br>', '<br/>', '<br />' ], "\n", (string) $note->content ) );
			if ( false === strpos( $text, $number ) ) {
				continue;
			}
			if ( preg_match( '/(?:產生時間|Generate Time)\s*[:：]\s*(\d{4})[-\/](\d{2})[-\/](\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/u', $text, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return sprintf( '%s-%s-%s', $m[1], $m[2], $m[3] ) . ( isset( $m[4] ) && '' !== $m[4] ? sprintf( ' %s:%s:%s', $m[4], $m[5], '' !== ( $m[6] ?? '' ) ? $m[6] : '00' ) : '' );
			}
		}
		return '';
	}

	/**
	 * woomp 結帳時存的發票資料（_ecpay_invoice_data），轉成後台顯示用的「欄位 => 值」。
	 *
	 * @return array<string,string>
	 */
	public static function woomp_buyer( \WC_Order $order ): array {
		$data = $order->get_meta( '_ecpay_invoice_data' );
		if ( ! is_array( $data ) ) {
			return [];
		}
		$types = [
			'individual' => __( 'Individual', 'moksa-for-woocommerce' ),
			'company'    => __( 'Company', 'moksa-for-woocommerce' ),
			'donate'     => __( 'Donation', 'moksa-for-woocommerce' ),
		];
		$type  = (string) ( $data['_invoice_type'] ?? '' );
		$rows  = [
			__( 'Invoice type', 'moksa-for-woocommerce' ) => $types[ $type ] ?? $type,
		];
		if ( 'company' === $type ) {
			$rows[ __( 'Tax ID', 'moksa-for-woocommerce' ) ]       = (string) ( $data['_invoice_tax_id'] ?? '' );
			$rows[ __( 'Company name', 'moksa-for-woocommerce' ) ] = (string) ( $data['_invoice_company_name'] ?? '' );
		} elseif ( 'donate' === $type ) {
			$rows[ __( 'Donation code', 'moksa-for-woocommerce' ) ] = (string) ( $data['_invoice_donate'] ?? '' );
		} else {
			$rows[ __( 'Carrier', 'moksa-for-woocommerce' ) ]        = is_string( $data['_invoice_individual'] ?? null ) ? $data['_invoice_individual'] : '';
			$rows[ __( 'Carrier number', 'moksa-for-woocommerce' ) ] = (string) ( $data['_invoice_carrier'] ?? '' );
		}
		return array_map( 'sanitize_text_field', $rows );
	}

	/**
	 * 舊發票能不能由 Moksa 用綠界 API 作廢、折讓：綠界官方或 woomp 開的、號碼是真的字軌
	 * （ERP 會在 POS 鏡像單寫「ERP開立」之類的佔位值擋開立，那不是能作廢的發票）。
	 *
	 * @param array<string,mixed>|null $legacy invoice() 的回傳。
	 */
	public static function ecpay_actionable( ?array $legacy ): bool {
		return null !== $legacy
			&& ! $legacy['voided']
			&& in_array( $legacy['source_key'], [ 'ecpay_official', 'woomp' ], true )
			&& (bool) preg_match( '/^[A-Z]{2}\d{8}$/', (string) $legacy['number'] );
	}

	/**
	 * 訂單頁的開立表單前呼叫：已有舊外掛或外部系統開的發票時，印一行說明並回 true（呼叫端就不要印開立按鈕）。
	 */
	public static function render_issue_blocked( \WC_Order $order ): bool {
		$legacy = self::invoice( $order );
		if ( null === $legacy ) {
			return false;
		}
		echo '<p style="color:#646970;">' . esc_html(
			sprintf(
				/* translators: 1: invoice number or marker, 2: plugin or system name */
				__( 'This order already has invoice %1$s, issued by the %2$s, so it cannot be issued here.', 'moksa-for-woocommerce' ),
				$legacy['number'],
				$legacy['source']
			)
		) . '</p>';
		return true;
	}

	public static function invoice_number( \WC_Order $order ): string {
		return self::invoice( $order )['number'] ?? '';
	}

	/**
	 * 自動開立前呼叫：舊外掛已開過票就跳過，並留一則訂單備註說明。
	 */
	public static function skip_issue_if_invoiced( \WC_Order $order ): bool {
		$legacy = self::invoice( $order );
		if ( null === $legacy ) {
			return false;
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: invoice number, 2: plugin name */
				__( 'No new invoice was issued: this order already has invoice %1$s, issued by the %2$s.', 'moksa-for-woocommerce' ),
				$legacy['number'],
				$legacy['source']
			)
		);
		return true;
	}

	/**
	 * 舊外掛建過的物流單。
	 *
	 * @return array<int, array{source: string, id: string, tracking: string, type: string, status: string}>
	 */
	public static function shipments( \WC_Order $order ): array {
		$list = [];
		$ry   = [
			'_ecpay_shipping_info'    => __( 'RY Tools (ECPay)', 'moksa-for-woocommerce' ),
			'_newebpay_shipping_info' => __( 'RY Tools (NewebPay)', 'moksa-for-woocommerce' ),
			'_smilepay_shipping_info' => __( 'RY Tools (SmilePay)', 'moksa-for-woocommerce' ),
		];
		foreach ( $ry as $key => $source ) {
			$info = $order->get_meta( $key );
			if ( ! is_array( $info ) ) {
				continue;
			}
			foreach ( $info as $id => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$list[] = [
					'source'   => $source,
					'id'       => (string) ( $row['ID'] ?? $id ),
					'tracking' => self::tracking_from( (string) ( $row['PaymentNo'] ?? '' ), (string) ( $row['ValidationNo'] ?? '' ), (string) ( $row['BookingNote'] ?? '' ), (string) ( $row['ID'] ?? $id ) ),
					'type'     => (string) ( $row['LogisticsSubType'] ?? $row['LogisticsType'] ?? '' ),
					'status'   => (string) ( $row['status_msg'] ?? '' ),
				];
			}
		}

		$id = trim( (string) $order->get_meta( '_wooecpay_logistic_AllPayLogisticsID' ) );
		if ( '' !== $id ) {
			$list[] = [
				'source'   => __( 'ECPay official plugin', 'moksa-for-woocommerce' ),
				'id'       => $id,
				'tracking' => self::tracking_from( (string) $order->get_meta( '_wooecpay_logistic_CVSPaymentNo' ), (string) $order->get_meta( '_wooecpay_logistic_CVSValidationNo' ), (string) $order->get_meta( '_wooecpay_logistic_BookingNote' ), $id ),
				'type'     => (string) $order->get_meta( '_wooecpay_logistic_LogisticsType' ),
				'status'   => (string) $order->get_meta( '_wooecpay_logistic_RtnMsg' ),
			];
		}
		return $list;
	}

	/** 最後一筆舊物流單的查件號碼，沒有回空字串。 */
	public static function tracking_no( \WC_Order $order ): string {
		$list = self::shipments( $order );
		return $list ? (string) end( $list )['tracking'] : '';
	}

	/**
	 * 舊外掛記下的取貨門市。
	 *
	 * @return array{id: string, name: string, address: string, phone: string}|null
	 */
	public static function store( \WC_Order $order ): ?array {
		$sources = [
			'_shipping_cvs_store_'       => '_shipping_cvs_store_ID',
			'_ecpay_logistic_cvs_store_' => '_ecpay_logistic_cvs_store_id',
		];
		foreach ( $sources as $prefix => $id_key ) {
			$id = trim( (string) $order->get_meta( $id_key ) );
			if ( '' === $id ) {
				continue;
			}
			return [
				'id'      => $id,
				'name'    => (string) $order->get_meta( $prefix . 'name' ),
				'address' => (string) $order->get_meta( $prefix . 'address' ),
				'phone'   => (string) $order->get_meta( $prefix . 'telephone' ),
			];
		}
		return null;
	}

	/** 超商用寄件代碼（加驗證碼），宅配用托運單號，都沒有才用物流商內部編號。 */
	private static function tracking_from( string $payment_no, string $validation_no, string $booking_note, string $id ): string {
		if ( '' !== $payment_no ) {
			return $payment_no . $validation_no;
		}
		return '' !== $booking_note ? $booking_note : $id;
	}
}
