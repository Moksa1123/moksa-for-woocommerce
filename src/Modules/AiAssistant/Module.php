<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\AiAssistant;

use Moksafowo\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 站內 AI 助手 — 用 WP 7.0 內建 AI Client(wp_ai_client_prompt + using_abilities)
 * 把 mo-ectools 的 abilities 當工具,讓商家用自然語言查訂單 / 物流 / 發票。
 *
 * LLM 呼叫、金鑰(Connectors API)、tool-loop、白名單、permission 全是核心包辦,
 * 我們只提供「面板 UI + 系統提示 + 要暴露哪些 ability」。需 WP 7.0。
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'ai_assistant';
	}

	public function label(): string {
		return __( 'Moksa AI（Beta）— 用一句話查訂單 / 數量 / 狀態', 'mo-ectools' );
	}

	public function category(): string {
		return 'tools';
	}

	public function name(): string {
		return Config::NAME;
	}

	public function tagline(): string {
		return __( '需 WordPress 7.0 並設定 AI 金鑰', 'mo-ectools' );
	}

	public function boot(): void {
		if ( 'yes' !== get_option( 'moksafowo_ai_enabled', 'no' ) ) {
			return;
		}
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}
		add_action( 'rest_api_init', [ Rest::class, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_chat' ] );
	}

	/**
	 * 浮動對話窗是全 admin 功能(像客服浮窗),無法用單一螢幕閘;
	 * 但用 cap 閘(最小權限)—— 沒有訂單權限的後台使用者不載入。
	 */
	public static function enqueue_chat(): void {
		if ( ! current_user_can( Config::CAP ) ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		$rel  = 'src/Modules/AiAssistant/assets/js/floating-chat.js';
		$path = MOKSAFOWO_PLUGIN_DIR . $rel;
		$ver  = file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOWO_VERSION;
		wp_enqueue_script(
			'moksafowo-ai-chat',
			MOKSAFOWO_PLUGIN_URL . $rel,
			[ 'wp-api-fetch' ],
			$ver,
			true
		);
		$greeting = (string) get_option( 'moksafowo_ai_greeting', __( '嗨,我是 Moksa AI。可以問我:待出貨幾筆?或:查發票號 / 物流單號。', 'mo-ectools' ) );
		$ex_raw   = (string) get_option( 'moksafowo_ai_examples', __( '待出貨有幾筆?,各狀態訂單數量', 'mo-ectools' ) );
		$examples = array_values( array_filter( array_map( 'trim', explode( ',', $ex_raw ) ) ) );

		wp_localize_script(
			'moksafowo-ai-chat',
			'moksafowoAi',
			[
				'name'        => Config::NAME,
				'userId'      => get_current_user_id(),
				'greeting'    => $greeting,
				'placeholder' => __( '例如:待出貨有幾筆?', 'mo-ectools' ),
				'examples'    => $examples,
				'sendLabel'   => __( '送出', 'mo-ectools' ),
				'thinking'    => __( '查詢中', 'mo-ectools' ),
				'clearLabel'  => __( '清除', 'mo-ectools' ),
				'errorPrefix' => __( '發生錯誤', 'mo-ectools' ),
				'emptyReply'  => __( '（無回覆）', 'mo-ectools' ),
			]
		);
	}
}
