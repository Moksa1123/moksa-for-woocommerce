<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\AiAssistant\Admin;

use Moksafowo\Modules\AiAssistant\Config;
use Moksafowo\Modules\CustomerService\Admin\Inbox;
use Moksafowo\Modules\CustomerService\Schema;
use Moksafowo\Modules\CustomerService\Threads;

defined( 'ABSPATH' ) || exit;

/**
 * 「Moksa AI」後台 hub —— 常駐子選單(不受模組開關 gate,否則關了就進不去)。
 *
 * 兩個 tab:
 * - 設定：WP 7.0 依賴宣告 + 前/後台 AI 開關 + 後台 AI 助手設定 + 前台客服設定。
 * - 客服訊息：顧客留言 Inbox(回覆)。
 *
 * 設計沿用 WP admin nav-tab + WC_Admin_Settings 欄位,與「Moksa 電商工具」一致。
 */
final class Hub {

	const PAGE = 'moksafowo-ai';
	const CAP  = Config::CAP;

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_moksafowo_cs_reply', array( Inbox::class, 'handle_reply' ) );
	}

	public static function enqueue_assets(): void {
		$screen = get_current_screen();
		if ( null === $screen || 'woocommerce_page_' . self::PAGE !== $screen->id ) {
			return;
		}
		$rel = 'assets/admin/css/moksafowo-ai-hub.css';
		wp_enqueue_style(
			'moksafowo-ai-hub',
			MOKSAFOWO_PLUGIN_URL . $rel,
			array(),
			file_exists( MOKSAFOWO_PLUGIN_DIR . $rel ) ? (string) filemtime( MOKSAFOWO_PLUGIN_DIR . $rel ) : MOKSAFOWO_VERSION
		);
	}

	public static function menu(): void {
		$unread = Threads::count_unread();
		$label  = Config::NAME;
		if ( $unread > 0 ) {
			$label .= ' <span class="awaiting-mod">' . esc_html( (string) $unread ) . '</span>';
		}
		add_submenu_page(
			'woocommerce',
			Config::NAME,
			$label,
			self::CAP,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		Schema::maybe_install();
		if ( isset( $_POST['moksafowo_ai_hub_save'] ) ) {
			check_admin_referer( 'moksafowo_ai_hub_save' );
			\WC_Admin_Settings::save_fields( self::fields() );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'moksa-for-woocommerce' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 唯讀的 tab 切換,無狀態變更。
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$unread = Threads::count_unread();
		$base   = admin_url( 'admin.php?page=' . self::PAGE );

		echo '<div class="wrap woocommerce moksafowo-ai-wrap"><h1>' . esc_html( Config::NAME ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( $base . '&tab=settings' ),
			'inbox' === $tab ? '' : 'nav-tab-active',
			esc_html__( 'Settings', 'moksa-for-woocommerce' )
		);
		printf(
			'<a href="%s" class="nav-tab %s">%s%s</a>',
			esc_url( $base . '&tab=inbox' ),
			'inbox' === $tab ? 'nav-tab-active' : '',
			esc_html__( 'Support messages', 'moksa-for-woocommerce' ),
			$unread > 0 ? ' <span class="awaiting-mod">' . esc_html( (string) $unread ) . '</span>' : ''
		);
		echo '</h2>';

		if ( 'inbox' === $tab ) {
			Inbox::render_inbox( self::PAGE );
		} else {
			self::render_settings();
		}
		echo '</div>';
	}

	private static function render_settings(): void {
		$has_client = function_exists( 'wp_ai_client_prompt' );
		$badge      = $has_client
			? '<span class="moksafowo-ai-ok">' . esc_html__( '✅ The WordPress 7.0 AI Client was found', 'moksa-for-woocommerce' ) . '</span>'
			: '<span class="moksafowo-ai-warn">' . esc_html__( '⚠️ The WordPress 7.0 AI Client was not found', 'moksa-for-woocommerce' ) . '</span>';

		echo '<div class="moksafowo-ai-card">';
		echo '<p class="moksafowo-ai-card-t">' . esc_html__( 'About Moksa AI', 'moksa-for-woocommerce' ) . ' — ' . wp_kses_post( $badge ) . '</p>';
		echo '<p class="moksafowo-ai-card-d">' . wp_kses_post(
			__( 'The admin AI assistant and the automatic replies on the storefront both need the AI Client built into <strong>WordPress 7.0</strong>. Upgrade to 7.0, then add an OpenAI, Anthropic or Google key under Settings → Connectors. Usage is billed to your own key. Order lookup and messaging on the storefront work without a key.', 'moksa-for-woocommerce' )
		) . '</p>';
		echo '</div>';

		echo '<div class="moksafowo-ai-settings"><form method="post" action="">';
		\WC_Admin_Settings::output_fields( self::fields() );
		wp_nonce_field( 'moksafowo_ai_hub_save' );
		echo '<p class="submit"><button type="submit" class="button button-primary" name="moksafowo_ai_hub_save" value="1">' . esc_html__( 'Save settings', 'moksa-for-woocommerce' ) . '</button></p>';
		echo '</form></div>';

		self::render_mcp_help();
	}

	private static function render_mcp_help(): void {
		if ( ! \Moksafowo\Mcp\Server::enabled() ) {
			return;
		}
		echo '<div class="moksafowo-ai-card">';
		echo '<p class="moksafowo-ai-card-t">' . esc_html__( 'Connecting over MCP', 'moksa-for-woocommerce' ) . '</p>';
		echo '<p class="moksafowo-ai-card-d">' . esc_html__( 'External AI tools such as mcp-remote, Claude and other standard MCP clients can connect straight to the endpoint below. No bridge is needed, and the endpoint is stateless so there is no session to manage:', 'moksa-for-woocommerce' ) . '</p>';
		echo '<p><code>' . esc_html( \Moksafowo\Mcp\Server::endpoint_url() ) . '</code></p>';
		echo '<ol class="moksafowo-ai-card-d" style="margin:6px 0 0 18px;line-height:1.9;">';
		echo '<li>' . wp_kses_post( __( 'Use an account that can edit orders — ideally a <strong>dedicated, limited account rather than an administrator</strong> — and create a password under Users → Profile → Application Passwords.', 'moksa-for-woocommerce' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( 'Base64-encode “username:application password” with the spaces removed, and send it as an <code>Authorization: Basic &lt;base64&gt;</code> header.', 'moksa-for-woocommerce' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Enter that endpoint and header in the MCP client and the tools will be listed.', 'moksa-for-woocommerce' ) . '</li>';
		echo '</ol>';
		echo '<p class="moksafowo-ai-card-d" style="margin-top:8px;">' . wp_kses_post( __( 'Only <strong>read-only</strong> tools are exposed by default: order lookups, reports and a settings summary. To let an external AI change orders or issue invoices, turn on “Allow external AI to make changes” above — and every change still has to be confirmed in the admin.', 'moksa-for-woocommerce' ) ) . '</p>';
		echo '</div>';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function fields(): array {
		return array(
			array(
				'title' => __( 'Enable Moksa AI', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'The master switch. With it off, neither the storefront nor the admin AI runs. With it on, use the settings below to control each side separately.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ai_hub_master',
			),
			array(
				'title'   => __( 'Enable AI features', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ai_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'The master switch for customer support, covering both storefront self-service and the admin AI assistant.', 'moksa-for-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'moksafowo_ai_hub_master',
			),

			array(
				'title' => __( 'Admin AI assistant', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'A floating chat window in the bottom-right of the admin, where one sentence gets you an order, a count or a status. Requires WordPress 7.0.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ai_hub_back',
			),
			array(
				'title'   => __( 'Enable the admin AI assistant', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ai_assistant_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Show the Moksa AI chat window in the admin.', 'moksa-for-woocommerce' ),
			),
			array(
				'title'   => __( 'Greeting', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ai_greeting',
				'type'    => 'textarea',
				'css'     => 'min-height:60px;width:100%;max-width:520px;',
				'default' => __( 'Hi, I\'m Moksa AI. Ask me things like how many orders are awaiting shipment, or look up an invoice or tracking number.', 'moksa-for-woocommerce' ),
				'desc'    => __( 'The first thing shown when the chat window opens.', 'moksa-for-woocommerce' ),
			),
			array(
				'title'    => __( 'Example questions', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_ai_examples',
				'type'     => 'text',
				'css'      => 'width:100%;max-width:520px;',
				'default'  => __( 'How many orders are awaiting shipment?,Order counts by status', 'moksa-for-woocommerce' ),
				'desc'     => __( 'Shortcut questions shown under the chat window, separated by commas.', 'moksa-for-woocommerce' ),
				'desc_tip' => true,
			),
			array(
				'title'   => __( 'Enable the MCP server', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ai_mcp_server_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => sprintf(
					/* translators: %s: MCP 端點網址 */
					__( 'Lets external AI tools connect over MCP to look up orders and reports. They sign in with a WordPress application password and get the same permissions as editing orders. Endpoint: %s', 'moksa-for-woocommerce' ),
					'<code>' . esc_url( \Moksafowo\Mcp\Server::endpoint_url() ) . '</code>'
				),
			),
			array(
				'title'   => __( 'Allow external AI to make changes (MCP)', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ai_mcp_expose_destructive',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'External AI can only read by default. Turn this on to allow actions such as changing an order status or issuing an invoice — and each one still has to be confirmed in the admin before it runs.', 'moksa-for-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'moksafowo_ai_hub_back',
			),

			array(
				'title' => __( 'Storefront support (customer self-service)', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'A floating window on the storefront where customers look up their order with an order number and the last three digits of their billing phone, and leave a message. It follows fixed rules and needs no AI key.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ai_hub_front',
			),
			array(
				'title'   => __( 'Enable the storefront support window', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_customer_service_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Show the floating order lookup window on the storefront.', 'moksa-for-woocommerce' ),
			),
			array(
				'title'   => __( 'Window title', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_customer_service_title',
				'type'    => 'text',
				'default' => __( 'Order lookup', 'moksa-for-woocommerce' ),
			),
			array(
				'title'             => __( 'Maximum verification attempts', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_customer_service_max_attempts',
				'type'              => 'number',
				'default'           => '5',
				'desc'              => __( 'After this many failures from the same order and IP address, lookups are locked for a while, so the last three digits cannot be guessed by brute force.', 'moksa-for-woocommerce' ),
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'title'             => __( 'Lockout time (minutes)', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_customer_service_lockout_minutes',
				'type'              => 'number',
				'default'           => '60',
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'title'   => __( 'Where to show it', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_customer_service_display_mode',
				'type'    => 'select',
				'default' => 'all',
				'options' => array(
					'all'     => __( 'Every page', 'moksa-for-woocommerce' ),
					'include' => __( 'Only the listed pages', 'moksa-for-woocommerce' ),
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- select 選項鍵,非 get_posts 查詢參數。
					'exclude' => __( 'Every page except the listed ones', 'moksa-for-woocommerce' ),
				),
			),
			array(
				'title'       => __( 'Page IDs', 'moksa-for-woocommerce' ),
				'id'          => 'moksafowo_customer_service_pages',
				'type'        => 'text',
				'placeholder' => '12, 34, 56',
				'desc'        => __( 'Page or post IDs separated by commas, used with the setting above.', 'moksa-for-woocommerce' ),
			),
			array(
				'title'   => __( 'Enable automatic AI replies on the storefront', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_cs_ai_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'When a customer leaves a message, the AI answers straight away using that order and the FAQ below, and hands over to a person whenever it cannot. Requires WordPress 7.0. With this off, messages are simply passed on.', 'moksa-for-woocommerce' ),
			),
			array(
				'title'   => __( 'Frequently asked questions', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_cs_faq',
				'type'    => 'textarea',
				'css'     => 'min-height:120px;width:100%;max-width:560px;',
				'default' => '',
				'desc'    => __( 'What the AI draws on when answering customers — dispatch times, returns and shipping rules, for example. One per line.', 'moksa-for-woocommerce' ),
			),
			array(
				'title'             => __( 'AI replies per hour', 'moksa-for-woocommerce' ),
				'id'                => 'moksafowo_cs_ai_rate',
				'type'              => 'number',
				'default'           => '20',
				'desc'              => __( 'Keeps usage and cost in check. Once the limit is reached, messages are simply passed on.', 'moksa-for-woocommerce' ),
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'moksafowo_ai_hub_front',
			),
		);
	}
}
