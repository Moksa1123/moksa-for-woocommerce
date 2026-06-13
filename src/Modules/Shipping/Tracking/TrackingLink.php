<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Tracking;

defined( 'ABSPATH' ) || exit;

final class TrackingLink {

	
	public static function for_ecpay_record( array $record ): ?array {
		$subtype = (string) ( $record['subtype'] ?? '' );
		$booking = (string) ( $record['booking_note'] ?? '' );
		$pay     = (string) ( $record['cvs_payment_no'] ?? '' );
		// CVS 訂單：booking_note 為主，否則 fallback 取貨碼（CVS C2C 才填）
		$tracking_no = '' !== $booking ? $booking : $pay;

		return self::resolve_carrier_link( $subtype, $tracking_no );
	}

	public static function for_payuni_record( array $record ): ?array {
		$ship_type   = (string) ( $record['ship_type'] ?? '' );
		$tracking_no = (string) ( $record['odno'] ?? '' );
		switch ( $ship_type ) {
			case '2':
				return self::resolve_carrier_link( 'TCAT', $tracking_no );
			case '1':
				return self::resolve_carrier_link( 'UNIMART', $tracking_no );
		}
		return null;
	}

	public static function for_smilepay_record( array $record ): ?array {
		$subtype  = (string) ( $record['subtype'] ?? '' );
		$lgs_type = (string) ( $record['lgs_type'] ?? '' );

		// 先看 subtype，再 fallback lgs_type 中文名（兩種寫入路徑都涵蓋）
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

	
	private static function resolve_carrier_link( string $subtype, string $tracking_no ): ?array {
		switch ( $subtype ) {
			case 'TCAT':
				if ( '' === $tracking_no ) {
					return null;
				}
				return [
					'mode'        => 'direct',
					'url'         => 'https://www.t-cat.com.tw/inquire/trace.aspx?method=result&billID=' . rawurlencode( $tracking_no ),
					'carrier'     => '黑貓宅急便',
					'tracking_no' => $tracking_no,
				];
			case 'UNIMART':
			case 'UNIMARTC2C':
			case 'UNIMARTFREEZE':
				return [
					'mode'        => 'prefill-needed',
					'url'         => 'https://eservice.7-11.com.tw/E-Tracking/search.aspx',
					'carrier'     => '7-11 統一超商',
					'tracking_no' => $tracking_no,
				];
			case 'FAMI':
			case 'FAMIC2C':
				return [
					'mode'        => 'prefill-needed',
					'url'         => 'https://fmec.famiport.com.tw/FP_Entrance/QueryBox',
					'carrier'     => '全家便利商店',
					'tracking_no' => $tracking_no,
				];
			case 'HILIFE':
			case 'HILIFEC2C':
				return [
					'mode'        => 'prefill-needed',
					'url'         => 'https://www.hilife.com.tw/serviceInfo_search.aspx',
					'carrier'     => '萊爾富',
					'tracking_no' => $tracking_no,
				];
			case 'OKMART':
			case 'OKMARTC2C':
				return [
					'mode'        => 'prefill-needed',
					'url'         => 'https://ecservice.okmart.com.tw/Tracking/Search',
					'carrier'     => 'OK 超商',
					'tracking_no' => $tracking_no,
				];
			case 'POST':
				return [
					'mode'        => 'prefill-needed',
					'url'         => 'https://postserv.post.gov.tw/pstmail/main_mail.html',
					'carrier'     => '中華郵政',
					'tracking_no' => $tracking_no,
				];
		}
		return null;
	}

	
	/**
	 * render_button_html 輸出的 kses allowlist — 各 echo 端統一使用。
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function kses_allowlist(): array {
		return [
			'span'   => [ 'class' => true, 'style' => true ],
			'code'   => [ 'class' => true, 'style' => true ],
			'button' => [ 'type' => true, 'class' => true, 'style' => true, 'title' => true, 'data-tracking' => true ],
			'a'      => [ 'href' => true, 'target' => true, 'rel' => true, 'class' => true, 'style' => true ],
			'svg'    => [ 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'aria-hidden' => true, 'style' => true ],
			'path'   => [ 'd' => true, 'fill' => true, 'fill-rule' => true, 'clip-rule' => true ],
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
					title="<?php esc_attr_e( '複製貨號', 'mo-ectools' ); ?>"
					style="background:#fff;border:1px solid #cbd5e1;border-radius:3px;cursor:pointer;padding:2px 8px;font-size:11px;line-height:1.4;color:#475569;"><?php esc_html_e( '複製', 'mo-ectools' ); ?></button>
			<?php endif; ?>
			<a href="<?php echo esc_url( $url ); ?>"
				target="_blank"
				rel="noopener noreferrer"
	class="moksafowo-tracking-btn"
				style="display:inline-flex;align-items:center;background:#fff;color:#1f2937;text-decoration:none;padding:3px 10px;border:1px solid #cbd5e1;border-radius:3px;font-size:12px;line-height:1.4;">
				<?php
				if ( $direct ) {
					/* translators: %s: carrier name */
					echo esc_html( sprintf( __( '查詢 %s 貨態', 'mo-ectools' ), $info['carrier'] ) );
				} else {
					/* translators: %s: carrier name */
					echo esc_html( sprintf( __( '前往 %s 查詢', 'mo-ectools' ), $info['carrier'] ) );
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
