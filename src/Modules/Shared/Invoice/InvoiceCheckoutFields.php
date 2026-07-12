<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Invoice;

use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 電子發票結帳欄位的共用 registrar — 5 個發票 provider（ECPay / ezPay / SmilePay / PayNow / AMEGO）
 * 共用同一份 Classic + Block 欄位邏輯，差異走 InvoiceFieldConfig 注入。
 *
 * 協調：多家發票同時啟用時，依固定優先序只讓最高優先者註冊欄位（單向讓位，無重複註冊）。
 * 欄位 namespace `moksafowo/invoice-*`、meta 走 Order\Meta\Keys，跨 provider 一致。
 */
final class InvoiceCheckoutFields {

	/** 協調優先序（小者優先）。最高優先且啟用者 own 欄位，其餘讓位。 */
	private const PRIORITY = [ 'ecpay', 'ezpay', 'smilepay', 'paynow', 'amego' ];

	private static ?InvoiceFieldConfig $config = null;

	public static function boot( InvoiceFieldConfig $cfg ): void {
		// 優先序協調 — 任一更高優先 provider 啟用就讓位。
		foreach ( self::PRIORITY as $slug ) {
			if ( $slug === $cfg->provider_slug ) {
				break;
			}
			if ( 'yes' === get_option( "moksafowo_{$slug}_invoice_enabled", 'no' ) ) {
				return;
			}
		}

		self::$config = $cfg;

		// Classic checkout。
		add_action( 'woocommerce_after_checkout_billing_form', [ self::class, 'render_classic' ] );
		add_action( 'woocommerce_checkout_create_order', [ self::class, 'save_classic' ], 10, 2 );

		// 格式驗證走 woocommerce_after_checkout_validation — Classic 與 Block (Store API) 都 fire，
		// 單一 method 兩路徑等價，避免 Block 漏驗。
		add_action( 'woocommerce_after_checkout_validation', [ self::class, 'validate_format' ], 10, 2 );

		// Block checkout — woocommerce_init 已 fire，直接呼叫（掛 add_action 會錯過時機）。
		self::register_block_fields();
		add_action( 'woocommerce_set_additional_field_value', [ self::class, 'sync_block_field' ], 10, 4 );

		// 載具真驗（僅 ECPay 注入，查財政部資料庫擋偽造載具）。
		if ( null !== $cfg->carrier_api_validator ) {
			add_action( 'woocommerce_after_checkout_validation', $cfg->carrier_api_validator, 20, 2 );
		}

		// 結帳頁互動 JS — 依發票類型 / 載具類型 hide/show。
		CheckoutAssets::register();
	}

	/**
	 * JSON Schema 片段：當 moksafowo/invoice-type === $type 時命中。
	 * 用於 Block 欄位的 required（正向）。WC 對 hidden 用反轉（not+const），見 when_type_not()。
	 */
	private static function when_type( string $type ): array {
		return [
			'type'       => 'object',
			'properties' => [
				'checkout' => [
					'properties' => [
						'additional_fields' => [
							'properties' => [
								'moksafowo/invoice-type' => [ 'const' => $type ],
							],
						],
					],
				],
			],
		];
	}

	/** 反轉：moksafowo/invoice-type !== $type 時命中（用於 hidden — 不該顯示就藏）。 */
	private static function when_type_not( string $type ): array {
		return [
			'type'       => 'object',
			'properties' => [
				'checkout' => [
					'properties' => [
						'additional_fields' => [
							'properties' => [
								'moksafowo/invoice-type' => [ 'not' => [ 'const' => $type ] ],
							],
						],
					],
				],
			],
		];
	}

	/** 反轉：moksafowo/invoice-carrier-type !== $carrier 時命中（用於 hidden）。 */
	private static function when_carrier_not( string $carrier ): array {
		return [
			'type'       => 'object',
			'properties' => [
				'checkout' => [
					'properties' => [
						'additional_fields' => [
							'properties' => [
								'moksafowo/invoice-carrier-type' => [ 'not' => [ 'const' => $carrier ] ],
							],
						],
					],
				],
			],
		];
	}

	/** moksafowo/invoice-type === $type 「且」moksafowo/invoice-carrier-type === $carrier 時命中（用於 required，AND）。 */
	private static function when_type_and_carrier( string $type, string $carrier ): array {
		return [
			'type'       => 'object',
			'properties' => [
				'checkout' => [
					'properties' => [
						'additional_fields' => [
							'properties' => [
								'moksafowo/invoice-type' => [ 'const' => $type ],
								'moksafowo/invoice-carrier-type' => [ 'const' => $carrier ],
							],
						],
					],
				],
			],
		];
	}

	private static function type_labels(): array {
		return [
			'b2c_carrier' => __( '個人', 'mo-ectools' ),
			'b2b'         => __( '公司', 'mo-ectools' ),
			'b2c_donate'  => __( '捐贈', 'mo-ectools' ),
		];
	}

	private static function carrier_labels(): array {
		$member = ( null !== self::$config && '' !== self::$config->member_label )
			? self::$config->member_label
			: __( '會員載具', 'mo-ectools' );
		return [
			'member' => $member,
			'mobile' => __( '手機條碼', 'mo-ectools' ),
			'cert'   => __( '自然人憑證', 'mo-ectools' ),
			'paper'  => __( '紙本發票', 'mo-ectools' ),
		];
	}

	public static function render_classic(): void {
		if ( null === self::$config ) {
			return;
		}
		$prefix        = self::$config->option_prefix;
		$enabled_types = InvoiceChannels::enabled_types( $prefix );
		$allow_b2b     = in_array( 'b2b', $enabled_types, true );
		$allow_donate  = in_array( 'b2c_donate', $enabled_types, true );

		// 短詞 label — 格式錯誤靠 validation 提示，不在 dropdown 內囉嗦。
		$type_labels  = self::type_labels();
		$type_options = [];
		foreach ( $enabled_types as $t ) {
			$type_options[ $t ] = $type_labels[ $t ];
		}

		// 載具選項 — 受該 provider 能力 + 逐項開關連動。
		$carrier_labels  = self::carrier_labels();
		$carrier_options = [];
		foreach ( InvoiceChannels::enabled_carriers( $prefix ) as $c ) {
			$carrier_options[ $c ] = $carrier_labels[ $c ];
		}

		// clearfix wrapper — 統編 / 公司名稱 用 form-row-first / -last（float），沒 clearfix 會讓
		// 後面的 ship-to-different H3 浮上來。.moksafowo-invoice-section 有對應 CSS（display:flow-root）。
		echo '<div class="moksafowo-invoice-section" style="clear:both;overflow:hidden;display:block;">';
		echo '<h3>' . esc_html__( '電子發票', 'mo-ectools' ) . '</h3>';

		woocommerce_form_field(
			'moksafowo_invoice_type',
			[
				'type'     => 'select',
				'label'    => __( '發票類型', 'mo-ectools' ),
				'class'    => [ 'form-row-wide' ],
				'options'  => $type_options,
				'default'  => 'b2c_carrier',
				'required' => true,
			],
			(string) WC()->checkout->get_value( 'moksafowo_invoice_type' )
		);

		woocommerce_form_field(
			'moksafowo_invoice_carrier_type',
			[
				'type'    => 'select',
				'label'   => __( '載具類型', 'mo-ectools' ),
				'class'   => [ 'form-row-wide', 'moksafowo-invoice-b2c-only' ],
				'options' => $carrier_options,
				'default' => InvoiceChannels::default_carrier( $prefix ),
			],
			(string) WC()->checkout->get_value( 'moksafowo_invoice_carrier_type' )
		);

		woocommerce_form_field(
			'moksafowo_invoice_carrier_num',
			[
				'type'  => 'text',
				'label' => __( '載具編號', 'mo-ectools' ),
				'class' => [ 'form-row-wide', 'moksafowo-invoice-carrier-num-row' ],
			],
			(string) WC()->checkout->get_value( 'moksafowo_invoice_carrier_num' )
		);

		if ( $allow_b2b ) {
			woocommerce_form_field(
				'moksafowo_invoice_buyer_ubn',
				[
					'type'  => 'text',
					'label' => __( '統一編號', 'mo-ectools' ),
					'class' => [ 'form-row-first', 'moksafowo-invoice-b2b-only' ],
				],
				(string) WC()->checkout->get_value( 'moksafowo_invoice_buyer_ubn' )
			);
			woocommerce_form_field(
				'moksafowo_invoice_buyer_name',
				[
					'type'  => 'text',
					'label' => __( '公司名稱', 'mo-ectools' ),
					'class' => [ 'form-row-last', 'moksafowo-invoice-b2b-only' ],
				],
				(string) WC()->checkout->get_value( 'moksafowo_invoice_buyer_name' )
			);
		}

		if ( $allow_donate ) {
			if ( InvoiceChannels::has_donate_orgs( $prefix ) ) {
				// 捐贈單位（名稱下拉）+ 捐贈碼（唯讀，JS 依選到的單位帶入）。
				woocommerce_form_field(
					'moksafowo_invoice_donate_org',
					[
						'type'    => 'select',
						'label'   => __( '捐贈單位', 'mo-ectools' ),
						'class'   => [ 'form-row-wide', 'moksafowo-invoice-donate-only' ],
						'options' => InvoiceChannels::donate_select_options( $prefix ),
					],
					(string) WC()->checkout->get_value( 'moksafowo_invoice_donate_org' )
				);
				woocommerce_form_field(
					'moksafowo_invoice_love_code',
					[
						'type'              => 'text',
						'label'             => __( '捐贈碼', 'mo-ectools' ),
						'class'             => [ 'form-row-wide', 'moksafowo-invoice-donate-only' ],
						'custom_attributes' => [ 'readonly' => 'readonly' ],
					],
					(string) WC()->checkout->get_value( 'moksafowo_invoice_love_code' )
				);
			} else {
				// 沒設定捐贈單位 → 捐贈碼開放自填。
				woocommerce_form_field(
					'moksafowo_invoice_love_code',
					[
						'type'        => 'text',
						'label'       => __( '捐贈碼', 'mo-ectools' ),
						'class'       => [ 'form-row-wide', 'moksafowo-invoice-donate-only' ],
						'placeholder' => __( '3-7 碼數字', 'mo-ectools' ),
					],
					(string) WC()->checkout->get_value( 'moksafowo_invoice_love_code' )
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
		$type = self::field_value( $data, [ 'moksafowo_invoice_type', '_moksafowo/invoice-type', 'moksafowo/invoice-type' ] );

		if ( 'b2b' === $type ) {
			$ubn = self::field_value( $data, [ 'moksafowo_invoice_buyer_ubn', '_moksafowo/invoice-buyer-ubn', 'moksafowo/invoice-buyer-ubn' ] );
			if ( ! Ubn::is_valid( $ubn ) ) {
				$errors->add( 'moksafowo_invoice_ubn', __( '統一編號格式或檢查碼不正確。', 'mo-ectools' ) );
			}
		}

		if ( 'b2c_donate' === $type ) {
			$code = self::field_value( $data, [ 'moksafowo_invoice_love_code', '_moksafowo/invoice-love-code', 'moksafowo/invoice-love-code' ] );
			if ( ! preg_match( '/^([xX]\d{2,6}|\d{3,7})$/', $code ) ) {
				$errors->add( 'moksafowo_invoice_love_code', __( '愛心碼格式錯誤（3-7 碼數字）。', 'mo-ectools' ) );
			}
		}

		if ( 'b2c_carrier' === $type ) {
			$carrier     = self::field_value( $data, [ 'moksafowo_invoice_carrier_type', '_moksafowo/invoice-carrier-type', 'moksafowo/invoice-carrier-type' ] );
			$carrier_num = self::field_value(
				$data,
				[
					'moksafowo_invoice_carrier_num', // Classic 單一欄位
					'_moksafowo/invoice-mobile-barcode',
					'moksafowo/invoice-mobile-barcode',
					'_moksafowo/invoice-cert-code',
					'moksafowo/invoice-cert-code',
				]
			);
			if ( 'mobile' === $carrier && ! preg_match( '#^/[0-9A-Z+\-.]{7}$#', $carrier_num ) ) {
				$errors->add( 'moksafowo_invoice_carrier_mobile', __( '手機條碼格式錯誤（/ 開頭加 7 碼大寫英數）。', 'mo-ectools' ) );
			}
			if ( 'cert' === $carrier && ! preg_match( '/^[A-Z]{2}\d{14}$/', $carrier_num ) ) {
				$errors->add( 'moksafowo_invoice_carrier_cert', __( '自然人憑證格式錯誤（2 大寫字母 + 14 碼數字）。', 'mo-ectools' ) );
			}
		}
	}

	/**
	 * 從 cleaned post data / $_POST / Block additional_fields 撈欄位值（唯讀驗證用途）。
	 * 公開供 provider 端的載具真驗 callback（如 ECPay）取同一份取值邏輯。
	 */
	public static function field_value( array $data, array $keys ): string {
		foreach ( $keys as $k ) {
			if ( isset( $data[ $k ] ) && '' !== $data[ $k ] ) {
				return (string) $data[ $k ];
			}
		}
		// 本方法只在 woocommerce_after_checkout_validation 內被呼叫 — Classic 由
		// WC_Checkout::process_checkout() 先驗 checkout nonce，Block 由 Store API 認證層把關，
		// 值一律 sanitize 後回傳。Block additional fields 帶 `additional_fields[moksafowo/invoice-...]`。
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
		// 最後 fallback 到 $_POST top-level（Classic 場景）。
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
		// phpcs:enable
		if ( null !== self::$config ) {
			$order->update_meta_data( Keys::INVOICE_PROVIDER, self::$config->provider_slug );
		}
	}

	public static function register_block_fields(): void {
		if ( null === self::$config || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$prefix        = self::$config->option_prefix;
		$enabled_types = InvoiceChannels::enabled_types( $prefix );
		$allow_b2b     = in_array( 'b2b', $enabled_types, true );
		$allow_donate  = in_array( 'b2c_donate', $enabled_types, true );

		$type_labels  = self::type_labels();
		$type_options = [];
		foreach ( $enabled_types as $t ) {
			$type_options[] = [
				'value' => $t,
				'label' => $type_labels[ $t ],
			];
		}

		$carrier_labels  = self::carrier_labels();
		$carrier_options = [];
		foreach ( InvoiceChannels::enabled_carriers( $prefix ) as $c ) {
			$carrier_options[] = [
				'value' => $c,
				'label' => $carrier_labels[ $c ],
			];
		}

		woocommerce_register_additional_checkout_field(
			[
				'id'       => 'moksafowo/invoice-type',
				'label'    => __( '發票類型', 'mo-ectools' ),
				'location' => 'order',
				'type'     => 'select',
				'options'  => $type_options,
				'required' => true,
			]
		);
		woocommerce_register_additional_checkout_field(
			[
				'id'       => 'moksafowo/invoice-carrier-type',
				'label'    => __( '載具類型', 'mo-ectools' ),
				'location' => 'order',
				'type'     => 'select',
				'options'  => $carrier_options,
				'hidden'   => self::when_type_not( 'b2c_carrier' ),
				'required' => self::when_type( 'b2c_carrier' ),
			]
		);
		// 載具編號拆成兩個宣告式欄位 — schema 無法依別欄位值動態換 label，拆開讓各自 label 固定。
		// hidden 只需測 carrier-type ≠ 對應載具：WC 會清掉被隱藏欄位的值，故發票類型切離個人時
		// carrier-type 值同步清空、本欄也隨之隱藏（實測無殘留）。required 用 type+carrier 雙條件 AND。
		woocommerce_register_additional_checkout_field(
			[
				'id'         => 'moksafowo/invoice-mobile-barcode',
				'label'      => __( '手機條碼（/ 開頭 + 7 碼大寫英數）', 'mo-ectools' ),
				'location'   => 'order',
				'type'       => 'text',
				'hidden'     => self::when_carrier_not( 'mobile' ),
				'required'   => self::when_type_and_carrier( 'b2c_carrier', 'mobile' ),
				'validation' => [
					'type'         => 'string',
					'pattern'      => '^(|/[0-9A-Z+\-.]{7})$',
					'errorMessage' => __( '手機條碼格式錯誤（/ 開頭加 7 碼大寫英數）。', 'mo-ectools' ),
				],
			]
		);
		woocommerce_register_additional_checkout_field(
			[
				'id'         => 'moksafowo/invoice-cert-code',
				'label'      => __( '自然人憑證（2 大寫字母 + 14 碼數字）', 'mo-ectools' ),
				'location'   => 'order',
				'type'       => 'text',
				'hidden'     => self::when_carrier_not( 'cert' ),
				'required'   => self::when_type_and_carrier( 'b2c_carrier', 'cert' ),
				'validation' => [
					'type'         => 'string',
					'pattern'      => '^(|[A-Z]{2}\d{14})$',
					'errorMessage' => __( '自然人憑證格式錯誤（2 大寫字母 + 14 碼數字）。', 'mo-ectools' ),
				],
			]
		);
		if ( $allow_b2b ) {
			woocommerce_register_additional_checkout_field(
				[
					'id'                => 'moksafowo/invoice-buyer-ubn',
					'label'             => __( '統一編號', 'mo-ectools' ),
					'location'          => 'order',
					'type'              => 'text',
					'hidden'            => self::when_type_not( 'b2b' ),
					'required'          => self::when_type( 'b2b' ),
					'validate_callback' => [ self::class, 'validate_ubn_block' ],
				]
			);
			woocommerce_register_additional_checkout_field(
				[
					'id'       => 'moksafowo/invoice-buyer-name',
					'label'    => __( '公司名稱', 'mo-ectools' ),
					'location' => 'order',
					'type'     => 'text',
					'hidden'   => self::when_type_not( 'b2b' ),
					'required' => self::when_type( 'b2b' ),
				]
			);
		}
		if ( $allow_donate ) {
			if ( InvoiceChannels::has_donate_orgs( $prefix ) ) {
				// 捐贈單位（名稱下拉）+ 捐贈碼（唯讀文字，JS 依選到的單位帶入）。
				woocommerce_register_additional_checkout_field(
					[
						'id'       => 'moksafowo/invoice-donate-org',
						'label'    => __( '捐贈單位', 'mo-ectools' ),
						'location' => 'order',
						'type'     => 'select',
						'options'  => InvoiceChannels::donate_block_options( $prefix ),
						'hidden'   => self::when_type_not( 'b2c_donate' ),
						'required' => false,
					]
				);
			}
			woocommerce_register_additional_checkout_field(
				[
					'id'                => 'moksafowo/invoice-love-code',
					'label'             => __( '捐贈碼', 'mo-ectools' ),
					'location'          => 'order',
					'type'              => 'text',
					'hidden'            => self::when_type_not( 'b2c_donate' ),
					'required'          => self::when_type( 'b2c_donate' ),
					'validate_callback' => [ self::class, 'validate_love_code_block' ],
				]
			);
		}
	}

	public static function sync_block_field( $key, $value, $group, $wc_object ): void {
		if ( ! ( $wc_object instanceof \WC_Order ) ) {
			return;
		}
		$map = [
			'moksafowo/invoice-type'           => Keys::INVOICE_TYPE,
			'moksafowo/invoice-carrier-type'   => Keys::INVOICE_CARRIER_TYPE,
			'moksafowo/invoice-mobile-barcode' => Keys::INVOICE_CARRIER_NUM,
			'moksafowo/invoice-cert-code'      => Keys::INVOICE_CARRIER_NUM,
			'moksafowo/invoice-buyer-ubn'      => Keys::INVOICE_BUYER_UBN,
			'moksafowo/invoice-buyer-name'     => Keys::INVOICE_BUYER_NAME,
			'moksafowo/invoice-love-code'      => Keys::INVOICE_LOVE_CODE,
		];
		if ( ! isset( $map[ $key ] ) ) {
			return;
		}
		// 手機條碼 / 自然人憑證共用同一 carrier-num meta — 隱藏欄位送來的空字串不可覆寫已填值。
		if ( '' === (string) $value
			&& in_array( $key, [ 'moksafowo/invoice-mobile-barcode', 'moksafowo/invoice-cert-code' ], true ) ) {
			return;
		}
		$wc_object->update_meta_data( $map[ $key ], (string) $value );
		if ( null !== self::$config ) {
			$wc_object->update_meta_data( Keys::INVOICE_PROVIDER, self::$config->provider_slug );
		}
	}
}
