<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EzpayInvoice\Frontend;

use MoksaWeb\Mowc\Modules\Shared\Invoice\CheckoutAssets;
use MoksaWeb\Mowc\Modules\Shared\Invoice\InvoiceChannels;
use MoksaWeb\Mowc\Modules\Shared\Invoice\Ubn;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class CheckoutFields {

	public static function init(): void {
		// 跟 ECPay 發票模組相容：ECPay 開了就讓它 own 欄位，ezPay 不重複註冊
		if ( 'yes' === get_option( 'moksafowo_ecpay_invoice_enabled', 'no' ) ) {
			return;
		}

		// Classic checkout fields
		add_action( 'woocommerce_after_checkout_billing_form', [ __CLASS__, 'render_classic' ] );
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_classic' ], 10, 2 );

		// 格式驗證走 woocommerce_after_checkout_validation — Classic 與 Block 都 fire，
		// 單一 method 兩路徑等價，避免 Block 漏驗。
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate_format' ], 10, 2 );

		// Block checkout — register_additional_checkout_field
		self::register_block_fields();
		add_action( 'woocommerce_set_additional_field_value', [ __CLASS__, 'sync_block_field' ], 10, 4 );

		// 結帳頁互動 JS — 共用 ECPay 那份，依發票類型 / 載具類型 hide/show
		CheckoutAssets::register();
	}

	public static function render_classic(): void {
		$prefix        = 'moksafowo_ezpay_invoice';
		$enabled_types = InvoiceChannels::enabled_types( $prefix );
		$allow_b2b     = in_array( 'b2b', $enabled_types, true );
		$allow_donate  = in_array( 'b2c_donate', $enabled_types, true );

		$type_labels  = [
			'b2c_carrier' => __( '個人', 'mo-ectools' ),
			'b2b'         => __( '公司', 'mo-ectools' ),
			'b2c_donate'  => __( '捐贈', 'mo-ectools' ),
		];
		$type_options = [];
		foreach ( $enabled_types as $t ) {
			$type_options[ $t ] = $type_labels[ $t ];
		}

		$carrier_labels  = [
			'member' => __( 'ezPay 會員載具', 'mo-ectools' ),
			'mobile' => __( '手機條碼', 'mo-ectools' ),
			'cert'   => __( '自然人憑證', 'mo-ectools' ),
			'paper'  => __( '紙本發票', 'mo-ectools' ),
		];
		$carrier_options = [];
		foreach ( InvoiceChannels::enabled_carriers( $prefix ) as $c ) {
			$carrier_options[ $c ] = $carrier_labels[ $c ];
		}

		echo '<div class="moksafowo-ezpay-invoice-fields"><h3>' . esc_html__( '電子發票', 'mo-ectools' ) . '</h3>';

		woocommerce_form_field(
			'moksafowo_invoice_type',
			[
				'type'     => 'select',
				'label'    => __( '發票類型', 'mo-ectools' ),
				'options'  => $type_options,
				'default'  => 'b2c_carrier',
				'required' => true,
				'class'    => [ 'form-row-wide' ],
			],
			(string) WC()->checkout->get_value( 'moksafowo_invoice_type' )
		);

		woocommerce_form_field(
			'moksafowo_invoice_carrier_type',
			[
				'type'    => 'select',
				'label'   => __( '載具類型', 'mo-ectools' ),
				'options' => $carrier_options,
				'default' => InvoiceChannels::default_carrier( $prefix ),
				'class'   => [ 'form-row-wide', 'moksafowo-invoice-b2c-only' ],
			],
			(string) WC()->checkout->get_value( 'moksafowo_invoice_carrier_type' )
		);

		woocommerce_form_field(
			'moksafowo_invoice_carrier_num',
			[
				'type'  => 'text',
				'label' => __( '載具編號', 'mo-ectools' ),
				'class' => [ 'form-row-wide' ],
			],
			(string) WC()->checkout->get_value( 'moksafowo_invoice_carrier_num' )
		);

		if ( $allow_b2b ) {
			woocommerce_form_field(
				'moksafowo_invoice_buyer_ubn',
				[
					'type'  => 'text',
					'label' => __( '統一編號', 'mo-ectools' ),
					'class' => [ 'form-row-wide' ],
				],
				(string) WC()->checkout->get_value( 'moksafowo_invoice_buyer_ubn' )
			);
			woocommerce_form_field(
				'moksafowo_invoice_buyer_name',
				[
					'type'  => 'text',
					'label' => __( '公司名稱', 'mo-ectools' ),
					'class' => [ 'form-row-wide' ],
				],
				(string) WC()->checkout->get_value( 'moksafowo_invoice_buyer_name' )
			);
		}

		if ( $allow_donate ) {
			if ( InvoiceChannels::has_donate_orgs( $prefix ) ) {
				// 捐贈單位（名稱下拉）+ 捐贈碼（唯讀，JS 依選到的單位帶入）
				woocommerce_form_field(
					'moksafowo_invoice_donate_org',
					[
						'type'    => 'select',
						'label'   => __( '捐贈單位', 'mo-ectools' ),
						'class'   => [ 'form-row-wide' ],
						'options' => InvoiceChannels::donate_select_options( $prefix ),
					],
					(string) WC()->checkout->get_value( 'moksafowo_invoice_donate_org' )
				);
				woocommerce_form_field(
					'moksafowo_invoice_love_code',
					[
						'type'              => 'text',
						'label'             => __( '捐贈碼', 'mo-ectools' ),
						'class'             => [ 'form-row-wide' ],
						'custom_attributes' => [ 'readonly' => 'readonly' ],
					],
					(string) WC()->checkout->get_value( 'moksafowo_invoice_love_code' )
				);
			} else {
				woocommerce_form_field(
					'moksafowo_invoice_love_code',
					[
						'type'  => 'text',
						'label' => __( '捐贈碼', 'mo-ectools' ),
						'class' => [ 'form-row-wide' ],
					],
					(string) WC()->checkout->get_value( 'moksafowo_invoice_love_code' )
				);
			}
		}

		echo '</div>';
	}

	public static function validate_format( $data, $errors ): void {
		if ( ! ( $errors instanceof \WP_Error ) ) {
			return;
		}
		$data = is_array( $data ) ? $data : [];
		$type = self::field_value( $data, [ 'moksafowo_invoice_type', '_mowp/invoice-type', 'mowp/invoice-type' ] );

		if ( 'b2b' === $type ) {
			$ubn = self::field_value( $data, [ 'moksafowo_invoice_buyer_ubn', '_mowp/invoice-buyer-ubn', 'mowp/invoice-buyer-ubn' ] );
			if ( ! Ubn::is_valid( $ubn ) ) {
				$errors->add( 'moksafowo_invoice_ubn', __( '統一編號格式或檢查碼不正確。', 'mo-ectools' ) );
			}
		}
		if ( 'b2c_donate' === $type ) {
			$code = self::field_value( $data, [ 'moksafowo_invoice_love_code', '_mowp/invoice-love-code', 'mowp/invoice-love-code' ] );
			if ( ! preg_match( '/^([xX]\d{2,6}|\d{3,7})$/', $code ) ) {
				$errors->add( 'moksafowo_invoice_love_code', __( '愛心碼格式錯誤（3-7 碼數字）。', 'mo-ectools' ) );
			}
		}
		if ( 'b2c_carrier' === $type ) {
			$carrier = self::field_value( $data, [ 'moksafowo_invoice_carrier_type', '_mowp/invoice-carrier-type', 'mowp/invoice-carrier-type' ] );
			$cnum    = self::field_value( $data, [ 'moksafowo_invoice_carrier_num', '_mowp/invoice-carrier-num', 'mowp/invoice-carrier-num' ] );
			if ( 'mobile' === $carrier && ! preg_match( '#^/[0-9a-zA-Z+\-.]{7}$#', $cnum ) ) {
				$errors->add( 'moksafowo_invoice_carrier_mobile', __( '手機條碼格式錯誤（/ 開頭加 7 碼）。', 'mo-ectools' ) );
			}
			if ( 'cert' === $carrier && ! preg_match( '/^[A-Z]{2}\d{14}$/', $cnum ) ) {
				$errors->add( 'moksafowo_invoice_carrier_cert', __( '自然人憑證格式錯誤（2 大寫字母 + 14 碼數字）。', 'mo-ectools' ) );
			}
		}
	}

	private static function field_value( array $data, array $keys ): string {
		foreach ( $keys as $k ) {
			if ( isset( $data[ $k ] ) && '' !== $data[ $k ] ) {
				return (string) $data[ $k ];
			}
		}
		// 唯讀驗證用途：本方法只在 woocommerce_after_checkout_validation 內被呼叫 —
		// Classic 由 WC_Checkout::process_checkout() 先驗 woocommerce-process-checkout-nonce，
		// Block 由 Store API 的 Cart-Token / 認證層把關，值一律 sanitize 後回傳。
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- upstream WC checkout nonce / Store API auth verified before this validation callback fires.
		if ( isset( $_POST['additional_fields'] ) && is_array( $_POST['additional_fields'] ) ) {
			foreach ( $keys as $k ) {
				$bare = preg_replace( '#^_#', '', $k );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above; sanitized at extraction.
				if ( isset( $_POST['additional_fields'][ $bare ] ) ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above; sanitized at extraction.
					return (string) sanitize_text_field( wp_unslash( $_POST['additional_fields'][ $bare ] ) );
				}
			}
		}
		foreach ( $keys as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above; sanitized at extraction.
			if ( isset( $_POST[ $k ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above; sanitized at extraction.
				return (string) sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			}
		}
		return '';
	}

	public static function save_classic( \WC_Order $order, array $data ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- WC checkout submit nonce 'woocommerce-process-checkout-nonce' verified by WC core in WC_Checkout::process_checkout() before this callback fires.
		$keys = [
			'moksafowo_invoice_type'         => Keys::INVOICE_TYPE,
			'moksafowo_invoice_carrier_type' => Keys::INVOICE_CARRIER_TYPE,
			'moksafowo_invoice_carrier_num'  => Keys::INVOICE_CARRIER_NUM,
			'moksafowo_invoice_buyer_ubn'    => Keys::INVOICE_BUYER_UBN,
			'moksafowo_invoice_buyer_name'   => Keys::INVOICE_BUYER_NAME,
			'moksafowo_invoice_love_code'    => Keys::INVOICE_LOVE_CODE,
		];
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		foreach ( $keys as $field => $meta_key ) {
			if ( isset( $_POST[ $field ] ) ) {
				$order->update_meta_data( $meta_key, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}
		// 標記由 ezPay 處理（給多 provider 環境路由用）
		$order->update_meta_data( Keys::INVOICE_PROVIDER, 'ezpay' );
		// phpcs:enable
	}

	private static function register_block_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$prefix        = 'moksafowo_ezpay_invoice';
		$enabled_types = InvoiceChannels::enabled_types( $prefix );
		$allow_b2b     = in_array( 'b2b', $enabled_types, true );
		$allow_donate  = in_array( 'b2c_donate', $enabled_types, true );

		$type_labels  = [
			'b2c_carrier' => __( '個人', 'mo-ectools' ),
			'b2b'         => __( '公司', 'mo-ectools' ),
			'b2c_donate'  => __( '捐贈', 'mo-ectools' ),
		];
		$type_options = [];
		foreach ( $enabled_types as $t ) {
			$type_options[] = [
				'value' => $t,
				'label' => $type_labels[ $t ],
			];
		}

		$carrier_labels  = [
			'member' => __( 'ezPay 會員載具', 'mo-ectools' ),
			'mobile' => __( '手機條碼', 'mo-ectools' ),
			'cert'   => __( '自然人憑證', 'mo-ectools' ),
			'paper'  => __( '紙本發票', 'mo-ectools' ),
		];
		$carrier_options = [];
		foreach ( InvoiceChannels::enabled_carriers( $prefix ) as $c ) {
			$carrier_options[] = [
				'value' => $c,
				'label' => $carrier_labels[ $c ],
			];
		}

		woocommerce_register_additional_checkout_field(
			[
				'id'       => 'mowp/invoice-type',
				'label'    => __( '發票類型', 'mo-ectools' ),
				'location' => 'order',
				'type'     => 'select',
				'options'  => $type_options,
				'required' => true,
			]
		);
		woocommerce_register_additional_checkout_field(
			[
				'id'       => 'mowp/invoice-carrier-type',
				'label'    => __( '載具類型', 'mo-ectools' ),
				'location' => 'order',
				'type'     => 'select',
				'options'  => $carrier_options,
				'required' => false,
			]
		);
		woocommerce_register_additional_checkout_field(
			[
				'id'       => 'mowp/invoice-carrier-num',
				'label'    => __( '載具編號', 'mo-ectools' ),
				'location' => 'order',
				'type'     => 'text',
				'required' => false,
			]
		);
		if ( $allow_b2b ) {
			woocommerce_register_additional_checkout_field(
				[
					'id'                => 'mowp/invoice-buyer-ubn',
					'label'             => __( '統一編號', 'mo-ectools' ),
					'location'          => 'order',
					'type'              => 'text',
					'required'          => false,
					'validate_callback' => [ __CLASS__, 'validate_ubn_block' ],
				]
			);
			woocommerce_register_additional_checkout_field(
				[
					'id'       => 'mowp/invoice-buyer-name',
					'label'    => __( '公司名稱', 'mo-ectools' ),
					'location' => 'order',
					'type'     => 'text',
					'required' => false,
				]
			);
		}
		if ( $allow_donate ) {
			if ( InvoiceChannels::has_donate_orgs( $prefix ) ) {
				// 捐贈單位（名稱下拉）+ 捐贈碼（唯讀文字，JS 依選到的單位帶入）
				woocommerce_register_additional_checkout_field(
					[
						'id'       => 'mowp/invoice-donate-org',
						'label'    => __( '捐贈單位', 'mo-ectools' ),
						'location' => 'order',
						'type'     => 'select',
						'options'  => InvoiceChannels::donate_block_options( $prefix ),
						'required' => false,
					]
				);
				woocommerce_register_additional_checkout_field(
					[
						'id'                => 'mowp/invoice-love-code',
						'label'             => __( '捐贈碼', 'mo-ectools' ),
						'location'          => 'order',
						'type'              => 'text',
						'required'          => false,
						'validate_callback' => [ __CLASS__, 'validate_love_code_block' ],
					]
				);
			} else {
				woocommerce_register_additional_checkout_field(
					[
						'id'                => 'mowp/invoice-love-code',
						'label'             => __( '捐贈碼', 'mo-ectools' ),
						'location'          => 'order',
						'type'              => 'text',
						'required'          => false,
						'validate_callback' => [ __CLASS__, 'validate_love_code_block' ],
					]
				);
			}
		}
	}

	public static function validate_ubn_block( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return null;
		}
		if ( ! Ubn::is_valid( $value ) ) {
			return new \WP_Error( 'moksafowo_invoice_ubn_format', __( '統一編號格式或檢查碼不正確。', 'mo-ectools' ) );
		}
		return null;
	}

	public static function validate_love_code_block( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return null;
		}
		if ( ! preg_match( '/^([xX]\d{2,6}|\d{3,7})$/', $value ) ) {
			return new \WP_Error( 'moksafowo_invoice_love_code_format', __( '愛心碼格式錯誤（3-7 碼數字）。', 'mo-ectools' ) );
		}
		return null;
	}

	public static function sync_block_field( $key, $value, $group, $wc_object ): void {
		if ( ! ( $wc_object instanceof \WC_Order ) ) {
			return;
		}
		$map = [
			'mowp/invoice-type'         => Keys::INVOICE_TYPE,
			'mowp/invoice-carrier-type' => Keys::INVOICE_CARRIER_TYPE,
			'mowp/invoice-carrier-num'  => Keys::INVOICE_CARRIER_NUM,
			'mowp/invoice-buyer-ubn'    => Keys::INVOICE_BUYER_UBN,
			'mowp/invoice-buyer-name'   => Keys::INVOICE_BUYER_NAME,
			'mowp/invoice-love-code'    => Keys::INVOICE_LOVE_CODE,
		];
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		$wc_object->update_meta_data( $map[ $key ], (string) $value );
		// 標記由 ezPay 處理
		$wc_object->update_meta_data( Keys::INVOICE_PROVIDER, 'ezpay' );
	}
}
