<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared\Frontend;

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
					$emails['MO_Payment_Info'] = new \MoksaWeb\Mowc\Modules\Shared\Email\PaymentInfoEmail();
					return $emails;
				}
			);
		}

		$render = static function ( $order ) use ( $resolver ): void {
			if ( ! $order instanceof \WC_Order ) {
				return;
			}
			$rows = $resolver( $order );
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


	public static function rows( \WC_Order $order ): array {
		foreach ( self::$resolvers as $resolver ) {
			$rows = $resolver( $order );
			if ( ! empty( $rows ) ) {
				return $rows;
			}
		}
		return [];
	}


	public static function render_html( array $rows ): string {
		ob_start();
		echo '<section class="moksafowo-payment-info woocommerce-order-overview" style="margin:1.5em 0;padding:1em 1.25em;border:1px solid #e0e0e0;border-radius:6px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( '繳費資訊', 'mo-ectools' ) . '</h2>';
		echo '<p style="color:#646970;font-size:13px;margin-top:-.5em;">' . esc_html__( '請於期限內以下列資訊完成付款。', 'mo-ectools' ) . '</p>';
		echo '<ul style="list-style:none;padding:0;margin:0;">';
		foreach ( $rows as $row ) {
			$label = isset( $row['label'] ) ? (string) $row['label'] : '';
			$value = isset( $row['value'] ) ? (string) $row['value'] : '';
			if ( '' === $value ) {
				continue;
			}
			echo '<li style="margin:.35em 0;"><strong>' . esc_html( $label ) . '：</strong><span style="font-family:monospace;">' . esc_html( $value ) . '</span></li>';
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
		];
	}
}
