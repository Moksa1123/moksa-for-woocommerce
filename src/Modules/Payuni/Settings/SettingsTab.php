<?php


namespace Moksafowo\Modules\Payuni\Settings;

use Moksafowo\Modules\Payuni\Gateways\CreditInstallment12;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment18;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment24;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment3;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment30;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment6;
use Moksafowo\Modules\Payuni\Gateways\CreditInstallment9;

defined( 'ABSPATH' ) || exit;


class SettingsTab extends \WC_Settings_Page {


	public function __construct() {

		$this->id    = 'moksafowo_payuni';
		$this->label = __( 'PAYUNi', 'moksa-for-woocommerce' );

		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );

		add_action( 'admin_init', array( $this, 'moksafowo_payuni_redirect_default_tab' ) );

		add_filter( 'woocommerce_get_sections_' . $this->id, array( $this, 'moksafowo_payuni_payment_sections' ), 10, 1 );

		parent::__construct();
	}


	public function moksafowo_payuni_payment_sections( $sections ) {

		unset( $sections[''] );
		if ( is_array( $sections ) && ! array_key_exists( 'payment', $sections ) ) {
			$sections['payment'] = __( 'Payment Settings', 'moksa-for-woocommerce' );
		}
		return $sections;
	}


	public function get_settings_for_payment_section() {

		$settings = apply_filters(
			'moksafowo_payuni_payment_settings',
			array(
				array(
					'title' => __( '一般', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'id'    => 'payment_general_setting',
				),
				array(
					'title'   => __( '偵錯日誌', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'yes',
					'desc'    => sprintf(
						/* translators: %s = view logs link */
						__( '排查訂單異常時開啟。位置：WooCommerce → 狀態 → 日誌。 %s', 'moksa-for-woocommerce' ),
						$this->get_log_link()
					),
					'id'      => 'moksafowo_payuni_payment_debug_log_enabled',
				),
				array(
					'title'   => __( '結帳頁語言', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'css'     => 'width: 200px;',
					'options' => array(
						'zh-tw' => __( '繁體中文', 'moksa-for-woocommerce' ),
						'en'    => __( '英文', 'moksa-for-woocommerce' ),
					),
					'default' => 'zh-tw',
					'desc'    => __( 'PAYUNi 收銀台的顯示語言。', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_payuni_payment_language',
				),
				array(
					'title'   => __( '電子發票', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'no',
					'desc'    => __( '啟用 PAYUNi 的電子發票開立。需先到 PAYUNi 後台申請並啟用發票功能。', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_payuni_payment_einvoice_enabled',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'payment_general_setting',
				),

				array(
					'title' => __( '結帳頁呈現方式', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'desc'  => __( '決定顧客看到「一個按鈕跳轉 PAYUNi 選付款方式」還是「每個付款方式各一個按鈕」。', 'moksa-for-woocommerce' ),
					'id'    => 'moksafowo_payuni_display_setting',
				),
				array(
					'title'   => __( '顯示方式', 'moksa-for-woocommerce' ),
					'type'    => 'select',
					'css'     => 'width: 400px;',
					'options' => array(
						'multi'  => __( '分開顯示（每個付款方式一個按鈕，建議）', 'moksa-for-woocommerce' ),
						'single' => __( '合併顯示（一個「PAYUNi」按鈕跳轉收銀台再選）', 'moksa-for-woocommerce' ),
					),
					'default' => 'multi',
					'id'      => 'moksafowo_payuni_display_mode',
				),
				array(
					'title'   => __( '啟用的付款方式', 'moksa-for-woocommerce' ),
					'type'    => 'multiselect',
					'class'   => 'wc-enhanced-select',
					'css'     => 'width: 400px;',
					'options' => array(
						'moksafowo_payuni_credit'     => __( '信用卡', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_atm'        => __( 'ATM 虛擬帳號', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_cvs'        => __( '超商代碼', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_aftee'      => __( 'Aftee 後付', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_applepay'   => __( 'Apple Pay', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_googlepay'  => __( 'Google Pay', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_samsungpay' => __( 'Samsung Pay', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_linepay'    => __( 'LINE Pay', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_unionpay'   => __( '銀聯卡', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_icash'      => __( '愛金卡 iCash', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_jkopay'     => __( '街口支付', 'moksa-for-woocommerce' ),
						'moksafowo_payuni_credit_red' => __( '信用卡紅利點數', 'moksa-for-woocommerce' ),
					),
					'desc'    => __( '勾選的付款方式才會出現在結帳頁，未勾選不會出現。「合併顯示」模式下此欄位無效。', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_payuni_enabled_methods',
				),
				array(
					'title'   => __( '啟用的分期數', 'moksa-for-woocommerce' ),
					'type'    => 'multiselect',
					'class'   => 'wc-enhanced-select',
					'css'     => 'width: 400px;',
					'options' => array(
						CreditInstallment3::GATEWAY_ID  => __( '3 期', 'moksa-for-woocommerce' ),
						CreditInstallment6::GATEWAY_ID  => __( '6 期', 'moksa-for-woocommerce' ),
						CreditInstallment9::GATEWAY_ID  => __( '9 期', 'moksa-for-woocommerce' ),
						CreditInstallment12::GATEWAY_ID => __( '12 期', 'moksa-for-woocommerce' ),
						CreditInstallment18::GATEWAY_ID => __( '18 期', 'moksa-for-woocommerce' ),
						CreditInstallment24::GATEWAY_ID => __( '24 期', 'moksa-for-woocommerce' ),
						CreditInstallment30::GATEWAY_ID => __( '30 期', 'moksa-for-woocommerce' ),
					),
					'desc'    => sprintf(
						/* translators: %s = link to WC payment settings */
						__( '勾選的分期數會各自獨立成一個付款方式，仍需到付款方式設定逐一啟用。 %s', 'moksa-for-woocommerce' ),
						$this->get_woo_payment_settings_url()
					),
					'id'      => 'moksafowo_payuni_payment_installment_number_of_payments',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'moksafowo_payuni_display_setting',
				),

				array(
					'title' => __( '商家憑證', 'moksa-for-woocommerce' ),
					'type'  => 'title',
					'desc'  => __( '從 PAYUNi 後台「會員專區 → 整合設定」複製過來。', 'moksa-for-woocommerce' ),
					'id'    => 'moksafowo_payuni_payment_api_settings',
				),
				array(
					'title'   => __( '啟用測試模式', 'moksa-for-woocommerce' ),
					'type'    => 'checkbox',
					'default' => 'yes',
					'desc'    => __( '上線前用，勾選後，所有交易走測試環境不會真扣款。上線後請取消勾選。', 'moksa-for-woocommerce' ),
					'id'      => 'moksafowo_payuni_payment_testmode_enabled',
				),
				array(
					'title' => __( '測試 MerchantID', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_merchant_id_test',
				),
				array(
					'title' => __( '測試 HashKey', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashkey_test',
				),
				array(
					'title' => __( '測試 HashIV', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashiv_test',
				),
				array(
					'title' => __( '正式 MerchantID', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_merchant_id',
				),
				array(
					'title' => __( '正式 HashKey', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashkey',
				),
				array(
					'title' => __( '正式 HashIV', 'moksa-for-woocommerce' ),
					'type'  => 'text',
					'id'    => 'moksafowo_payuni_payment_hashiv',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'moksafowo_payuni_payment_api_settings',
				),
			)
		);

		return $settings;
	}


	public function moksafowo_payuni_redirect_default_tab() {

		global $pagenow;

		if ( 'admin.php' !== $pagenow ) {
			return;
		}

     // phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page    = ( array_key_exists( 'page', $_GET ) ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = ( array_key_exists( 'tab', $_GET ) ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = ( array_key_exists( 'section', $_GET ) ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		if ( 'wc-settings' === $page && 'payuni' === $tab ) {

			if ( empty( $section ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=payuni&section=payment' ) );
				exit;
			}
		}
	}


	public function output() {
		global $current_section;

		if ( 'payment' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::output_fields( $settings );
	}


	public function save() {
		global $current_section;

		if ( 'payment' !== $current_section ) {
			return;
		}

		$settings = $this->get_settings( $current_section );
		\WC_Admin_Settings::save_fields( $settings );
	}


	private function get_log_link() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '" target="_blank">' . __( 'View logs', 'moksa-for-woocommerce' ) . '</a>';
	}


	private function get_woo_payment_settings_url() {
		return '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . '" target="_blank">' . __( 'Go to Payment Settings', 'moksa-for-woocommerce' ) . '</a>';
	}
}
