<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * AI 助手後台頁面。表單 → wp_ai_client_prompt()->using_abilities() → 顯示回覆。
 *
 * 安全:頁面 cap 閘(edit_shop_orders)+ nonce + using_abilities 白名單
 * (模型只能呼叫我們明列的 ability)+ 每個 ability 自己的 permission_callback。
 */
final class AdminPage {

	const SLUG = 'mo-ectools-ai';

	public static function register(): void {
		add_submenu_page(
			'woocommerce',
			Config::NAME,
			Config::NAME,
			Config::CAP,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			wp_die( esc_html__( '權限不足。', 'mo-ectools' ) );
		}

		$prompt = '';
		$answer = '';
		$error  = '';

		if ( isset( $_POST['mo_ai_prompt'] ) && check_admin_referer( 'mo_ai_assistant' ) ) {
			$prompt = sanitize_textarea_field( wp_unslash( $_POST['mo_ai_prompt'] ) );
			if ( '' !== $prompt ) {
				$result = Agent::run( $prompt, Config::abilities(), Config::system_instruction() );
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				} else {
					$answer = (string) $result;
				}
			}
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( Config::NAME ) . ' <span style="font-size:12px;color:#888;">Beta</span></h1>';
		echo '<p style="color:#555;">' . esc_html__( '用一句話查訂單。例如:「待出貨有幾筆?」或「查發票號 JT12202411 是哪張訂單」。會用真實資料查詢後回答。', 'mo-ectools' ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( 'mo_ai_assistant' );
		echo '<textarea name="mo_ai_prompt" rows="3" style="width:100%;max-width:680px;" placeholder="' . esc_attr__( '例如:幫我查發票號 JT12202411 是哪張訂單', 'mo-ectools' ) . '">' . esc_textarea( $prompt ) . '</textarea>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( '送出', 'mo-ectools' ) . '</button></p>';
		echo '</form>';

		if ( '' !== $error ) {
			echo '<div class="notice notice-error" style="max-width:680px;"><p><strong>' . esc_html__( 'AI 回應失敗:', 'mo-ectools' ) . '</strong> ' . esc_html( $error ) . '</p>';
			echo '<p style="color:#666;">' . esc_html__( '若顯示沒有可用的 AI provider,請到「設定 → Connectors」設定 OpenAI / Anthropic / Google 任一把金鑰。', 'mo-ectools' ) . '</p></div>';
		}

		if ( '' !== $answer ) {
			echo '<div id="mo-ai-answer" class="notice notice-success" style="max-width:680px;"><p><strong>' . esc_html__( 'AI 回覆:', 'mo-ectools' ) . '</strong></p>';
			// $answer 是純文字模型輸出 — esc_html 後再 nl2br 補上換行(<br> 為我方刻意加的安全標記)。
			echo '<p>' . nl2br( esc_html( $answer ) ) . '</p></div>';
		}

		echo '</div>';
	}
}
