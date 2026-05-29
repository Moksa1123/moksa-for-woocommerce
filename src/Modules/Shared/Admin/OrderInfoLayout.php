<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared\Admin;

defined( 'ABSPATH' ) || exit;

final class OrderInfoLayout {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_box' ] );
		add_action( 'admin_print_styles-post.php', [ __CLASS__, 'styles' ] );
		add_action( 'admin_print_styles-post-new.php', [ __CLASS__, 'styles' ] );
		add_action( 'admin_print_styles-woocommerce_page_wc-orders', [ __CLASS__, 'styles' ] );
	}

	public static function register_meta_box(): void {
		$screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
		foreach ( $screens as $screen ) {
			add_meta_box(
				'mowp_order_info',
				__( '金流 / 物流 / 電子發票', 'mo-ectools' ),
				[ __CLASS__, 'render' ],
				$screen,
				'normal',
				'high'
			);
		}
	}

	private const SLOT_ORDER = [ 'payment', 'shipping', 'invoice' ];

	public static function render( $order_or_post ): void {
		$order = self::resolve_order( $order_or_post );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}


		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		$cards = (array) apply_filters( 'mo_order_info_cards', [], $order );

		// 依 slot 分配（同 slot 多個 callback 後者覆蓋，allow override pattern）
		$by_slot = [];
		foreach ( $cards as $card ) {
			if ( ! is_array( $card ) || empty( $card['slot'] ) ) {
				continue;
			}
			$slot = (string) $card['slot'];
			if ( ! in_array( $slot, self::SLOT_ORDER, true ) ) {
				continue;
			}
			if ( empty( $card['html'] ) ) {
				continue;
			}
			$by_slot[ $slot ] = $card;
		}

		echo '<div class="mowp-order-info-grid">';
		foreach ( self::SLOT_ORDER as $slot ) {
			$card = $by_slot[ $slot ] ?? null;
			if ( $card ) {
				echo '<div class="mowp-order-info-card mowp-order-info-card--' . esc_attr( $slot ) . '">';
				echo '<h4 class="mowp-order-info-card__title">' . esc_html( (string) ( $card['title'] ?? self::default_title( $slot ) ) ) . '</h4>';
				echo $card['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller responsibility, html assembled by provider module with esc_*.
				echo '</div>';
			} else {
				echo '<div class="mowp-order-info-card mowp-order-info-card--' . esc_attr( $slot ) . ' mowp-order-info-card--empty">';
				echo '<h4 class="mowp-order-info-card__title">' . esc_html( self::default_title( $slot ) ) . '</h4>';
				echo self::default_placeholder( $slot, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</div>';
			}
		}
		echo '</div>';
	}

	private static function default_title( string $slot ): string {
		return [
			'payment'  => __( '金流資訊', 'mo-ectools' ),
			'shipping' => __( '物流資訊', 'mo-ectools' ),
			'invoice'  => __( '發票資訊', 'mo-ectools' ),
		][ $slot ] ?? '';
	}

	private static function default_placeholder( string $slot, \WC_Order $order ): string {
		$method = (string) $order->get_payment_method();
		$method_title = (string) $order->get_payment_method_title();

		if ( 'payment' === $slot ) {
			// 貨到付款 — 走通用 COD 卡片
			if ( 'cod' === $method ) {
				$amount = (int) round( (float) $order->get_total() );
				$shipping = $order->get_shipping_methods();
				$is_cvs = false;
				foreach ( $shipping as $sm ) {
					if ( str_contains( (string) $sm->get_method_id(), '_cvs_' ) ) {
						$is_cvs = true;
						break;
					}
				}
				// CVS 取貨：顧客自己到門市付款
				// 宅配：宅配員送到家時當場付款
				$where = $is_cvs
					? __( '顧客至超商取貨時於門市付款。', 'mo-ectools' )
					: __( '宅配送達時由顧客當場付款給宅配員。', 'mo-ectools' );
				return sprintf(
					'<p><strong>%s</strong>%s</p><p><strong>%s</strong>NT$%d</p><p style="color:#646970;font-size:12px;">%s</p>',
					esc_html__( '付款方式：', 'mo-ectools' ),
					esc_html__( '貨到付款（COD）', 'mo-ectools' ),
					esc_html__( '應收金額：', 'mo-ectools' ),
					$amount,
					esc_html( $where )
				);
			}
			// 其他 gateway — 區分「mowp 模組但 card 未實作」vs「完全外掛 gateway」
			if ( '' !== $method_title ) {
				$is_mowp = str_starts_with( $method, 'mo_' ) || 'linepay-tw' === $method;
				$note = $is_mowp
					? __( '此付款方式由 mowp 處理但詳情卡尚未實作。', 'mo-ectools' )
					: __( '此付款方式未由 mowp 處理，無額外資訊可顯示。', 'mo-ectools' );
				return sprintf(
					'<p><strong>%s</strong>%s</p><p style="color:#646970;font-size:12px;">%s</p>',
					esc_html__( '付款方式：', 'mo-ectools' ),
					esc_html( $method_title ),
					esc_html( $note )
				);
			}
			// 完全未付款
			return '<p style="color:#646970;font-size:12px;">' . esc_html__( '尚未付款。', 'mo-ectools' ) . '</p>';
		}

		if ( 'shipping' === $slot ) {
			$methods = $order->get_shipping_methods();
			if ( empty( $methods ) ) {
				return '<p style="color:#646970;font-size:12px;">' . esc_html__( '此訂單無運送（虛擬商品 / 自取）。', 'mo-ectools' ) . '</p>';
			}
			// 區分「mowp 物流模組但 card 未實作」vs「完全外掛 method」
			$is_mowp = false;
			$titles = [];
			foreach ( $methods as $m ) {
				$mid = (string) $m->get_method_id();
				if ( str_starts_with( $mid, 'mo_' ) ) {
					$is_mowp = true;
				}
				$titles[] = $m->get_name();
			}
			$note = $is_mowp
				? __( '此物流由 mowp 處理但詳情卡尚未實作（例如 PAYUNi 物流）。', 'mo-ectools' )
				: __( '此運送方式未由 mowp 物流模組處理。', 'mo-ectools' );
			return sprintf(
				'<p><strong>%s</strong>%s</p><p style="color:#646970;font-size:12px;">%s</p>',
				esc_html__( '運送方式：', 'mo-ectools' ),
				esc_html( implode( ' / ', $titles ) ),
				esc_html( $note )
			);
		}

		if ( 'invoice' === $slot ) {
			return '<p style="color:#646970;font-size:12px;">' . esc_html__( '此訂單未啟用電子發票模組。', 'mo-ectools' ) . '</p>';
		}

		return '';
	}

	public static function styles(): void {
		echo '<style>
			#mowp_order_info .inside { padding: 0 12px 12px; }
			.mowp-order-info-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
			.mowp-order-info-card { background: #fafafa; border: 1px solid #e0e0e0; border-radius: 4px; padding: 14px 16px; min-width: 0; }
			.mowp-order-info-card__title { margin: 0 0 10px; font-size: 12px; font-weight: 600; text-transform: uppercase; color: #1d2327; letter-spacing: 0.6px; padding-bottom: 8px; border-bottom: 1px solid #e0e0e0; }
			.mowp-order-info-card p { margin: 0.3em 0; font-size: 13px; word-break: break-word; }
			.mowp-order-info-card p:first-of-type { margin-top: 0; }
			.mowp-order-info-card .button { margin-top: 6px; }
			@media (max-width: 1280px) { .mowp-order-info-grid { grid-template-columns: 1fr 1fr; } }
			@media (max-width: 782px) { .mowp-order-info-grid { grid-template-columns: 1fr; } }
		</style>';
	}

	private static function resolve_order( $context ): ?\WC_Order {
		if ( $context instanceof \WC_Order ) {
			return $context;
		}
		if ( $context instanceof \WP_Post ) {
			$o = wc_get_order( $context->ID );
			return $o instanceof \WC_Order ? $o : null;
		}
		if ( is_numeric( $context ) ) {
			$o = wc_get_order( (int) $context );
			return $o instanceof \WC_Order ? $o : null;
		}
		return null;
	}
}
