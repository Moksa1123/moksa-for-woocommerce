<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Frontend;

defined( 'ABSPATH' ) || exit;


final class PaymentInfoBox {


	private static array $resolvers = [];

	private static bool $email_registered = false;


	public static function register( callable $resolver ): void {
		self::$resolvers[] = $resolver;

		// 獨立繳費通知 Email — 只註冊一次（多個 provider 共用同一封）。
		if ( ! self::$email_registered ) {
			self::$email_registered = true;
			add_filter(
				'woocommerce_email_classes',
				static function ( array $emails ): array {
					$emails['MO_Payment_Info'] = new \Moksafowo\Modules\Shared\Email\PaymentInfoEmail();
					return $emails;
				}
			);
			// WC 11 的區塊信件編輯器只認一份白名單，沒加進去的信件在新介面
			// 沒有「編輯 / 預覽 / 測試信」，商家等於不能自訂內容。
			add_filter(
				'woocommerce_transactional_emails_for_block_editor',
				static function ( array $ids ): array {
					$ids[] = 'moksafowo_payment_info';
					return $ids;
				}
			);
		}

		$render = static function ( $order ) use ( $resolver ): void {
			if ( ! $order instanceof \WC_Order || ! self::awaiting_payment( $order ) ) {
				return;
			}
			// 傳統佈景的感謝頁兩個 hook 都會觸發，同一張訂單只畫一次。
			static $rendered = [];
			if ( isset( $rendered[ $order->get_id() ] ) ) {
				return;
			}
			$rows = $resolver( $order );
			if ( ! empty( $rows ) ) {
				$rendered[ $order->get_id() ] = true;
			}
			if ( ! empty( $rows ) ) {
				echo wp_kses( self::render_html( $rows ), self::kses_allowlist() );
			}
		};

		// Thankyou 頁（傳 order_id）。
		add_action(
			'woocommerce_thankyou',
			static function ( $order_id ) use ( $render ): void {
				$render( wc_get_order( (int) $order_id ) );
			},
			15
		);
		// my-account / view-order 頁（傳 WC_Order）。
		add_action( 'woocommerce_order_details_after_order_table', $render, 15, 1 );
	}


	/** 已取消、失敗、已退款的訂單不該再叫顧客去繳費。 */
	private static function awaiting_payment( \WC_Order $order ): bool {
		return ! $order->has_status( [ 'cancelled', 'failed', 'refunded' ] );
	}

	public static function rows( \WC_Order $order ): array {
		if ( ! self::awaiting_payment( $order ) ) {
			return [];
		}
		foreach ( self::$resolvers as $resolver ) {
			$rows = $resolver( $order );
			if ( ! empty( $rows ) ) {
				foreach ( $rows as &$row ) {
					if ( 'deadline' === ( $row['key'] ?? '' ) ) {
						$row['value'] = self::format_deadline( (string) ( $row['value'] ?? '' ) );
					}
				}
				unset( $row );
				return $rows;
			}
		}
		return [];
	}

	/**
	 * 各家的繳費期限格式都不一樣（2026/10/20、2026-10-20、20261020、帶或不帶時間），
	 * 統一照 WC 的日期設定顯示。原值有時間才顯示時間，不自己補；解析不了就原樣顯示。
	 */
	private static function format_deadline( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( 1 === preg_match( '/^(\d{4})(\d{2})(\d{2})(?:(\d{2})(\d{2})(\d{2}))?$/', $raw, $m ) ) {
			$raw = $m[1] . '-' . $m[2] . '-' . $m[3] . ( isset( $m[4] ) ? ' ' . $m[4] . ':' . $m[5] . ':' . $m[6] : '' );
		}
		$has_time = 1 === preg_match( '/\d{1,2}:\d{2}/', $raw );
		try {
			$tz = new \DateTimeZone( 'Asia/Taipei' );
			$dt = new \DateTimeImmutable( $raw, $tz );
		} catch ( \Exception $e ) {
			return $raw;
		}
		return (string) wp_date( wc_date_format() . ( $has_time ? ' ' . wc_time_format() : '' ), $dt->getTimestamp(), $tz );
	}


	public static function render_html( array $rows, bool $with_intro = true ): string {
		ob_start();
		echo '<section class="moksafowo-payment-info woocommerce-order-overview" style="margin:1.5em 0;padding:1em 1.25em;border:1px solid #e0e0e0;border-radius:6px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Payment details', 'moksa-for-woocommerce' ) . '</h2>';
		// 信件自己已經有一句一樣的提醒，信裡不再印。
		if ( $with_intro ) {
			echo '<p style="color:#646970;font-size:13px;margin-top:-.5em;">' . esc_html__( 'Please use the details below to pay before the deadline.', 'moksa-for-woocommerce' ) . '</p>';
		}
		$has_barcode = false;
		foreach ( $rows as $row ) {
			if ( str_starts_with( (string) ( $row['key'] ?? '' ), 'barcode_' ) ) {
				$has_barcode = true;
				break;
			}
		}
		if ( $has_barcode ) {
			echo '<p style="font-size:13px;">' . esc_html__( 'Show the barcodes below to the convenience store clerk to scan.', 'moksa-for-woocommerce' ) . '</p>';
		}
		echo '<ul style="list-style:none;padding:0;margin:0;">';
		foreach ( $rows as $row ) {
			$label = isset( $row['label'] ) ? (string) $row['label'] : '';
			$value = isset( $row['value'] ) ? (string) $row['value'] : '';
			if ( '' === $value ) {
				continue;
			}
			// 帶 url 的列（例如錢包付款連結）畫成可點的連結；value 仍是網址本身，純文字信也看得到。
			if ( '' !== (string) ( $row['url'] ?? '' ) ) {
				echo '<li style="margin:.35em 0;"><strong>' . esc_html( $label ) . '：</strong><a href="' . esc_url( (string) $row['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open the payment page', 'moksa-for-woocommerce' ) . '</a></li>';
				continue;
			}
			// 條碼列在文字下方畫出可掃描的條碼，文字留著當備用。
			$barcode = str_starts_with( (string) ( $row['key'] ?? '' ), 'barcode_' ) ? Code39Barcode::html( $value ) : '';
			echo '<li style="margin:.35em 0;"><strong>' . esc_html( $label ) . '：</strong><span style="font-family:monospace;">' . esc_html( $value ) . '</span>' . wp_kses( $barcode, self::kses_allowlist() ) . '</li>';
		}
		echo '</ul>';
		echo '</section>';
		return (string) ob_get_clean();
	}


	/**
	 * kses allowlist for the 繳費資訊 markup emitted by render_html()
	 * （static structure；所有動態值在 render_html 內已 esc_html）。
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function kses_allowlist(): array {
		$style = [
			'class' => true,
			'style' => true,
		];
		return [
			'section' => $style,
			'h2'      => $style,
			'p'       => $style,
			'ul'      => $style,
			'li'      => $style,
			'span'    => $style,
			'strong'  => [],
			'a'       => [
				'href'   => true,
				'target' => true,
				'rel'    => true,
			],
			'div'     => $style,
			'table'   => [
				'role'        => true,
				'width'       => true,
				'cellpadding' => true,
				'cellspacing' => true,
				'border'      => true,
				'style'       => true,
			],
			'tr'      => [],
			'td'      => [
				'width'   => true,
				'height'  => true,
				'bgcolor' => true,
				'style'   => true,
			],
		];
	}
}
