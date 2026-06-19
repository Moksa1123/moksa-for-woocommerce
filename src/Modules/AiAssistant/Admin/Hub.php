<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\AiAssistant\Admin;

use MoksaWeb\Mowc\Modules\AiAssistant\Config;
use MoksaWeb\Mowc\Modules\CustomerService\Admin\Inbox;
use MoksaWeb\Mowc\Modules\CustomerService\Schema;
use MoksaWeb\Mowc\Modules\CustomerService\Threads;

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
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '設定已儲存。', 'mo-ectools' ) . '</p></div>';
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
			esc_html__( '設定', 'mo-ectools' )
		);
		printf(
			'<a href="%s" class="nav-tab %s">%s%s</a>',
			esc_url( $base . '&tab=inbox' ),
			'inbox' === $tab ? 'nav-tab-active' : '',
			esc_html__( '客服訊息', 'mo-ectools' ),
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
			? '<span class="moksafowo-ai-ok">' . esc_html__( '✅ 已偵測到 WordPress 7.0 AI Client', 'mo-ectools' ) . '</span>'
			: '<span class="moksafowo-ai-warn">' . esc_html__( '⚠️ 未偵測到 WordPress 7.0 AI Client', 'mo-ectools' ) . '</span>';

		echo '<div class="moksafowo-ai-card">';
		echo '<p class="moksafowo-ai-card-t">' . esc_html__( '關於 Moksa AI', 'mo-ectools' ) . ' — ' . wp_kses_post( $badge ) . '</p>';
		echo '<p class="moksafowo-ai-card-d">' . wp_kses_post(
			__( '後台 AI 助手與前台 AI 自動回覆需要 <strong>WordPress 7.0</strong> 內建的 AI Client:請先升級到 7.0,並到「設定 → Connectors」設定 OpenAI / Anthropic / Google 任一把金鑰(用量計費走你自己的金鑰)。前台客服的「自助查單 / 留言」不需金鑰即可使用。', 'mo-ectools' )
		) . '</p>';
		echo '</div>';

		echo '<div class="moksafowo-ai-settings"><form method="post" action="">';
		\WC_Admin_Settings::output_fields( self::fields() );
		wp_nonce_field( 'moksafowo_ai_hub_save' );
		echo '<p class="submit"><button type="submit" class="button button-primary" name="moksafowo_ai_hub_save" value="1">' . esc_html__( '儲存設定', 'mo-ectools' ) . '</button></p>';
		echo '</form></div>';

		self::render_mcp_help();
	}

	private static function render_mcp_help(): void {
		if ( ! \MoksaWeb\Mowc\Mcp\Server::enabled() ) {
			return;
		}
		echo '<div class="moksafowo-ai-card">';
		echo '<p class="moksafowo-ai-card-t">' . esc_html__( '如何用 MCP 連線', 'mo-ectools' ) . '</p>';
		echo '<p class="moksafowo-ai-card-d">' . esc_html__( '外部 AI 工具(mcp-remote / Claude 等標準 MCP 客戶端)可直接連到以下端點,不需橋接器(本端點為 stateless,免 session）:', 'mo-ectools' ) . '</p>';
		echo '<p><code>' . esc_html( \MoksaWeb\Mowc\Mcp\Server::endpoint_url() ) . '</code></p>';
		echo '<ol class="moksafowo-ai-card-d" style="margin:6px 0 0 18px;line-height:1.9;">';
		echo '<li>' . wp_kses_post( __( '用一個有「編輯訂單」權限的帳號(建議<strong>專用受限帳號,不要用管理員</strong>),到「使用者 → 個人資料 → 應用程式密碼」建立一組密碼。', 'mo-ectools' ) ) . '</li>';
		echo '<li>' . wp_kses_post( __( '把「帳號:應用程式密碼(去掉空格)」做 Base64,當成 <code>Authorization: Basic &lt;base64&gt;</code> 標頭。', 'mo-ectools' ) ) . '</li>';
		echo '<li>' . esc_html__( '在 MCP 客戶端填入上面端點 + 該認證標頭,即可列出工具。', 'mo-ectools' ) . '</li>';
		echo '</ol>';
		echo '<p class="moksafowo-ai-card-d" style="margin-top:8px;">' . wp_kses_post( __( '預設只開放<strong>唯讀</strong>工具(查訂單 / 報表 / 設定彙整);要讓外部 AI 能改訂單 / 開發票,需另外開啟上方「允許外部 AI 執行變更動作」,且變更仍需在後台確認。', 'mo-ectools' ) ) . '</p>';
		echo '</div>';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function fields(): array {
		return array(
			array(
				'title' => __( '啟用 Moksa AI', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '總開關。關閉時前台與後台 AI 都不啟用;開啟後再用下方分別控制前台 / 後台。', 'mo-ectools' ),
				'id'    => 'moksafowo_ai_hub_master',
			),
			array(
				'title'   => __( '啟用 AI 功能', 'mo-ectools' ),
				'id'      => 'moksafowo_ai_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '智慧客服總開關(前台顧客自助 + 後台 AI 助手)。', 'mo-ectools' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'moksafowo_ai_hub_master',
			),

			array(
				'title' => __( '後台 AI 助手', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '後台右下角的浮動對話窗,用一句話查訂單 / 數量 / 狀態(需 WordPress 7.0)。', 'mo-ectools' ),
				'id'    => 'moksafowo_ai_hub_back',
			),
			array(
				'title'   => __( '啟用後台 AI 助手', 'mo-ectools' ),
				'id'      => 'moksafowo_ai_assistant_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '在後台顯示 Moksa AI 浮動對話窗。', 'mo-ectools' ),
			),
			array(
				'title'   => __( '問候語', 'mo-ectools' ),
				'id'      => 'moksafowo_ai_greeting',
				'type'    => 'textarea',
				'css'     => 'min-height:60px;width:100%;max-width:520px;',
				'default' => __( '嗨,我是 Moksa AI。可以問我:待出貨幾筆?或:查發票號 / 物流單號。', 'mo-ectools' ),
				'desc'    => __( '開啟對話窗時的第一句招呼。', 'mo-ectools' ),
			),
			array(
				'title'    => __( '範例問題', 'mo-ectools' ),
				'id'       => 'moksafowo_ai_examples',
				'type'     => 'text',
				'css'      => 'width:100%;max-width:520px;',
				'default'  => __( '待出貨有幾筆?,各狀態訂單數量', 'mo-ectools' ),
				'desc'     => __( '對話窗下方的快捷提問,以逗號分隔。', 'mo-ectools' ),
				'desc_tip' => true,
			),
			array(
				'title'   => __( '啟用對外 MCP 伺服器', 'mo-ectools' ),
				'id'      => 'moksafowo_ai_mcp_server_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => sprintf(
					/* translators: %s: MCP 端點網址 */
					__( '開啟後,外部 AI 工具可透過 MCP 連到本站查訂單 / 報表(需用 WordPress 應用程式密碼登入,權限同「編輯訂單」)。端點:%s', 'mo-ectools' ),
					'<code>' . esc_url( \MoksaWeb\Mowc\Mcp\Server::endpoint_url() ) . '</code>'
				),
			),
			array(
				'title'   => __( '允許外部 AI 執行變更動作（MCP）', 'mo-ectools' ),
				'id'      => 'moksafowo_ai_mcp_expose_destructive',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '預設只開放外部 AI 查詢(唯讀)。開啟後才允許改訂單狀態 / 開立發票等動作,且一律需要你在後台確認才會執行。', 'mo-ectools' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'moksafowo_ai_hub_back',
			),

			array(
				'title' => __( '前台客服(顧客自助)', 'mo-ectools' ),
				'type'  => 'title',
				'desc'  => __( '前台浮動窗,讓顧客用「訂單編號 + 帳單電話末三碼」自助查單 / 留言。規則式,不需 AI 金鑰。', 'mo-ectools' ),
				'id'    => 'moksafowo_ai_hub_front',
			),
			array(
				'title'   => __( '啟用前台客服窗', 'mo-ectools' ),
				'id'      => 'moksafowo_customer_service_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '在前台顯示浮動「訂單查詢」窗。', 'mo-ectools' ),
			),
			array(
				'title'   => __( '視窗標題', 'mo-ectools' ),
				'id'      => 'moksafowo_customer_service_title',
				'type'    => 'text',
				'default' => __( '訂單查詢', 'mo-ectools' ),
			),
			array(
				'title'             => __( '驗證嘗試上限', 'mo-ectools' ),
				'id'                => 'moksafowo_customer_service_max_attempts',
				'type'              => 'number',
				'default'           => '5',
				'desc'              => __( '同一訂單+IP 連續錯誤達此次數即暫時鎖定(防末三碼暴力猜測)。', 'mo-ectools' ),
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'title'             => __( '鎖定時間（分鐘）', 'mo-ectools' ),
				'id'                => 'moksafowo_customer_service_lockout_minutes',
				'type'              => 'number',
				'default'           => '60',
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'title'   => __( '顯示頁面', 'mo-ectools' ),
				'id'      => 'moksafowo_customer_service_display_mode',
				'type'    => 'select',
				'default' => 'all',
				'options' => array(
					'all'     => __( '全部頁面', 'mo-ectools' ),
					'include' => __( '僅指定頁面', 'mo-ectools' ),
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- select 選項鍵,非 get_posts 查詢參數。
					'exclude' => __( '排除指定頁面', 'mo-ectools' ),
				),
			),
			array(
				'title'       => __( '頁面 ID 清單', 'mo-ectools' ),
				'id'          => 'moksafowo_customer_service_pages',
				'type'        => 'text',
				'placeholder' => '12, 34, 56',
				'desc'        => __( '以逗號分隔的頁面 / 文章 ID,配合上方「僅指定 / 排除指定」使用。', 'mo-ectools' ),
			),
			array(
				'title'   => __( '啟用前台 AI 自動回覆', 'mo-ectools' ),
				'id'      => 'moksafowo_cs_ai_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( '顧客留言時由 AI 依該訂單資訊與下方常見問答即時回覆,答不出來會自動轉給真人。需 WordPress 7.0;未啟用時為一般留言。', 'mo-ectools' ),
			),
			array(
				'title'   => __( '常見問答（FAQ）', 'mo-ectools' ),
				'id'      => 'moksafowo_cs_faq',
				'type'    => 'textarea',
				'css'     => 'min-height:120px;width:100%;max-width:560px;',
				'default' => '',
				'desc'    => __( 'AI 回答顧客的依據,例如出貨時間、退換貨與運費規則。一行一則。', 'mo-ectools' ),
			),
			array(
				'title'             => __( 'AI 回覆每小時上限', 'mo-ectools' ),
				'id'                => 'moksafowo_cs_ai_rate',
				'type'              => 'number',
				'default'           => '20',
				'desc'              => __( '控制用量與費用;超過後改為一般留言。', 'mo-ectools' ),
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'moksafowo_ai_hub_front',
			),
		);
	}
}
