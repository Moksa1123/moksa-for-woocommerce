<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Payuni\Gateways;

defined( 'ABSPATH' ) || exit;

final class Unified extends GatewayBase {

	const GATEWAY_ID = 'moksafowo_payuni_unified';

	private const METHOD_MAP = array(
		'moksafowo_payuni_credit'     => 'Credit',
		'moksafowo_payuni_icash'      => 'ICash',
		'moksafowo_payuni_aftee'      => 'Aftee',
		'moksafowo_payuni_linepay'    => 'LinePay',
		'moksafowo_payuni_jkopay'     => 'JKoPay',
		'moksafowo_payuni_atm'        => 'ATM',
		'moksafowo_payuni_cvs'        => 'CVS',
		'moksafowo_payuni_unionpay'   => 'CreditUnionPay',
		'moksafowo_payuni_credit_red' => 'CreditRed',
		'moksafowo_payuni_applepay'   => 'ApplePay',
		'moksafowo_payuni_googlepay'  => 'GooglePay',
		'moksafowo_payuni_samsungpay' => 'SamsungPay',
	);

	public function __construct() {
		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi 統一金流（單一入口）', 'mo-ectools' );
		$this->method_description = __( '結帳呈現模式為「單一入口」時啟用。顧客點選後跳轉至 PAYUNi 收銀台選擇具體付款方式。', 'mo-ectools' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'moksafowo_payuni_transaction_args_' . $this->id, array( $this, 'inject_enabled_methods' ), 10, 2 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'             => array(
				'title'       => __( '啟用', 'mo-ectools' ),
				'type'        => 'checkbox',
				'label'       => __( '啟用 PAYUNi 統一金流（單一入口）', 'mo-ectools' ),
				'description' => __( '注意：啟用「單一入口」顯示模式時才會出現在結帳頁。', 'mo-ectools' ),
				'default'     => 'yes',
			),
			'title'               => array(
				'title'   => __( '結帳顯示名稱', 'mo-ectools' ),
				'type'    => 'text',
				'default' => __( '統一金流 PAYUNi', 'mo-ectools' ),
			),
			'description'         => array(
				'title'   => __( '結帳顯示說明', 'mo-ectools' ),
				'type'    => 'textarea',
				'default' => __( '整合各式付款工具，按下「下單」後將跳轉至 PAYUNi 收銀台。', 'mo-ectools' ),
			),
			'allow_installment'   => array(
				'title'   => __( '允許分期付款', 'mo-ectools' ),
				'type'    => 'checkbox',
				'label'   => __( '在 PAYUNi 收銀台顯示信用卡分期選項', 'mo-ectools' ),
				'default' => 'no',
			),
			'installment_periods' => array(
				'title'    => __( '可用分期數', 'mo-ectools' ),
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'css'      => 'width: 400px;',
				'options'  => array(
					3  => '3',
					6  => '6',
					9  => '9',
					12 => '12',
					18 => '18',
					24 => '24',
					30 => '30',
				),
				'desc'     => __( '勾選「允許分期付款」時生效，勾選的期數將在 PAYUNi 付款頁供顧客選擇。', 'mo-ectools' ),
				'desc_tip' => true,
			),
		);
	}

	public function is_available() {
		if ( 'single' !== get_option( 'moksafowo_payuni_display_mode', 'multi' ) ) {
			return false;
		}
		return parent::is_available();
	}

	public function inject_enabled_methods( $args, $order ) {
		foreach ( self::METHOD_MAP as $moksafowo_id => $pay_flag ) {
			$settings = (array) get_option( 'woocommerce_' . $moksafowo_id . '_settings', array() );
			if ( 'yes' === ( $settings['enabled'] ?? 'no' ) ) {
				$args[ $pay_flag ] = '1';
			}
		}

		// Installments — own toggle on Unified itself.
		if ( 'yes' === $this->get_option( 'allow_installment' ) ) {
			$periods = (array) $this->get_option( 'installment_periods', array() );
			$periods = array_filter( array_map( 'intval', $periods ) );
			if ( ! empty( $periods ) ) {
				$args['CreditInst'] = implode( ',', $periods );
			}
		}

		return $args;
	}
}
