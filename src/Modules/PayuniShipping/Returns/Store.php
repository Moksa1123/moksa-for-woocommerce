<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Returns;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單上的統一金流退貨紀錄。
 *
 * 每筆紀錄存在 Keys::PAYUNI_RETURNS 陣列裡；另外每筆寫一列 Keys::PAYUNI_RETURN_REF（同一個 key 可以有多列），
 * 讓貨態通知用精確比對反查訂單。7-11 退貨便的 ref 是 12 碼（退貨便編號＋驗證碼），兩段都要對才找得到；
 * 黑貓退貨的 ref 是退貨單的物流序號。
 */
final class Store {

	public const TYPE_C2B  = 'c2b';
	public const TYPE_HOME = 'home';

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( \WC_Order $order ): array {
		$raw = $order->get_meta( Keys::PAYUNI_RETURNS );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		return array_values( array_filter( $raw, 'is_array' ) );
	}

	/**
	 * 加一筆紀錄並寫反查索引。呼叫端負責 save。
	 *
	 * @param \WC_Order            $order  訂單。
	 * @param array<string, mixed> $record 退貨紀錄。
	 */
	public static function add( \WC_Order $order, array $record ): void {
		$records   = self::all( $order );
		$records[] = $record;
		$order->update_meta_data( Keys::PAYUNI_RETURNS, $records );
		$ref = (string) ( $record['ref'] ?? '' );
		if ( '' !== $ref ) {
			$order->add_meta_data( Keys::PAYUNI_RETURN_REF, $ref, false );
		}
	}

	/**
	 * 更新 ref 相同的那筆紀錄。呼叫端負責 save。
	 *
	 * @param \WC_Order            $order  訂單。
	 * @param string               $ref    退貨 ref。
	 * @param array<string, mixed> $fields 要更新的欄位。
	 * @return array<string, mixed>|null 更新後的紀錄；找不到時 null。
	 */
	public static function update( \WC_Order $order, string $ref, array $fields ): ?array {
		$records = self::all( $order );
		foreach ( $records as $i => $record ) {
			if ( (string) ( $record['ref'] ?? '' ) === $ref ) {
				$records[ $i ] = array_merge( $record, $fields );
				$order->update_meta_data( Keys::PAYUNI_RETURNS, $records );
				return $records[ $i ];
			}
		}
		return null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find( \WC_Order $order, string $ref ): ?array {
		foreach ( self::all( $order ) as $record ) {
			if ( (string) ( $record['ref'] ?? '' ) === $ref ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * 用退貨 ref 找訂單（精確比對，走查單索引）。
	 */
	public static function find_order( string $ref ): ?\WC_Order {
		if ( '' === $ref ) {
			return null;
		}
		$ids = \Moksafowo\Order\Lookup::orders(
			[
				'limit'                            => 2,
				'return'                           => 'ids',
				'type'                             => 'shop_order',
				'status'                           => 'all',
				\Moksafowo\Order\Lookup::QUERY_VAR => [
					'key'   => Keys::PAYUNI_RETURN_REF,
					'value' => $ref,
				],
			]
		);
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order && null !== self::find( $order, $ref ) ) {
				return $order;
			}
		}
		return null;
	}
}
