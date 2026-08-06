<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\AiAssistant;

use Moksafowo\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 站內 AI 助手 —— 對話窗本體在共用層（lib/moksa-ai），全站只跑一份；
 * 這個模組只負責把 mo-ectools 自己的能力推進共用層的 Registry。
 *
 * 為什麼窗要共用：商家可能同時裝了訂房、電商、優惠券等多個 Moksa 外掛，
 * 每個都畫一個浮動窗就會疊成一排。共用層用版本選舉挑一份執行，各外掛
 * 透過 moksa_ai_* filter 把能力送進去，於是一個窗操作全部。
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
		// 模組卡片（moksafowo_ai_assistant_enabled）是唯一的開關 —— ModuleRegistry 已經
		// 檢查過才會走到這裡，不再疊第二層總開關。
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		// 這些 ability 由 OrderLookup 模組註冊，但它們是 AI 助手唯一的工具來源。
		// 只開 AI、沒開 OrderLookup 時，助手會拿到 0 個工具 —— 選單有、對話窗不出現，
		// 商家只會覺得「我開了但沒反應」。所以這裡確保註冊一定發生（內部有 once 閘）。
		\Moksafowo\Modules\OrderLookup\Module::register_abilities();

		add_filter( 'moksa_ai_abilities', [ self::class, 'register_abilities' ] );
		add_filter( 'moksa_ai_destructive_handlers', [ self::class, 'register_destructive_handlers' ] );
		add_filter( 'moksa_ai_system_instruction', [ self::class, 'register_system_instruction' ] );
		add_filter( 'moksa_ai_capability', [ self::class, 'register_capability' ] );
		add_filter( 'moksa_ai_strings', [ self::class, 'register_strings' ] );
	}

	/**
	 * @param string[] $abilities 其他外掛已註冊的能力。
	 * @return string[]
	 */
	public static function register_abilities( array $abilities ): array {
		return array_merge( $abilities, Config::abilities() );
	}

	/**
	 * @param array<string, array{prepare:callable, apply:callable}> $handlers 其他外掛的處理表。
	 * @return array<string, array{prepare:callable, apply:callable}>
	 */
	public static function register_destructive_handlers( array $handlers ): array {
		return array_merge( $handlers, Config::destructive_handlers() );
	}

	/**
	 * 把電商領域的規則接在共用骨架後面，而不是整段覆寫 —— 別的外掛也要能接自己的。
	 */
	public static function register_system_instruction( string $base ): string {
		return trim( $base ) . "\n\n" . Config::system_instruction();
	}

	public static function register_capability( string $cap ): string {
		return Config::CAP;
	}

	/**
	 * 共用層是 bundle 進來的函式庫，沒有自己的 textdomain，所以介面字串由我們翻好餵進去。
	 *
	 * @param array<string, mixed> $strings 預設字串。
	 * @return array<string, mixed>
	 */
	public static function register_strings( array $strings ): array {
		$ex_raw   = (string) get_option( 'moksafowo_ai_examples', __( 'How many orders are awaiting shipment?,Order counts by status', 'moksa-for-woocommerce' ) );
		$examples = array_values( array_filter( array_map( 'trim', explode( ',', $ex_raw ) ) ) );

		return array_merge(
			$strings,
			[
				'name'         => Config::NAME,
				'greeting'     => (string) get_option( 'moksafowo_ai_greeting', __( 'Hi, I\'m Moksa AI. Ask me things like how many orders are awaiting shipment, or look up an invoice or tracking number.', 'moksa-for-woocommerce' ) ),
				'placeholder'  => __( 'For example: how many orders are awaiting shipment?', 'moksa-for-woocommerce' ),
				'send'         => __( 'Send', 'moksa-for-woocommerce' ),
				'thinking'     => __( 'Looking that up', 'moksa-for-woocommerce' ),
				'clear'        => __( 'Clear', 'moksa-for-woocommerce' ),
				'errorPrefix'  => __( 'Something went wrong', 'moksa-for-woocommerce' ),
				'emptyReply'   => __( '(no reply)', 'moksa-for-woocommerce' ),
				'unavailable'  => __( 'The AI Client is unavailable. It requires WordPress 7.0.', 'moksa-for-woocommerce' ),
				'busy'         => __( 'The AI cannot answer right now. Please try again shortly.', 'moksa-for-woocommerce' ),
				'exhausted'    => __( 'The AI could not finish after several attempts. Try asking a different way.', 'moksa-for-woocommerce' ),
				'unsupported'  => __( 'Unsupported action.', 'moksa-for-woocommerce' ),
				'notPrepared'  => __( 'This action could not be prepared.', 'moksa-for-woocommerce' ),
				'confirmStale' => __( 'That confirmation has expired or no longer exists. Please start again.', 'moksa-for-woocommerce' ),
				// 確認關卡的兩顆按鈕。共用層自己不做 gettext（bundle 的函式庫用自己的 textdomain
				// 會被 Plugin Check 擋），所以少餵這兩個的話中文站會看到英文的 Confirm / Cancel。
				'confirmRun'   => __( 'Confirm', 'moksa-for-woocommerce' ),
				'confirmSkip'  => __( 'Cancel', 'moksa-for-woocommerce' ),
				'noPermission' => __( 'You do not have permission to do this.', 'moksa-for-woocommerce' ),
				'examples'     => $examples,
			]
		);
	}
}
