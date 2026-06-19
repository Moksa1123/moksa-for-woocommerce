<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayInvoice\Frontend;

use MoksaWeb\Mowc\Modules\EcpayInvoice\Api\Helper;
use MoksaWeb\Mowc\Modules\Shared\Invoice\CheckoutAssets;
use MoksaWeb\Mowc\Modules\Shared\Invoice\InvoiceChannels;
use MoksaWeb\Mowc\Modules\Shared\Invoice\Ubn;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class CheckoutFields {

	public static function init(): void {
		// Classic checkout fields
		add_action( 'woocommerce_after_checkout_billing_form', [ __CLASS__, 'render_classic' ] );
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_classic' ], 10, 2 );

		// 格式驗證（統編 / 愛心碼 / 載具）走 woocommerce_after_checkout_validation —
		// Classic 與 Block (Store API) 都 fire，單一 method 兩路徑等價，避免 Block 漏驗。
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate_format' ], 10, 2 );

		// 統一的「載具 / 愛心碼真驗」hook — fires for both Classic + Block (Store API)。
		// 走 ECPay CheckBarcode / CheckLoveCode API 查財政部資料庫，避免顧客輸入合法格式
		// 但偽造的載具導致後續開立發票失敗、訂單卡死。
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate_carrier_via_api' ], 20, 2 );

		// Block checkout — register_additional_checkout_field
		// 直接呼叫：模組 boot 時 woocommerce_init 已 fire，掛 add_action 會錯過時機
		// （同 BlockField::init() 模式）
		self::register_block_fields();

		// Block 端把欄位值寫進 order meta（contact namespace）
		add_action( 'woocommerce_set_additional_field_value', [ __CLASS__, 'sync_block_field' ], 10, 4 );

		// 結帳頁互動 JS — 依發票類型 / 載具類型隱藏不適用欄位。
		// 走共用 CheckoutAssets — ezPay / SmilePay 模組獨立啟用時也用同一份 JS。
		CheckoutAssets::register();
	}

	public static function render_classic(): void {
		$prefix        = 'moksafowo_ecpay_invoice';
		$enabled_types = InvoiceChannels::enabled_types( $prefix );
		$allow_b2b     = in_array( 'b2b', $enabled_types, true );
		$allow_donate  = in_array( 'b2c_donate', $enabled_types, true );

		// 短詞 label — 格式錯誤靠 validation 提示，不在 dropdown 內囉嗦。
		$type_labels  = [
			'b2c_carrier' => __( '個人', 'mo-ectools' ),
			'b2b'         => __( '公司', 'mo-ectools' ),
			'b2c_donate'  => __( '捐贈', 'mo-ectools' ),
		];
		$type_options = [];
		foreach ( $enabled_types as $t ) {
			$type_options[ $t ] = $type_labels[ $t ];
		}

		// 載具選項 — 受該 provider 能力 + 逐項開關連動
		$carrier_labels  = [
			'member' => __( '會員載具', 'mo-ectools' ),
			'mobile' => __( '手機條碼', 'mo-ectools' ),
			'cert'   => __( '自然人憑證', 'mo-ectools' ),
			'paper'  => __( '紙本發票', 'mo-ectools' ),
		];
		$carrier_options = [];
		foreach ( InvoiceChannels::enabled_carriers( $prefix ) as $c ) {
			$carrier_options[ $c ] = $carrier_labels[ $c ];
		}

		// Wrap in a clearfix container — 統編 / 公司名稱 用 form-row-first / -last
		// (float left / right)，沒 clearfix 會讓後面的 ship-to-different H3 浮上來
		// 卡進兩個欄位中間。`overflow: hidden` 是最簡單的 clearfix 寫法。
		echo '<div class="moksafowo-invoice-section" style="clear:both;overflow:hidden;display:block;">';
		echo '<h3>' . esc_html__( '電子發票', 'mo-ectools' ) . '</h3>';
		woocommerce_form_field(
			'moksafowo_invoice_type',
			[
				'type'    => 'select',
				'label'   => __( '發票類型', 'mo-ectools' ),
				'class'   => [ 'form-row-wide' ],
				'options' => $type_options,
				'default' => 'b2c_carrier',
			]
		);

		woocommerce_form_field(
			'moksafowo_invoice_carrier_type',
			[
				'type'    => 'select',
				'label'   => __( '載具類型', 'mo-ectools' ),
				'class'   => [ 'form-row-wide', 'moksafowo-invoice-b2c-only' ],
				'options' => $carrier_options,
				'default' => InvoiceChannels::default_carrier( $prefix ),
			]
		);

		woocommerce_form_field(
			'moksafowo_invoice_carrier_num',
			[
				'type'  => 'text',
				'label' => __( '載具編號', 'mo-ectools' ),
				'class' => [ 'form-row-wide', 'moksafowo-invoice-carrier-num-row' ],
			]
		);

		if ( $allow_b2b ) {
			woocommerce_form_field(
				'moksafowo_invoice_buyer_ubn',
				[
					'type'  => 'text',
					'label' => __( '統一編號', 'mo-ectools' ),
					'class' => [ 'form-row-first', 'moksafowo-invoice-b2b-only' ],
				]
			);
			woocommerce_form_field(
				'moksafowo_invoice_buyer_name',
				[
					'type'  => 'text',
					'label' => __( '公司名稱', 'mo-ectools' ),
					'class' => [ 'form-row-last', 'moksafowo-invoice-b2b-only' ],
				]
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
						'class'   => [ 'form-row-wide', 'moksafowo-invoice-donate-only' ],
						'options' => InvoiceChannels::donate_select_options( $prefix ),
					]
				);
				woocommerce_form_field(
					'moksafowo_invoice_love_code',
					[
						'type'              => 'text',
						'label'             => __( '捐贈碼', 'mo-ectools' ),
						'class'             => [ 'form-row-wide', 'moksafowo-invoice-donate-only' ],
						'custom_attributes' => [ 'readonly' => 'readonly' ],
					]
				);
			} else {
				// 沒設定捐贈單位 → 捐贈碼開放自填
				woocommerce_form_field(
					'moksafowo_invoice_love_code',
					[
						'type'        => 'text',
						'label'       => __( '捐贈碼', 'mo-ectools' ),
						'class'       => [ 'form-row-wide', 'moksafowo-invoice-donate-only' ],
						'placeholder' => __( '3-7 碼數字', 'mo-ectools' ),
					]
				);
			}
		}
		echo '</div>'; // .moksafowo-invoice-section
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
			$carrier     = self::field_value( $data, [ 'moksafowo_invoice_carrier_type', '_mowp/invoice-carrier-type', 'mowp/invoice-carrier-type' ] );
			$carrier_num = self::field_value( $data, [ 'moksafowo_invoice_carrier_num', '_mowp/invoice-carrier-num', 'mowp/invoice-carrier-num' ] );
			if ( 'mobile' === $carrier && ! preg_match( '/^\/[0-9A-Z+\-.]{7}$/', $carrier_num ) ) {
				$errors->add( 'moksafowo_invoice_carrier_mobile', __( '手機條碼格式錯誤（/ 開頭加 7 碼大寫英數）。', 'mo-ectools' ) );
			}
			if ( 'cert' === $carrier && ! preg_match( '/^[A-Z]{2}\d{14}$/', $carrier_num ) ) {
				$errors->add( 'moksafowo_invoice_carrier_cert', __( '自然人憑證格式錯誤（2 大寫字母 + 14 碼數字）。', 'mo-ectools' ) );
			}
		}
	}

	public static function validate_carrier_via_api( $data, $errors ): void {
		if ( ! ( $errors instanceof \WP_Error ) ) {
			return;
		}
		// 商家 opt-out
		if ( 'yes' !== get_option( 'moksafowo_ecpay_invoice_carrier_api_check', 'yes' ) ) {
			return;
		}

		// 撈 invoice_type — Classic 用 moksafowo_invoice_type，Block 走 additional fields key
		// $data 是 WC checkout 的 cleaned post data；$_POST 是原始的（Block fallback）
		$type        = self::field_value( $data, [ 'moksafowo_invoice_type', '_mowp/invoice-type', 'mowp/invoice-type' ] );
		$carrier     = self::field_value( $data, [ 'moksafowo_invoice_carrier_type', '_mowp/invoice-carrier-type', 'mowp/invoice-carrier-type' ] );
		$carrier_num = self::field_value( $data, [ 'moksafowo_invoice_carrier_num', '_mowp/invoice-carrier-num', 'mowp/invoice-carrier-num' ] );
		$love_code   = self::field_value( $data, [ 'moksafowo_invoice_love_code', '_mowp/invoice-love-code', 'mowp/invoice-love-code' ] );

		// 個人 + 手機條碼 → CheckBarcode
		if ( 'b2c_carrier' === $type && 'mobile' === $carrier && '' !== $carrier_num ) {
			$result = Helper::check_barcode( $carrier_num );
			// 真驗失敗（exists=N）才擋；HTTP / 解密錯一般不擋（避免 ECPay 服務問題擋單）
			if ( $result['ok'] && ! $result['exists'] ) {
				$errors->add( 'moksafowo_ecpay_invoice_barcode_invalid', $result['message'] );
			}
			// 格式錯誤（1040/1041）已由 validate_classic + Block additional field 自身擋下，
			// 此處不重複報錯避免商家看到兩條相同訊息。
		}

		// 捐贈 → CheckLoveCode
		if ( 'b2c_donate' === $type && '' !== $love_code ) {
			$result = Helper::check_love_code( $love_code );
			if ( $result['ok'] && ! $result['exists'] ) {
				$errors->add( 'moksafowo_ecpay_invoice_love_code_invalid', $result['message'] );
			}
			if ( ! $result['ok'] && in_array( $result['code'], [ '1020', '1021' ], true ) ) {
				$errors->add( 'moksafowo_ecpay_invoice_love_code_format', $result['message'] );
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
		// Block additional fields 在 Store API request 進來時帶 `additional_fields[mowp/invoice-...]` 結構。
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- upstream WC checkout nonce / Store API auth verified before this validation callback fires.
		if ( isset( $_POST['additional_fields'] ) && is_array( $_POST['additional_fields'] ) ) {
			foreach ( $keys as $k ) {
				$bare = preg_replace( '#^_#', '', $k ); // 拿掉 leading _（Block 沒有）
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above; sanitized at extraction.
				if ( isset( $_POST['additional_fields'][ $bare ] ) ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above; sanitized at extraction.
					return (string) sanitize_text_field( wp_unslash( $_POST['additional_fields'][ $bare ] ) );
				}
			}
		}
		// 最後 fallback 到 $_POST top-level（Classic 場景）
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
		foreach ( $keys as $field => $meta_key ) {
			if ( isset( $_POST[ $field ] ) ) {
				$order->update_meta_data( $meta_key, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}
	}

	public static function register_block_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$prefix        = 'moksafowo_ecpay_invoice';
		$enabled_types = InvoiceChannels::enabled_types( $prefix );
		$allow_b2b     = in_array( 'b2b', $enabled_types, true );
		$allow_donate  = in_array( 'b2c_donate', $enabled_types, true );

		// option label 走最簡短稱，dropdown 不寫格式說明。
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
			'member' => __( '會員載具', 'mo-ectools' ),
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
						'validate_callback' => [ __CLASS__, 'validate_love_code_block' ],
						'required'          => false,
					]
				);
			} else {
				woocommerce_register_additional_checkout_field(
					[
						'id'                => 'mowp/invoice-love-code',
						'label'             => __( '捐贈碼', 'mo-ectools' ),
						'location'          => 'order',
						'type'              => 'text',
						'validate_callback' => [ __CLASS__, 'validate_love_code_block' ],
						'required'          => false,
					]
				);
			}
		}
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
	}
}
