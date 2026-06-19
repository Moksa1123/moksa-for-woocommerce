<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Emails;

defined( 'ABSPATH' ) || exit;

final class EmailTrackingSection {

	public static function init(): void {
		add_action( 'moksafowo_shipping_email_tracking_info', [ __CLASS__, 'render' ], 10, 2 );
	}

	public static function render( $order, bool $plain_text = false ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$entries = self::collect_entries( $order );
		if ( empty( $entries ) ) {
			return;
		}

		if ( $plain_text ) {
			self::render_plain( $entries );
		} else {
			self::render_html( $entries );
		}
	}


	private static function collect_entries( \WC_Order $order ): array {
		$method_id = '';
		foreach ( $order->get_shipping_methods() as $m ) {
			$method_id = (string) $m->get_method_id();
			break;
		}
		if ( '' === $method_id ) {
			return [];
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		$entries = apply_filters( 'moksafowo_shipping_tracking_entries', [], $order, $method_id );
		return is_array( $entries ) ? $entries : [];
	}

	private static function render_html( array $entries ): void {
		echo '<h2 style="color:#1f2937;font-size:18px;margin:24px 0 10px;">' . esc_html__( '貨態查詢', 'mo-ectools' ) . '</h2>';
		echo '<table cellspacing="0" cellpadding="8" border="1" style="width:100%;border-collapse:collapse;border-color:#e5e7eb;margin-bottom:16px;">';
		echo '<thead><tr style="background:#f8fafc;">';
		echo '<th align="left">' . esc_html__( '物流商', 'mo-ectools' ) . '</th>';
		echo '<th align="left">' . esc_html__( '貨號', 'mo-ectools' ) . '</th>';
		echo '<th align="left">' . esc_html__( '查詢連結', 'mo-ectools' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $entries as $e ) {
			$is_direct = 'direct' === $e['mode'];
			$carrier   = '' !== $e['temp_label'] ? $e['carrier'] . '（' . $e['temp_label'] . '）' : $e['carrier'];
			$link_text = $is_direct
				? __( '一鍵查詢', 'mo-ectools' )
				: __( '前往查詢頁', 'mo-ectools' );
			echo '<tr>';
			echo '<td>' . esc_html( $carrier ) . '</td>';
			echo '<td style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;">' . esc_html( $e['tracking_no'] ) . '</td>';
			echo '<td><a href="' . esc_url( $e['url'] ) . '" target="_blank" rel="noopener noreferrer" style="color:#1d4ed8;">' . esc_html( $link_text ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p style="font-size:12px;color:#64748b;margin:-8px 0 16px;">' . esc_html__( '※ 黑貓宅急便為一鍵查詢，其他物流請複製貨號後到該物流網站貼上查詢。', 'mo-ectools' ) . '</p>';
	}

	private static function render_plain( array $entries ): void {
		echo "\n= " . esc_html__( '貨態查詢', 'mo-ectools' ) . " =\n\n";
		foreach ( $entries as $e ) {
			$carrier = '' !== $e['temp_label'] ? $e['carrier'] . '（' . $e['temp_label'] . '）' : $e['carrier'];
			echo esc_html( $carrier ) . " — \n";
			if ( '' !== $e['tracking_no'] ) {
				echo '  ' . esc_html__( '貨號：', 'mo-ectools' ) . esc_html( $e['tracking_no'] ) . "\n";
			}
			echo '  ' . esc_html__( '查詢：', 'mo-ectools' ) . esc_url( $e['url'] ) . "\n\n";
		}
	}
}
