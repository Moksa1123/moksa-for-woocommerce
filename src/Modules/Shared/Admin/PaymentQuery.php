<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * 金流「主動查詢」共用層。
 *
 * 每家金流各自實作一支查詢 API，但按鈕、權限、nonce、備註格式應該只有一份 ——
 * 否則會像先前的物流那樣，有的模組查完會轉狀態、有的只寫備註，商家看到的東西
 * 前後不一致。
 *
 * 模組接上去的方式：
 *   add_filter( 'moksafowo_payment_query_handlers', fn( $h ) => $h + [
 *       'moksafowo_newebpay_' => [ Foo::class, 'query' ],   // key 是 payment_method 前綴
 *   ] );
 *
 * callable 簽章：( \WC_Order $order ): array
 *   回傳 [ 'ok' => bool, 'message' => string, 'lines' => array<string,string> ]
 *   lines 是要顯示給商家看的「欄位 => 值」，同時也會寫進訂單備註。
 */
final class PaymentQuery {

	public const ACTION     = 'moksafowo_payment_query';
	public const NONCE      = 'moksafowo_payment_query';
	public const CAPABILITY = 'edit_shop_orders';

	public static function boot(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	/** @return array<string,callable> payment_method 前綴 => 查詢 callable */
	public static function handlers(): array {
		$out = [];
		foreach ( (array) apply_filters( 'moksafowo_payment_query_handlers', [] ) as $prefix => $cb ) {
			if ( is_callable( $cb ) ) {
				$out[ (string) $prefix ] = $cb;
			}
		}
		return $out;
	}

	private static function handler_for( \WC_Order $order ): ?callable {
		$method = (string) $order->get_payment_method();
		foreach ( self::handlers() as $prefix => $cb ) {
			if ( '' !== $prefix && str_starts_with( $method, $prefix ) ) {
				return $cb;
			}
		}
		return null;
	}

	/** 沒有對應的查詢器就不畫按鈕 —— 按了也沒用的按鈕比沒有更糟。 */
	public static function render_button( \WC_Order $order ): string {
		if ( null === self::handler_for( $order ) ) {
			return '';
		}
		return sprintf(
			'<p style="margin-top:10px;"><button type="button" class="button button-small moksafowo-payment-query" data-order-id="%1$s" data-nonce="%2$s">%3$s</button></p>',
			esc_attr( (string) $order->get_id() ),
			esc_attr( wp_create_nonce( self::NONCE ) ),
			esc_html__( 'Look up payment status', 'moksa-for-woocommerce' )
		);
	}

	public static function handle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}

		$cb = self::handler_for( $order );
		if ( null === $cb ) {
			wp_send_json_error( [ 'message' => __( 'This payment method cannot be looked up.', 'moksa-for-woocommerce' ) ], 400 );
		}

		try {
			$res = (array) $cb( $order );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 502 );
		}

		if ( empty( $res['ok'] ) ) {
			wp_send_json_error(
				[
					'message' => (string) ( $res['message'] ?? __( 'The payment provider could not be reached.', 'moksa-for-woocommerce' ) ),
				],
				502
			);
		}

		$lines = [];
		foreach ( (array) ( $res['lines'] ?? [] ) as $k => $v ) {
			$lines[] = sanitize_text_field( (string) $k ) . ': ' . sanitize_text_field( (string) $v );
		}
		$order->add_order_note(
			sprintf(
				/* translators: %s: the details returned by the payment provider */
				__( 'Payment status looked up — %s', 'moksa-for-woocommerce' ),
				$lines ? implode( ' / ', $lines ) : (string) ( $res['message'] ?? '' )
			)
		);
		$order->save();

		wp_send_json_success(
			[
				'message' => (string) ( $res['message'] ?? '' ),
				'lines'   => $lines,
			]
		);
	}

	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		wp_register_script( 'moksafowo-payment-query', false, [ 'jquery' ], MOKSAFOWO_VERSION, true );
		wp_enqueue_script( 'moksafowo-payment-query' );
		wp_localize_script(
			'moksafowo-payment-query',
			'moksafowo_payment_query',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'action'   => self::ACTION,
				'i18n'     => [
					'running' => __( 'Looking up…', 'moksa-for-woocommerce' ),
					'ok'      => __( 'Payment provider reports:', 'moksa-for-woocommerce' ),
					'fail'    => __( 'The payment status could not be looked up: ', 'moksa-for-woocommerce' ),
					'neterr'  => __( 'Connection error. Please try again later.', 'moksa-for-woocommerce' ),
				],
			]
		);
		wp_add_inline_script( 'moksafowo-payment-query', self::inline_js() );
	}

	private static function inline_js(): string {
		return <<<'JS'
jQuery( function ( $ ) {
	var cfg = window.moksafowo_payment_query || {};
	$( document ).on( 'click', '.moksafowo-payment-query', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var original = $btn.text();
		$btn.prop( 'disabled', true ).text( cfg.i18n.running );
		$.post( cfg.ajax_url, {
			action: cfg.action,
			order_id: $btn.data( 'order-id' ),
			nonce: $btn.data( 'nonce' )
		} ).done( function ( resp ) {
			if ( resp && resp.success && resp.data ) {
				// 備註已寫入，重載讓商家直接在訂單備註看到完整結果。
				window.location.reload();
				return;
			}
			window.alert( cfg.i18n.fail + ( ( resp && resp.data && resp.data.message ) || '' ) );
			$btn.prop( 'disabled', false ).text( original );
		} ).fail( function () {
			window.alert( cfg.i18n.fail + cfg.i18n.neterr );
			$btn.prop( 'disabled', false ).text( original );
		} );
	} );
} );
JS;
	}
}
