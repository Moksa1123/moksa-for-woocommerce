<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\AiAssistant;

use Moksafowo\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 站內 AI 助手 — 用 WP 7.0 內建 AI Client(wp_ai_client_prompt + using_abilities)
 * 把 moksa-for-woocommerce 的 abilities 當工具,讓商家用自然語言查訂單 / 物流 / 發票。
 *
 * LLM 呼叫、金鑰(Connectors API)、tool-loop、白名單、permission 全是核心包辦,
 * 我們只提供「面板 UI + 系統提示 + 要暴露哪些 ability」。需 WP 7.0。
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'ai_assistant';
	}

	public function label(): string {
		return __( 'Moksa AI (beta) — one sentence gets you an order, a count or a status', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'tools';
	}

	public function name(): string {
		return Config::NAME;
	}

	public function tagline(): string {
		return __( 'Requires WordPress 7.0 and an AI key', 'moksa-for-woocommerce' );
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
		$greeting = (string) get_option( 'moksafowo_ai_greeting', __( 'Hi, I\'m Moksa AI. Ask me things like how many orders are awaiting shipment, or look up an invoice or tracking number.', 'moksa-for-woocommerce' ) );
		$ex_raw   = (string) get_option( 'moksafowo_ai_examples', __( 'How many orders are awaiting shipment?,Order counts by status', 'moksa-for-woocommerce' ) );
		$examples = array_values( array_filter( array_map( 'trim', explode( ',', $ex_raw ) ) ) );

		wp_localize_script(
			'moksafowo-ai-chat',
			'moksafowoAi',
			[
				'name'        => Config::NAME,
				'userId'      => get_current_user_id(),
				'greeting'    => $greeting,
				'placeholder' => __( 'For example: how many orders are awaiting shipment?', 'moksa-for-woocommerce' ),
				'examples'    => $examples,
				'sendLabel'   => __( 'Send', 'moksa-for-woocommerce' ),
				'thinking'    => __( 'Looking that up', 'moksa-for-woocommerce' ),
				'clearLabel'  => __( 'Clear', 'moksa-for-woocommerce' ),
				'errorPrefix' => __( 'Something went wrong', 'moksa-for-woocommerce' ),
				'emptyReply'  => __( '(no reply)', 'moksa-for-woocommerce' ),
			]
		);
	}
}
