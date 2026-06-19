<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\CustomerService\Admin;

use MoksaWeb\Mowc\Modules\CustomerService\Threads;

defined( 'ABSPATH' ) || exit;

/**
 * 客服訊息 Inbox —— 列出對話、檢視、回覆。
 *
 * 不再自掛子選單;由 AiAssistant\Admin\Hub 的「客服訊息」tab 呼叫 render_inbox()。
 * 回覆 handler(admin_post）由 Hub 常駐註冊。
 */
final class Inbox {

	const PAGE = 'moksafowo-ai';
	const CAP  = 'edit_shop_orders';

	/**
	 * Hub「客服訊息」tab 內容(不含外層 wrap/h1/tabs,那些 Hub 提供）。
	 *
	 * @param string $page hub 頁 slug。
	 */
	public static function render_inbox( string $page ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 唯讀的列表/檢視切換。
		$thread_id = isset( $_GET['thread'] ) ? absint( wp_unslash( $_GET['thread'] ) ) : 0;
		if ( $thread_id > 0 ) {
			self::render_thread( $thread_id, $page );
		} else {
			self::render_list( $page );
		}
	}

	private static function render_list( string $page ): void {
		$threads = Threads::list_threads( 100 );
		echo '<div class="moksafowo-ai-inbox">';
		if ( empty( $threads ) ) {
			echo '<div class="moksafowo-ai-empty">' . esc_html__( '目前沒有客服留言。顧客在前台客服窗留言後會出現在這裡。', 'mo-ectools' ) . '</div></div>';
			return;
		}
		echo '<div class="moksafowo-ai-threads">';
		foreach ( $threads as $t ) {
			$url    = add_query_arg(
				array(
					'page'   => $page,
					'tab'    => 'inbox',
					'thread' => (int) $t['id'],
				),
				admin_url( 'admin.php' )
			);
			$unread = ! empty( $t['unread_staff'] );
			$open   = 'open' === (string) $t['status'];
			$label  = $open ? __( '待回覆', 'mo-ectools' ) : __( '已結束', 'mo-ectools' );
			echo '<div class="moksafowo-ai-trow">';
			echo '<span class="moksafowo-ai-ord">' . esc_html( (string) $t['customer_ref'] ) . '</span>';
			echo '<span class="moksafowo-ai-badge ' . ( $open ? 'open' : '' ) . '">' . esc_html( $label ) . '</span>';
			echo '<span class="moksafowo-ai-time">' . esc_html( (string) $t['updated_at'] )
				. ( $unread ? ' <span class="moksafowo-ai-unread">' . esc_html__( '未讀', 'mo-ectools' ) . '</span>' : '' ) . '</span>';
			echo '<a class="moksafowo-ai-btn-view" href="' . esc_url( $url ) . '">' . esc_html__( '檢視 / 回覆', 'mo-ectools' ) . '</a>';
			echo '</div>';
		}
		echo '</div></div>';
	}

	private static function render_thread( int $thread_id, string $page ): void {
		$thread = Threads::get_thread( $thread_id );
		if ( null === $thread ) {
			echo '<div class="moksafowo-ai-inbox"><div class="moksafowo-ai-empty">' . esc_html__( '找不到對話。', 'mo-ectools' ) . '</div></div>';
			return;
		}
		Threads::mark_staff_read( $thread_id );
		$order_id = (int) $thread['order_id'];
		$back     = add_query_arg(
			array(
				'page' => $page,
				'tab'  => 'inbox',
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="moksafowo-ai-inbox">';
		echo '<a class="moksafowo-ai-back" href="' . esc_url( $back ) . '">&larr; ' . esc_html__( '返回列表', 'mo-ectools' ) . '</a>';
		echo '<div class="moksafowo-ai-thread">';

		echo '<div class="moksafowo-ai-thread-head"><span class="moksafowo-ai-ord">' . esc_html( (string) $thread['customer_ref'] ) . '</span>';
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				echo '<a class="button button-small" href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( '開啟訂單', 'mo-ectools' ) . '</a>';
			}
		}
		echo '</div>';

		echo '<div class="moksafowo-ai-msgs">';
		foreach ( Threads::get_messages( $thread_id ) as $m ) {
			$sender   = (string) $m['sender'];
			$customer = 'customer' === $sender;
			$who      = 'staff' === $sender ? __( '客服', 'mo-ectools' ) : ( 'ai' === $sender ? __( 'AI 客服', 'mo-ectools' ) : __( '顧客', 'mo-ectools' ) );
			$cls      = $customer ? 'customer' : ( 'ai' === $sender ? 'ai' : 'staff' );
			$side     = $customer ? 'left' : 'right';
			echo '<div class="moksafowo-ai-line ' . esc_attr( $side ) . '"><div class="moksafowo-ai-bubble ' . esc_attr( $cls ) . '">';
			echo '<div class="moksafowo-ai-meta">' . esc_html( $who ) . ' · ' . esc_html( (string) $m['created_at'] ) . '</div>';
			echo esc_html( (string) $m['body'] );
			echo '</div></div>';
		}
		echo '</div>';

		echo '<div class="moksafowo-ai-reply"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="moksafowo_cs_reply">';
		echo '<input type="hidden" name="thread" value="' . esc_attr( (string) $thread_id ) . '">';
		wp_nonce_field( 'moksafowo_cs_reply_' . $thread_id );
		echo '<textarea name="body" rows="3" placeholder="' . esc_attr__( '輸入回覆內容…', 'mo-ectools' ) . '" required></textarea>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( '送出回覆', 'mo-ectools' ) . '</button></p>';
		echo '</form></div>';

		echo '</div></div>';
	}

	public static function handle_reply(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'mo-ectools' ) );
		}
		$thread_id = isset( $_POST['thread'] ) ? absint( wp_unslash( $_POST['thread'] ) ) : 0;
		check_admin_referer( 'moksafowo_cs_reply_' . $thread_id );
		$body = isset( $_POST['body'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) ) : '';
		if ( $thread_id > 0 && '' !== $body ) {
			Threads::add_message( $thread_id, 'staff', mb_substr( $body, 0, 5000 ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE,
					'tab'    => 'inbox',
					'thread' => $thread_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
