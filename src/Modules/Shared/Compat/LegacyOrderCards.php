<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單頁「金流 / 物流 / 電子發票」卡片下方，唯讀列出舊外掛留下的發票、物流單與門市。
 * 同一個位置只能有一張卡，所以接在現有卡片後面；沒有卡片時自己開一張。
 */
final class LegacyOrderCards {

	public static function init(): void {
		\Moksafowo\Modules\Shared\Admin\OrderInfoLayout::boot();
		add_filter( 'moksafowo_order_info_cards', [ __CLASS__, 'add' ], 99, 2 );
	}

	/**
	 * @param array<int,array<string,mixed>> $cards 其他模組已放上的卡片。
	 * @param \WC_Order                      $order 訂單。
	 * @return array<int,array<string,mixed>>
	 */
	public static function add( array $cards, \WC_Order $order ): array {
		$invoice = LegacyOrderData::invoice( $order );
		if ( null !== $invoice ) {
			$rows  = [
				__( 'Invoice number', 'moksa-for-woocommerce' ) => $invoice['number'],
				__( 'Issued at', 'moksa-for-woocommerce' ) => $invoice['date'],
				__( 'Random code', 'moksa-for-woocommerce' ) => $invoice['random'],
			];
			$cards = self::append( $cards, 'invoice', __( 'E-invoice', 'moksa-for-woocommerce' ), self::block( $invoice['source'], [ $rows ] ) );
		}

		$tables = [];
		foreach ( LegacyOrderData::shipments( $order ) as $s ) {
			$tables[] = [
				__( 'Tracking number', 'moksa-for-woocommerce' ) => $s['tracking'],
				__( 'Shipping type', 'moksa-for-woocommerce' ) => $s['type'],
				__( 'Status', 'moksa-for-woocommerce' ) => $s['status'],
			];
			$source   = $s['source'];
		}
		$store = LegacyOrderData::store( $order );
		if ( null !== $store ) {
			$tables[] = [
				__( 'Pickup store', 'moksa-for-woocommerce' ) => trim( $store['name'] . ' (' . $store['id'] . ')' ),
				__( 'Store address', 'moksa-for-woocommerce' ) => $store['address'],
			];
		}
		if ( $tables ) {
			$cards = self::append( $cards, 'shipping', __( 'Shipping', 'moksa-for-woocommerce' ), self::block( $source ?? __( 'RY Tools', 'moksa-for-woocommerce' ), $tables ) );
		}
		return $cards;
	}

	/**
	 * @param string                          $source 舊外掛名稱。
	 * @param array<int,array<string,string>> $tables 每張表是「欄位 => 值」。
	 */
	private static function block( string $source, array $tables ): string {
		$html = '<div class="moksafowo-legacy-records" style="margin-top:10px;padding-top:8px;border-top:1px dashed #c3c4c7;">'
			. '<p style="margin:0 0 4px;color:#646970;font-size:12px;">'
			/* translators: %s: plugin name */
			. esc_html( sprintf( __( 'Recorded by the %s (read-only)', 'moksa-for-woocommerce' ), $source ) )
			. '</p>';
		foreach ( $tables as $rows ) {
			$html .= '<table style="width:100%;font-size:12px;table-layout:fixed;margin-bottom:4px;">';
			foreach ( $rows as $label => $value ) {
				if ( '' === (string) $value ) {
					continue;
				}
				$html .= '<tr><th style="text-align:left;">' . esc_html( (string) $label ) . '</th><td style="word-break:break-all;">' . esc_html( (string) $value ) . '</td></tr>';
			}
			$html .= '</table>';
		}
		return $html . '</div>';
	}

	/**
	 * @param array<int,array<string,mixed>> $cards 目前的卡片。
	 * @param string                         $slot  payment／shipping／invoice。
	 * @param string                         $title 沒有現成卡片時用的標題。
	 * @param string                         $html  要接上的內容。
	 * @return array<int,array<string,mixed>>
	 */
	private static function append( array $cards, string $slot, string $title, string $html ): array {
		// 同 slot 後者覆蓋，所以接在最後一張同 slot 的卡片上。
		foreach ( array_reverse( array_keys( $cards ) ) as $i ) {
			if ( ( $cards[ $i ]['slot'] ?? '' ) === $slot ) {
				$cards[ $i ]['html'] = (string) ( $cards[ $i ]['html'] ?? '' ) . $html;
				return $cards;
			}
		}
		$cards[] = [
			'slot'  => $slot,
			'title' => $title,
			'html'  => $html,
		];
		return $cards;
	}
}
