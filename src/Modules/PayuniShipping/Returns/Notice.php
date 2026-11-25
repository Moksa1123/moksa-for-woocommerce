<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Returns;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;

defined( 'ABSPATH' ) || exit;

/**
 * 退貨貨態：通知與手動查詢都走這裡。
 *
 * 只更新退貨紀錄並寫備註，不改出貨貨態、不改訂單狀態、不退款；
 * 想自動處理的商家可以接 moksafowo_payuni_return_status。
 */
final class Notice {

	/**
	 * 處理已驗章、已解密的退貨貨態通知（7-11 退貨便或黑貓逆物流）。
	 *
	 * @param array<string, mixed> $info            解密後的通知。
	 * @param callable|null        $fallback_finder 找不到退貨紀錄時，用物流序號找訂單（黑貓逆物流沿用原單序號時）。
	 */
	public static function handle( array $info, ?callable $fallback_finder = null ): void {
		$is_c2b = '' !== (string) ( $info['RefundODNO'] ?? '' ) || 'C2B' === (string) ( $info['LgsType'] ?? '' );
		$ref    = $is_c2b
			? (string) ( $info['RefundODNO'] ?? '' ) . (string) ( $info['ValidationNo'] ?? '' )
			: (string) ( $info['ShipTradeNo'] ?? '' );

		$order  = Store::find_order( $ref );
		$record = $order ? Store::find( $order, $ref ) : null;

		if ( null === $order && ! $is_c2b && null !== $fallback_finder && '' !== $ref ) {
			$found = $fallback_finder( $ref );
			$order = $found instanceof \WC_Order ? $found : null;
		}
		if ( null === $order ) {
			PayuniShipping::log(
				sprintf(
					'PAYUNi return status notice without a matching order: LgsType=%s RefundODNO=%s ShipTradeNo=%s Status=%s',
					(string) ( $info['LgsType'] ?? '' ),
					(string) ( $info['RefundODNO'] ?? '' ),
					(string) ( $info['ShipTradeNo'] ?? '' ),
					(string) ( $info['ShipStatus'] ?? '' )
				)
			);
			return;
		}
		if ( null === $record ) {
			// 不是從外掛建立的黑貓退貨（沿用原單序號）：記成一筆退貨紀錄，之後同樣的通知才能去重。
			$record = [
				'type' => Store::TYPE_HOME,
				'ref'  => $ref,
			];
			Store::add( $order, $record );
			$order->save();
		}
		self::apply( $order, $record, $info );
	}

	/**
	 * 把通知或查詢結果寫進退貨紀錄；貨態有變才寫備註。
	 *
	 * @param \WC_Order            $order  訂單。
	 * @param array<string, mixed> $record 退貨紀錄。
	 * @param array<string, mixed> $info   通知或查詢結果。
	 */
	public static function apply( \WC_Order $order, array $record, array $info ): void {
		// 查詢要等統一金流回應，期間可能有別的退貨寫進訂單：重新讀一次再寫，避免整包覆寫蓋掉。
		$fresh  = wc_get_order( $order->get_id() );
		$order  = $fresh instanceof \WC_Order ? $fresh : $order;
		$ref    = (string) ( $record['ref'] ?? '' );
		$record = Store::find( $order, $ref ) ?? $record;
		$fields = [
			'status'      => (string) ( $info['ShipStatus'] ?? '' ),
			'status_desc' => (string) ( $info['ShipStatusDesc'] ?? '' ),
			'status_time' => (string) ( $info['ShipStatusTime'] ?? '' ),
		];
		if ( '' !== (string) ( $info['OBTNumber'] ?? '' ) ) {
			$fields['obt_number'] = (string) $info['OBTNumber'];
		}
		$changed = (string) ( $record['status'] ?? '' ) !== $fields['status']
			|| (string) ( $record['status_time'] ?? '' ) !== $fields['status_time'];

		$updated = Store::update( $order, $ref, $fields );
		$order->save();

		if ( ! $changed || '' === $fields['status'] || '-' === $fields['status'] ) {
			return;
		}
		$is_c2b = Store::TYPE_C2B === ( $record['type'] ?? '' );
		$order->add_order_note(
			\Moksafowo\Order\Note::build(
				/* translators: %s: shipping status description */
				sprintf( __( 'PAYUNi return shipment status: %s', 'moksa-for-woocommerce' ), '' !== $fields['status_desc'] ? $fields['status_desc'] : $fields['status'] ),
				[
					__( 'Return code', 'moksa-for-woocommerce' )             => $is_c2b ? $ref : '',
					__( 'Shipping transaction ID', 'moksa-for-woocommerce' ) => $is_c2b ? '' : $ref,
					__( 'Return number', 'moksa-for-woocommerce' )           => (string) ( $fields['obt_number'] ?? ( $record['obt_number'] ?? '' ) ),
					__( 'Status code', 'moksa-for-woocommerce' )             => $fields['status'],
					__( 'Status updated', 'moksa-for-woocommerce' )          => $fields['status_time'],
				]
			)
		);

		/**
		 * 統一金流退貨貨態有變動（通知或手動查詢）。
		 *
		 * @param \WC_Order            $order  訂單。
		 * @param array<string, mixed> $record 更新後的退貨紀錄；不是外掛建立的退貨時只有 type、ref 與貨態。
		 */
		do_action( 'moksafowo_payuni_return_status', $order, $updated ?? array_merge( $record, $fields ) );
	}
}
