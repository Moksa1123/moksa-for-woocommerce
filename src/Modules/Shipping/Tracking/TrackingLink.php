<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Tracking;

defined( 'ABSPATH' ) || exit;

final class TrackingLink {


	public static function for_ecpay_record( array $record ): ?array {
		$subtype = (string) ( $record['subtype'] ?? '' );
		$booking = (string) ( $record['booking_note'] ?? '' );
		$pay     = (string) ( $record['cvs_payment_no'] ?? '' );
		// 7-11 店到店的查詢號碼是寄件代碼加驗證碼（共 12 碼），只給寄件代碼查不到。
		if ( 'UNIMARTC2C' === $subtype && '' !== $pay ) {
			$pay .= (string) ( $record['cvs_validation_no'] ?? '' );
		}
		$tracking_no = '' !== $booking ? $booking : $pay;

		return self::resolve_carrier_link( $subtype, $tracking_no );
	}

	public static function for_payuni_record( array $record ): ?array {
		$ship_type   = (string) ( $record['ship_type'] ?? '' );
		$tracking_no = self::payuni_tracking_no( $record );
		switch ( $ship_type ) {
			case '2':
				return self::resolve_carrier_link( 'TCAT', $tracking_no );
			case '1':
				return self::resolve_carrier_link( 'UNIMART', $tracking_no );
		}
		return null;
	}

	/**
	 * PAYUNi 7-11 查詢號碼：店到店是 Odno＋驗證碼（12 碼）、大宗是 PartnerId＋Odno（11 碼）；
	 * 黑貓是 Odno。單筆舊訂單直接帶已組好的 ship_no。
	 */
	private static function payuni_tracking_no( array $record ): string {
		$ship_no = (string) ( $record['ship_no'] ?? '' );
		if ( '' !== $ship_no ) {
			return $ship_no;
		}
		$odno = (string) ( $record['odno'] ?? '' );
		if ( '1' !== (string) ( $record['ship_type'] ?? '' ) || '' === $odno ) {
			return $odno;
		}
		$lgs = (string) ( $record['lgs_type'] ?? '' );
		if ( 'C2C' === $lgs ) {
			return $odno . (string) ( $record['validation_no'] ?? '' );
		}
		if ( 'B2C' === $lgs ) {
			return (string) ( $record['partner_id'] ?? '' ) . $odno;
		}
		return $odno;
	}

	public static function for_smilepay_record( array $record ): ?array {
		$subtype  = (string) ( $record['subtype'] ?? '' );
		$lgs_type = (string) ( $record['lgs_type'] ?? '' );

		if ( 'TCAT' === $subtype || '黑貓' === $lgs_type ) {
			$tracking_no = (string) ( $record['track_num'] ?? $record['track_no'] ?? '' );
			return self::resolve_carrier_link( 'TCAT', $tracking_no );
		}
		if ( 'UNIMART' === $subtype || 'UNIMARTC2C' === $subtype || '7-11' === $lgs_type ) {
			$tracking_no = (string) ( $record['pay_no'] ?? '' );
			return self::resolve_carrier_link( 'UNIMART', $tracking_no );
		}
		if ( 'FAMI' === $subtype || 'FAMIC2C' === $subtype || '全家' === $lgs_type ) {
			$tracking_no = (string) ( $record['pay_no'] ?? '' );
			return self::resolve_carrier_link( 'FAMI', $tracking_no );
		}
		return null;
	}


	/**
	 * 藍新的物流資料是平鋪在 order meta 上（不像綠界是 records 陣列），
	 * 所以呼叫端要自己組成 [ 'ship_type' => …, 'lgs_no' => … ] 再傳進來。
	 *
	 * NDNS ShipType：1=7-ELEVEN、2=全家、3=萊爾富、4=OK mart。
	 *
	 * @param array<string,mixed> $record 物流資料。
	 */
	public static function for_newebpay_record( array $record ): ?array {
		$tracking_no = (string) ( $record['lgs_no'] ?? '' );
		switch ( (string) ( $record['ship_type'] ?? '' ) ) {
			case '1':
				return self::resolve_carrier_link( 'UNIMART', $tracking_no );
			case '2':
				return self::resolve_carrier_link( 'FAMI', $tracking_no );
			case '3':
				return self::resolve_carrier_link( 'HILIFE', $tracking_no );
			case '4':
				return self::resolve_carrier_link( 'OKMART', $tracking_no );
		}
		return null;
	}


	private static function resolve_carrier_link( string $subtype, string $tracking_no ): ?array {
		switch ( $subtype ) {
			case 'TCAT':
				if ( '' === $tracking_no ) {
					return null;
				}
				return [
					'mode'        => 'direct',
					'url'         => 'https://www.t-cat.com.tw/inquire/trace.aspx?method=result&billID=' . rawurlencode( $tracking_no ),
					'carrier'     => __( 'T-Cat home delivery', 'moksa-for-woocommerce' ),
					'tracking_no' => $tracking_no,
				];
			case 'UNIMART':
			case 'UNIMARTC2C':
			case 'UNIMARTFREEZE':
				return [
					'mode'        => 'prefill-needed',
					'url'         => 'https://eservice.7-11.com.tw/E-Tracking/search.aspx',
					'carrier'     => __( '7-ELEVEN', 'moksa-for-woocommerce' ),
					'tracking_no' => $tracking_no,
				];
			case 'FAMI':
			case 'FAMIC2C':
				return [
					'mode'        => 'prefill-needed',
					'url'         => 'https://fmec.famiport.com.tw/FP_Entrance/QueryBox',
					'carrier'     => __( 'FamilyMart', 'moksa-for-woocommerce' ),
					'tracking_no' => $tracking_no,
				];
			case 'HILIFE':
			case 'HILIFEC2C':
				return [
					'mode'        => 'prefill-needed',
					'url'         => 'https://www.hilife.com.tw/serviceInfo_search.aspx',
					'carrier'     => __( 'Hi-Life', 'moksa-for-woocommerce' ),
					'tracking_no' => $tracking_no,
				];
			case 'OKMART':
			case 'OKMARTC2C':
				return [
					'mode'        => 'prefill-needed',
					'url'         => 'https://ecservice.okmart.com.tw/Tracking/Search',
					'carrier'     => __( 'OK Mart', 'moksa-for-woocommerce' ),
					'tracking_no' => $tracking_no,
				];
			case 'POST':
				return [
					'mode'        => 'prefill-needed',
					'url'         => 'https://postserv.post.gov.tw/pstmail/main_mail.html',
					'carrier'     => __( 'Chunghwa Post', 'moksa-for-woocommerce' ),
					'tracking_no' => $tracking_no,
				];
		}
		return null;
	}


	public static function kses_allowlist(): array {
		return [
			'span'   => [
				'class' => true,
				'style' => true,
			],
			'code'   => [
				'class' => true,
				'style' => true,
			],
			'button' => [
				'type'          => true,
				'class'         => true,
				'style'         => true,
				'title'         => true,
				'data-tracking' => true,
			],
			'a'      => [
				'href'   => true,
				'target' => true,
				'rel'    => true,
				'class'  => true,
				'style'  => true,
			],
			'svg'    => [
				'xmlns'       => true,
				'viewbox'     => true,
				'width'       => true,
				'height'      => true,
				'fill'        => true,
				'aria-hidden' => true,
				'style'       => true,
			],
			'path'   => [
				'd'         => true,
				'fill'      => true,
				'fill-rule' => true,
				'clip-rule' => true,
			],
			'strong' => [],
			'br'     => [],
		];
	}

	public static function render_button_html( array $info ): string {
		$direct = 'direct' === $info['mode'];
		$url    = (string) $info['url'];
		$tn     = (string) $info['tracking_no'];

		ob_start();
		?>
		<span class="moksafowo-tracking-link" style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;">
			<?php if ( '' !== $tn && ! $direct ) : ?>
				<code class="moksafowo-tracking-no" style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;background:#f1f5f9;padding:2px 8px;border-radius:3px;color:#0f172a;"><?php echo esc_html( $tn ); ?></code>
				<button type="button"
	class="moksafowo-tracking-copy"
					data-tracking="<?php echo esc_attr( $tn ); ?>"
					title="<?php esc_attr_e( 'Copy tracking number', 'moksa-for-woocommerce' ); ?>"
					style="background:#fff;border:1px solid #cbd5e1;border-radius:3px;cursor:pointer;padding:2px 8px;font-size:11px;line-height:1.4;color:#475569;"><?php esc_html_e( 'Copy', 'moksa-for-woocommerce' ); ?></button>
			<?php endif; ?>
			<a href="<?php echo esc_url( $url ); ?>"
				target="_blank"
				rel="noopener noreferrer"
	class="moksafowo-tracking-btn"
				style="display:inline-flex;align-items:center;background:#fff;color:#1f2937;text-decoration:none;padding:3px 10px;border:1px solid #cbd5e1;border-radius:3px;font-size:12px;line-height:1.4;">
				<?php
				if ( $direct ) {
					/* translators: %s: carrier name */
					echo esc_html( sprintf( __( 'Track %s shipment', 'moksa-for-woocommerce' ), $info['carrier'] ) );
				} else {
					/* translators: %s: carrier name */
					echo esc_html( sprintf( __( 'Open %s tracking', 'moksa-for-woocommerce' ), $info['carrier'] ) );
				}
				?>
			</a>
		</span>
		<?php
		return (string) ob_get_clean();
	}

	public static function copy_script(): string {
		return <<<'JS'
( function () {
	document.addEventListener( 'click', function ( e ) {
	const btn = e.target.closest( '.moksafowo-tracking-copy' );
		if ( ! btn ) { return; }
		e.preventDefault();
	const tn = btn.getAttribute( 'data-tracking' ) || '';
		if ( ! tn ) { return; }
	const fallback = function () {
	const ta = document.createElement( 'textarea' );
			ta.value = tn;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			try { document.execCommand( 'copy' ); } catch ( _ ) {}
			document.body.removeChild( ta );
		};
	const flash = function () {
	const orig = btn.textContent;
			btn.textContent = '已複製';
			btn.style.background = '#dcfce7';
			btn.style.borderColor = '#86efac';
			btn.style.color = '#16a34a';
			setTimeout( function () {
				btn.textContent = orig;
				btn.style.background = '';
				btn.style.borderColor = '';
				btn.style.color = '';
			}, 1200 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( tn ).then( flash, fallback );
		} else {
			fallback();
			flash();
		}
	} );
} )();
JS;
	}
}
