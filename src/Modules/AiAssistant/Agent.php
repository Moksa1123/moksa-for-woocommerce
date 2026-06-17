<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * WP 7.0 AI Client agentic 迴圈 —— 把 mo-ectools abilities 當工具,跑「生成 → 執行 ability
 * → 餵回 → 再生成」直到模型給出文字答覆。AdminPage 與浮動對話窗 REST 共用同一份。
 *
 * 安全:abilities 白名單(模型只能呼叫傳入的這幾個)+ 每個 ability 自己的 permission_callback
 * (核心 WP_Ability::execute 強制跑)—— 兩層都由核心擋。
 *
 * 註:本 class 直接引用 WP 7.0 核心 AI Client / php-ai-client 的 DTO,僅在
 * function_exists('wp_ai_client_prompt') 成立(模組才 boot)時才會被呼叫到。
 */
final class Agent {

	const MAX_TURNS = 5;

	/**
	 * 跨 provider 偏好;核心會挑第一個「已設定且可用」的(商家在 Settings → Connectors 設誰就用誰)。
	 */
	const MODELS = array( 'gemini-2.5-flash', 'claude-sonnet-4-6', 'gpt-4o-mini' );

	/**
	 * @param string   $user_text 商家的提問。
	 * @param string[] $abilities 暴露給 AI 的 ability 白名單。
	 * @param string   $system    系統提示。
	 * @return string|\WP_Error 模型最終文字答覆,或錯誤。
	 */
	public static function run( string $user_text, array $abilities, string $system ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) || empty( $abilities ) ) {
			return new \WP_Error( 'mo_ai_unavailable', __( 'AI Client 不可用（需 WordPress 7.0）。', 'mo-ectools' ) );
		}

		$resolver = new \WP_AI_Client_Ability_Function_Resolver( ...$abilities );
		$history  = array();
		$current  = $user_text;

		for ( $turn = 0; $turn < self::MAX_TURNS; $turn++ ) {
			$builder = wp_ai_client_prompt( $current )
				->using_system_instruction( $system )
				->using_abilities( ...$abilities )
				->using_model_preference( ...self::MODELS );
			if ( ! empty( $history ) ) {
				$builder = $builder->with_history( ...$history );
			}

			$result = $builder->generate_text_result();
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$assistant = $result->toMessage();

			// 模型沒有要呼叫工具了 → 這就是最終文字答覆。
			if ( ! $resolver->has_ability_calls( $assistant ) ) {
				try {
					return (string) $result->toText();
				} catch ( \Throwable $e ) {
					return new \WP_Error( 'mo_ai_no_text', __( 'AI 沒有回覆文字內容,請換個問法。', 'mo-ectools' ) );
				}
			}

			// 執行模型要呼叫的 ability(permission_callback 由核心強制),把回應餵回下一回合。
			$tool_response = $resolver->execute_abilities( $assistant );
			$current_msg   = is_string( $current )
				? new \WordPress\AiClient\Messages\DTO\UserMessage( array( new \WordPress\AiClient\Messages\DTO\MessagePart( $current ) ) )
				: $current;
			$history[]     = $current_msg;
			$history[]     = $assistant;
			$current       = $tool_response;
		}

		return new \WP_Error( 'mo_ai_max_turns', __( 'AI 多次嘗試後仍未完成,請換個問法。', 'mo-ectools' ) );
	}
}
