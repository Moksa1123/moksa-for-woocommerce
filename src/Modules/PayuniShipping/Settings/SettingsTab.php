<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsTab extends \WC_Settings_Page {

	public function __construct() {

		$this->id    = 'moksafowo_payuni';
		$this->label = __( 'PAYUNi', 'mo-ectools' );

		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );

		add_action( 'admin_init', array( $this, 'moksafowo_payuni_shipping_redirect_default_tab' ) );

		add_filter( 'woocommerce_get_sections_' . $this->id, array( $this, 'moksafowo_payuni_shipping_sections' ), 11, 1 );

		parent::__construct();
	}

	public function moksafowo_payuni_shipping_sections( $sections ) {

		unset( $sections[''] );
		if ( is_array( $sections ) && ! array_key_exists( 'shipping', $sections ) ) {
			$sections['shipping'] = __( '物流設定', 'mo-ectools' );
		}
		return $sections;
	}

	public function get_sections() {

		if ( 'yes' !== get_option( 'moksafowo_payuni_enabled', 'no' ) ) {
			$sections = array(
				'shipping' => __( '物流設定', 'mo-ectools' ),
			);
			return apply_filters( 'woocommerce_get_sections_' . $this->id, $sections ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC core convention extension point.
		}
		return array();
	}

	public function get_settings_for_shipping_section() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		$settings = apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
			'moksafowo_payuni_shipping_settings',
			array(
				array(
					'title' => __( '基本設定', 'mo-ectools' ),
					'type'  => 'title',
					'id'    => 'shipping_general_setting',
				),
				array(
					'title'   => __( 'Debug 日誌', 'mo-ectools' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => sprintf(
						/* translators: %s = view logs link */
						__( '排查物流單異常時開啟。位置：WooCommerce → 狀態 → 日誌。 %s', 'mo-ectools' ),
						$this->get_log_link()
					),
					'id'      => 'moksafowo_payuni_shipping_debug_log_enabled',
				),
				array(
					'title'    => __( '超商選店畫面排版', 'mo-ectools' ),
					'type'     => 'select',
					'desc_tip' => __( '依結帳頁佈景主題挑選 — 單欄較窄主題建議用，雙欄較寬主題用。', 'mo-ectools' ),
					'options'  => array(
						'single_column' => __( '單欄（標題在上、內容在下，建議）', 'mo-ectools' ),
						'two_column'    => __( '雙欄（標題在左、內容在右）', 'mo-ectools' ),
					),
					'default'  => 'single_column',
					'id'       => 'moksafowo_payuni_shipping_cvs_selector_layout',
				),
				array(
					'title'   => __( '超商取貨隱藏帳單地址欄位', 'mo-ectools' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( '顧客選超商取貨時，自動隱藏結帳頁的縣市 / 鄉鎮 / 郵遞區號 / 地址欄位（門市資訊已替代）。', 'mo-ectools' ),
					'id'      => 'moksafowo_payuni_shipping_hide_billing_address_fields',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'shipping_general_setting',
				),

				array(
					'title' => __( '寄件人資料', 'mo-ectools' ),
					'type'  => 'title',
					'desc'  => __( '建立物流單時的寄件人資訊。', 'mo-ectools' ),
					'id'    => 'moksafowo_payuni_shipping_store_settings',
				),
				array(
					'title' => __( '姓名', 'mo-ectools' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_shipping_sender_name',
				),
				array(
					'title' => __( '電話', 'mo-ectools' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_shipping_sender_phone',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'shipping_store_setting',
				),

				array(
					'title' => __( '物流貨態自動更新訂單狀態', 'mo-ectools' ),
					'type'  => 'title',
					'desc'  => __( '物流進度更新時，自動把訂單轉到指定狀態。留空則不變更。', 'mo-ectools' ),
					'id'    => 'moksafowo_payuni_shipping_shipping_settings',
				),
				array(
					'title'   => __( '7-11 商家出貨：物流中心驗收', 'mo-ectools' ),
					'type'    => 'select',
					'options' => self::moksafowo_payuni_get_order_status(),
					'id'      => 'moksafowo_payuni_shipping_order_status_at_logistic_center',
				),
				array(
					'title'   => __( '7-11 個人寄件：賣家門市寄件', 'mo-ectools' ),
					'type'    => 'select',
					'options' => self::moksafowo_payuni_get_order_status(),
					'id'      => 'moksafowo_payuni_shipping_order_status_at_sender_cvs',
				),
				array(
					'title'   => __( '配送中', 'mo-ectools' ),
					'type'    => 'select',
					'options' => self::moksafowo_payuni_get_order_status(),
					'id'      => 'moksafowo_payuni_shipping_order_status_delivering',
				),
				array(
					'title'   => __( '到收件門市待取', 'mo-ectools' ),
					'type'    => 'select',
					'options' => self::moksafowo_payuni_get_order_status(),
					'id'      => 'moksafowo_payuni_shipping_order_status_at_receiver_cvs',
				),
				array(
					'title'   => __( '已取貨', 'mo-ectools' ),
					'type'    => 'select',
					'options' => self::moksafowo_payuni_get_order_status(),
					'id'      => 'moksafowo_payuni_shipping_order_status_pickuped',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'shipping_order_setting',
				),

				array(
					'title' => __( '黑貓宅配', 'mo-ectools' ),
					'type'  => 'title',
					'id'    => 'shipping_TCat_setting',
				),
				array(
					'title'   => __( '配達時段', 'mo-ectools' ),
					'type'    => 'select',
					'options' => array(
						'01' => __( '13:00 前', 'mo-ectools' ),
						'02' => __( '14:00 - 18:00', 'mo-ectools' ),
						'04' => __( '不指定', 'mo-ectools' ),
					),
					'default' => '04',
					'id'      => 'moksafowo_payuni_shipping_tcat_delivery_time',
				),
				array(
					'title'   => __( '預計出貨日（列印標籤後 N 天）', 'mo-ectools' ),
					'type'    => 'number',
					'default' => 1,
					'desc'    => __( '預設 1 = 列印標籤後隔天出貨。', 'mo-ectools' ),
					'id'      => 'moksafowo_payuni_shipping_tcat_estimate_shipping_date',
				),
				array(
					'title'   => __( '超商標籤版型', 'mo-ectools' ),
					'type'    => 'select',
					'options' => array(
						'1' => __( 'A4 版型', 'mo-ectools' ),
						'2' => __( '直立式（僅 B2C 適用）', 'mo-ectools' ),
					),
					'id'      => 'moksafowo_payuni_shipping_cvs_label_mode',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'shipping_TCat_setting',
				),

				array(
					'title' => __( '商家憑證', 'mo-ectools' ),
					'type'  => 'title',
					'desc'  => __( '從 PAYUNi 後台「會員專區 → 整合設定」複製過來。跟金流憑證共用同一組。', 'mo-ectools' ),
					'id'    => 'moksafowo_payuni_shipping_api_settings',
				),
				array(
					'title'   => __( '啟用測試模式', 'mo-ectools' ),
					'type'    => 'checkbox',
					'default' => 'yes',
					'desc'    => __( '上線前用，勾選後，所有物流單走測試環境不會真出貨。上線後請取消勾選。', 'mo-ectools' ),
					'id'      => 'moksafowo_payuni_shipping_testmode_enabled',
				),
				array(
					'title' => __( '測試 MerchantID', 'mo-ectools' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_merchant_id_test',
				),
				array(
					'title' => __( '測試 HashKey', 'mo-ectools' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashkey_test',
				),
				array(
					'title' => __( '測試 HashIV', 'mo-ectools' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashiv_test',
				),
				array(
					'title' => __( '正式 MerchantID', 'mo-ectools' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_merchant_id',
				),
				array(
					'title' => __( '正式 HashKey', 'mo-ectools' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashkey',
				),
				array(
					'title' => __( '正式 HashIV', 'mo-ectools' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashiv',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'moksafowo_payuni_shipping_api_settings',
				),
			)
		);

		return $settings;
	}

	private static function moksafowo_payuni_get_order_status() {
		$order_statuses = array(
			'' => __( '不變更', 'mo-ectools' ),
		);

		foreach ( wc_get_order_statuses() as $slug => $name ) {
			if ( $slug === 'wc-cancelled' || $slug === 'wc-refunded' || $slug === 'wc-failed' ) {
				continue;
			}
			$order_statuses[ str_replace( 'wc-', '', $slug ) ] = $name;
		}

		return $order_statuses;
	}

	public function moksafowo_payuni_shipping_redirect_default_tab() {

		global $pagenow;

		if ( 'admin.php' !== $pagenow ) {
			return;
		}

		if ( 'yes' === get_option( 'moksafowo_payuni_enabled', 'no' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect dispatch on WC settings tab.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect dispatch on WC settings tab.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( 'wc-settings' === $page && 'payuni' === $tab ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect dispatch on WC settings tab.
			if ( empty( $_GET['section'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=payuni&section=shipping' ) );
				exit;
			}
		}
	}

	public function output() {

		global $current_section;

		if ( 'shipping' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::output_fields( $settings );
	}

	public function save() {

		global $current_section;

		if ( 'shipping' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::save_fields( $settings );
	}

	protected function get_log_link() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . __( '查看日誌', 'mo-ectools' ) . '</a>';
	}
}
