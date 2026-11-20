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
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	/**
	 * 綠界官方或 woomp 開的舊發票能從這裡作廢、折讓：Moksa 的綠界發票模組要開著，用的是同一組綠界帳號。
	 *
	 * @param array<string,mixed> $invoice LegacyOrderData::invoice_record() 的回傳。
	 * @param string              $cap     Caps 常數。
	 */
	private static function can( array $invoice, string $cap ): bool {
		return LegacyOrderData::ecpay_actionable( $invoice )
			&& 'yes' === get_option( 'moksafowo_ecpay_invoice_enabled', 'no' )
			&& \Moksafowo\Modules\Shared\Caps::user_can( $cap );
	}

	public static function enqueue( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		if ( 'yes' !== get_option( 'moksafowo_ecpay_invoice_enabled', 'no' ) ) {
			return;
		}
		wp_register_script( 'moksafowo-legacy-invoice-void', false, [], MOKSAFOWO_VERSION, true );
		wp_enqueue_script( 'moksafowo-legacy-invoice-void' );
		wp_add_inline_script(
			'moksafowo-legacy-invoice-void',
			'window.moksafowoLegacyVoid=' . wp_json_encode(
				[
					'ajax'    => admin_url( 'admin-ajax.php' ),
					'void'    => [
						'action' => 'moksafowo_ecpay_legacy_invoice_void',
						'nonce'  => wp_create_nonce( 'moksafowo_ecpay_legacy_invoice_void' ),
						'field'  => 'reason',
						'prompt' => __( 'Reason for voiding (up to 20 characters). The invoice is voided at ECPay right away and cannot be undone.', 'moksa-for-woocommerce' ),
					],
					'allow'   => [
						'action' => 'moksafowo_ecpay_legacy_invoice_allowance',
						'nonce'  => wp_create_nonce( 'moksafowo_ecpay_legacy_invoice_allowance' ),
						'field'  => 'amount',
						'prompt' => __( 'Allowance amount (whole NT dollars). The allowance is issued at ECPay right away.', 'moksa-for-woocommerce' ),
					],
					'unallow' => [
						'action'  => 'moksafowo_ecpay_legacy_allowance_void',
						'nonce'   => wp_create_nonce( 'moksafowo_ecpay_legacy_allowance_void' ),
						'confirm' => __( 'Void this allowance at ECPay?', 'moksa-for-woocommerce' ),
					],
					'working' => __( 'Working…', 'moksa-for-woocommerce' ),
					'network' => __( 'The request failed. Please try again.', 'moksa-for-woocommerce' ),
				]
			) . ';' . <<<'JS'
document.addEventListener('click',function(e){
	var b=e.target.closest&&e.target.closest('.moksafowo-legacy-invoice-void,.moksafowo-legacy-invoice-allow,.moksafowo-legacy-allowance-void');
	if(!b){return;}
	e.preventDefault();
	if(b.disabled){return;}
	var c=window.moksafowoLegacyVoid,fd=new FormData(),kind=b.classList.contains('moksafowo-legacy-invoice-allow')?'allow':(b.classList.contains('moksafowo-legacy-allowance-void')?'unallow':'void'),k=c[kind];
	if(k.prompt){
		var v=window.prompt(k.prompt,'');
		if(v===null||!v.trim()){return;}
		fd.append(k.field,v.trim());
	}else{
		if(!window.confirm(k.confirm)){return;}
		fd.append('allowance_no',b.getAttribute('data-no'));
	}
	var label=b.textContent;
	fd.append('action',k.action);fd.append('nonce',k.nonce);fd.append('order_id',b.getAttribute('data-order-id'));
	b.disabled=true;b.textContent=c.working;
	fetch(c.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){
		if(r&&r.success){location.reload();return;}
		b.disabled=false;b.textContent=label;window.alert((r&&r.data&&r.data.message)||c.network);
	}).catch(function(){b.disabled=false;b.textContent=label;window.alert(c.network);});
});
JS
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $cards 其他模組已放上的卡片。
	 * @param \WC_Order                      $order 訂單。
	 * @return array<int,array<string,mixed>>
	 */
	public static function add( array $cards, \WC_Order $order ): array {
		$invoice = LegacyOrderData::invoice_record( $order );
		if ( null !== $invoice ) {
			$date = '' !== $invoice['date'] || 'woomp' !== $invoice['source_key']
				? $invoice['date']
				: LegacyOrderData::woomp_issue_date( $order, $invoice['number'] );
			$rows = [
				__( 'Invoice number', 'moksa-for-woocommerce' ) => $invoice['number'],
				__( 'Issued at', 'moksa-for-woocommerce' ) => $date,
				__( 'Random code', 'moksa-for-woocommerce' ) => $invoice['random'],
			];
			if ( 'woomp' === $invoice['source_key'] ) {
				$rows += LegacyOrderData::woomp_buyer( $order );
			}
			$allowances = \Moksafowo\Modules\EcpayInvoice\Operations\LegacyAllowance::class;
			$allowances = class_exists( $allowances ) ? $allowances::allowances( $order ) : [];
			foreach ( $allowances as $a ) {
				/* translators: %s: allowance number */
				$rows[ sprintf( __( 'Allowance %s', 'moksa-for-woocommerce' ), (string) ( $a['no'] ?? '' ) ) ] = 'NT$' . (int) ( $a['amount'] ?? 0 ) . ( empty( $a['voided'] ) ? '' : ' ' . __( '(voided)', 'moksa-for-woocommerce' ) );
			}
			if ( $invoice['voided'] ) {
				$rows[ __( 'Status', 'moksa-for-woocommerce' ) ] = sprintf(
					/* translators: %s: void time */
					__( 'Voided in Moksa at %s', 'moksa-for-woocommerce' ),
					(string) $order->get_meta( \Moksafowo\Order\Meta\Keys::LEGACY_INVOICE_VOIDED_AT )
				);
			}
			$html = self::block( $invoice['source'], [ $rows ] );
			$caps = \Moksafowo\Modules\Shared\Caps::class;
			if ( self::can( $invoice, $caps::INVOICE_ALLOWANCE ) ) {
				$html .= sprintf(
					'<button type="button" class="button button-small moksafowo-legacy-invoice-allow" data-order-id="%1$d">%2$s</button> ',
					(int) $order->get_id(),
					esc_html__( 'Issue an allowance', 'moksa-for-woocommerce' )
				);
			}
			if ( self::can( $invoice, $caps::INVOICE_VOID ) ) {
				foreach ( $allowances as $a ) {
					if ( empty( $a['voided'] ) ) {
						$html .= sprintf(
							'<button type="button" class="button button-small moksafowo-legacy-allowance-void" data-order-id="%1$d" data-no="%2$s">%3$s</button> ',
							(int) $order->get_id(),
							esc_attr( (string) ( $a['no'] ?? '' ) ),
							/* translators: %s: allowance number */
							esc_html( sprintf( __( 'Void allowance %s', 'moksa-for-woocommerce' ), (string) ( $a['no'] ?? '' ) ) )
						);
					}
				}
				$html .= sprintf(
					'<button type="button" class="button button-small moksafowo-legacy-invoice-void" data-order-id="%1$d">%2$s</button>',
					(int) $order->get_id(),
					esc_html__( 'Void this invoice', 'moksa-for-woocommerce' )
				);
			}
			$cards = self::append( $cards, 'invoice', __( 'E-invoice', 'moksa-for-woocommerce' ), $html );
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
