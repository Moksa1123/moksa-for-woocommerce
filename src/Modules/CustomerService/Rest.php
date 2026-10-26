<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\CustomerService;

defined( 'ABSPATH' ) || exit;

/**
 * 前台客服 REST —— 顧客自助查單(未登入可用)。
 *
 * 安全設計（permission_callback 為 __return_true 的理由）：
 * 這四個端點提供顧客自助查單，必須允許未登入訪客存取，因此無法套用 is_user_logged_in()。
 * 替代閘門（每個端點都有各自的閘）：
 *   - /cs/verify：訂單號 + 電話末三碼 + 帳單 email 全對才過；IP 與訂單雙維度節流（Verify::attempt）；
 *                 統一失敗訊息，無法枚舉訂單；完全唯讀。
 *   - /cs/order：必須帶 Verify::attempt 核發的時效 token（hash_equals 比對），否則拒絕；唯讀。
 *   - /cs/message：同上 token 閘；訊息長度上限 2000 字元；只寫入當次訂單對話 thread。
 *   - /cs/messages：同上 token 閘；唯讀輪詢。
 * 所有輸入皆透過 register_rest_route args.sanitize_callback 消毒後才進 callback。
 * 端點不暴露任何可枚舉訂單清單的介面，fail-closed（token 無效 → 200 + ok:false，不洩漏原因）。
 */
final class Rest {

	const NS = 'moksa-for-woocommerce/v1';

	public static function register(): void {
		register_rest_route(
			self::NS,
			'/cs/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'verify' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'order'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'phone3' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/cs/order',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'order' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/cs/message',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'message' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'body'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/cs/messages',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'messages' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * 顧客留言(驗證後)→ 開 / 取對話 + 寫訊息 + 通知店家。
	 *
	 * @param \WP_REST_Request $request 請求。
	 * @return \WP_REST_Response
	 */
	public static function message( \WP_REST_Request $request ): \WP_REST_Response {
		$token    = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$order_id = Verify::order_for_token( $token );
		$body     = trim( sanitize_textarea_field( (string) $request->get_param( 'body' ) ) );
		if ( $order_id <= 0 ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'The session has expired. Please verify again.', 'moksa-for-woocommerce' ),
				),
				200
			);
		}
		if ( '' === $body ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Please write a message.', 'moksa-for-woocommerce' ),
				),
				200
			);
		}
		// 每張訂單每小時留言上限：token 有效 30 分鐘，沒有上限就能無限寫入對話並觸發通知信。
		$rate_key = 'moksafowo_cs_msg_rl_' . $order_id;
		$sent     = (int) get_transient( $rate_key );
		if ( $sent >= 30 ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'You have sent a lot of messages. Please wait a while before sending more.', 'moksa-for-woocommerce' ),
				),
				200
			);
		}
		set_transient( $rate_key, $sent + 1, HOUR_IN_SECONDS );

		$body      = mb_substr( $body, 0, 2000 );
		$order     = wc_get_order( $order_id );
		$ref       = $order instanceof \WC_Order ? '#' . $order->get_order_number() : '#' . $order_id;
		$thread_id = Threads::open_or_get( $order_id, $ref );

		$history = Threads::get_messages( $thread_id );
		Threads::add_message( $thread_id, 'customer', $body );

		$ai = null;
		if ( AiReply::enabled() && AiReply::rate_ok( $order_id ) ) {
			$ai = AiReply::generate( $order_id, $history, $body );
		}
		if ( null !== $ai ) {
			Threads::add_message( $thread_id, 'ai', $ai );
		}
		// AI 回覆後也要通知：它被要求在不確定時告訴顧客「已轉交專人」，沒通知就沒人會跟進。
		// 同一訂單 15 分鐘內只寄一封。
		self::notify_staff( $order_id, $ref );

		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'messages' => Threads::get_messages( $thread_id ),
			),
			200
		);
	}

	/**
	 * 顧客輪詢自己訂單的對話訊息。
	 *
	 * @param \WP_REST_Request $request 請求。
	 * @return \WP_REST_Response
	 */
	public static function messages( \WP_REST_Request $request ): \WP_REST_Response {
		$token    = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$order_id = Verify::order_for_token( $token );
		if ( $order_id <= 0 ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}
		$thread_id = Threads::thread_id_for_order( $order_id );
		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'messages' => $thread_id > 0 ? Threads::get_messages( $thread_id ) : array(),
			),
			200
		);
	}

	private static function notify_staff( int $order_id, string $ref ): void {
		$to = (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return;
		}
		// 同一張訂單連續留言只通知一次，15 分鐘內不重寄。
		$sent_key = 'moksafowo_cs_notified_' . $order_id;
		if ( get_transient( $sent_key ) ) {
			return;
		}
		set_transient( $sent_key, 1, 15 * MINUTE_IN_SECONDS );
		$subject = sprintf(
			/* translators: %s: order ref */
			__( '[Support] New message on order %s', 'moksa-for-woocommerce' ),
			$ref
		);
		$link = admin_url( 'admin.php?page=moksafowo-ai&tab=inbox' );
		$line = sprintf(
			/* translators: 1: order ref, 2: inbox url */
			__( 'The customer on order %1$s left a message in storefront support. Reply from the support messages screen: %2$s', 'moksa-for-woocommerce' ),
			$ref,
			$link
		);
		wp_mail( $to, $subject, $line );
	}

	/**
	 * @param \WP_REST_Request $request 請求。
	 * @return \WP_REST_Response
	 */
	public static function verify( \WP_REST_Request $request ): \WP_REST_Response {
		$order_ref = sanitize_text_field( (string) $request->get_param( 'order' ) );
		$phone3    = sanitize_text_field( (string) $request->get_param( 'phone3' ) );
		$email     = sanitize_email( (string) $request->get_param( 'email' ) );

		$result = Verify::attempt( $order_ref, $phone3, $email, self::ip() );
		if ( empty( $result['ok'] ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'That order was not found, or the details did not match. Please check the order number, the last three digits of your billing phone and your billing email, then try again.', 'moksa-for-woocommerce' ),
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'token'   => (string) $result['token'],
				'summary' => CustomerView::summary( (int) $result['order_id'] ),
			),
			200
		);
	}

	/**
	 * @param \WP_REST_Request $request 請求。
	 * @return \WP_REST_Response
	 */
	public static function order( \WP_REST_Request $request ): \WP_REST_Response {
		$token    = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$order_id = Verify::order_for_token( $token );
		if ( $order_id <= 0 ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'The session has expired. Please enter your order number again.', 'moksa-for-woocommerce' ),
				),
				200
			);
		}

		$summary = CustomerView::summary( $order_id );
		if ( null === $summary ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'No order details were found.', 'moksa-for-woocommerce' ),
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'summary' => $summary,
			),
			200
		);
	}

	private static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : '0.0.0.0';
	}
}
