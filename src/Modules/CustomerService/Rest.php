<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\CustomerService;

defined( 'ABSPATH' ) || exit;

/**
 * 前台客服 REST —— 顧客自助查單(未登入可用)。
 *
 * 安全:這兩個端點對外開放(permission __return_true),但
 * - verify:內部 IP+訂單號節流 + 統一失敗訊息,無法寫入任何東西。
 * - order：必須帶有效 token 才回資料,否則拒。
 * 一律唯讀、fail closed,符合 CLAUDE.md §4 nopriv 紅線。
 */
final class Rest {

	const NS = 'mo-ectools/v1';

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
					'message' => __( '連線已過期,請重新驗證。', 'mo-ectools' ),
				),
				200
			);
		}
		if ( '' === $body ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( '請輸入留言內容。', 'mo-ectools' ),
				),
				200
			);
		}
		$body      = mb_substr( $body, 0, 2000 );
		$order     = wc_get_order( $order_id );
		$ref       = $order instanceof \WC_Order ? '#' . $order->get_order_number() : '#' . $order_id;
		$thread_id = Threads::open_or_get( $order_id, $ref );

		$history = Threads::get_messages( $thread_id );
		Threads::add_message( $thread_id, 'customer', $body );

		$ai = null;
		if ( AiReply::enabled() && AiReply::rate_ok( self::ip() ) ) {
			$ai = AiReply::generate( $order_id, $history, $body );
		}
		if ( null !== $ai ) {
			Threads::add_message( $thread_id, 'ai', $ai );
		} else {
			self::notify_staff( $order_id, $ref );
		}

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
		$subject = sprintf(
			/* translators: %s: order ref */
			__( '[客服留言] 訂單 %s 有新留言', 'mo-ectools' ),
			$ref
		);
		$link = admin_url( 'admin.php?page=moksafowo-ai&tab=inbox' );
		$line = sprintf(
			/* translators: 1: order ref, 2: inbox url */
			__( '訂單 %1$s 的顧客在前台客服留言。請至後台客服訊息回覆:%2$s', 'mo-ectools' ),
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

		$result = Verify::attempt( $order_ref, $phone3, self::ip() );
		if ( empty( $result['ok'] ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( '查無此訂單或驗證失敗,請確認訂單編號與帳單電話末三碼後再試。', 'mo-ectools' ),
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
					'message' => __( '連線已過期,請重新輸入訂單編號驗證。', 'mo-ectools' ),
				),
				200
			);
		}

		$summary = CustomerView::summary( $order_id );
		if ( null === $summary ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( '查無訂單資料。', 'mo-ectools' ),
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
