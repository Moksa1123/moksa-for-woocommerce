<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 金流「個別付款方式」的查詢與啟用切換(比 ChannelOps 整模組更細一層)。
 *
 * 各金流把啟用的付款方式存在 multiselect option moksafowo_<provider>_enabled_methods,
 * 方式 id↔中文名定義在各 Settings\SettingsTab::get_settings() 的該欄位 options。
 * 這裡用反射動態讀那份 options(不寫死,維護免兩處改)。切換是破壞性,走人工確認關卡。
 *
 * 注意:display_mode = single(合併顯示)時個別方式開關不生效,prepare 會提醒。
 */
final class PaymentMethodOps {

	const CAP = 'manage_woocommerce';

	private static function providers(): array {
		return array(
			'ecpay'             => __( '綠界金流', 'mo-ectools' ),
			'newebpay'          => __( '藍新金流', 'mo-ectools' ),
			'payuni'            => __( 'PAYUNi', 'mo-ectools' ),
			'smilepay'          => __( '速買配金流', 'mo-ectools' ),
			'paynow'            => __( 'PayNow', 'mo-ectools' ),
			'pchomepay'         => __( 'PChomePay', 'mo-ectools' ),
			'shopline_payments' => __( 'Shopline Payments', 'mo-ectools' ),
		);
	}

	private static function tab_class( string $provider ): string {
		$map = array(
			'ecpay'             => 'Moksafowo\\Modules\\Ecpay\\Settings\\SettingsTab',
			'newebpay'          => 'Moksafowo\\Modules\\Newebpay\\Settings\\SettingsTab',
			'payuni'            => 'Moksafowo\\Modules\\Payuni\\Settings\\SettingsTab',
			'smilepay'          => 'Moksafowo\\Modules\\Smilepay\\Settings\\SettingsTab',
			'paynow'            => 'Moksafowo\\Modules\\Paynow\\Settings\\SettingsTab',
			'pchomepay'         => 'Moksafowo\\Modules\\Pchomepay\\Settings\\SettingsTab',
			'shopline_payments' => 'Moksafowo\\Modules\\ShoplinePayments\\Settings\\SettingsTab',
		);
		return $map[ $provider ] ?? '';
	}

	private static function option_key( string $provider ): string {
		if ( 'shopline_payments' === $provider ) {
			return 'moksafowo_shopline_payments_payment_methods';
		}
		return 'moksafowo_' . $provider . '_enabled_methods';
	}

	/**
	 * 方式 id => 中文名(從該 provider 的 SettingsTab 動態讀)。
	 *
	 * @return array<string,string>
	 */
	private static function method_map( string $provider ): array {
		$class = self::tab_class( $provider );
		if ( '' === $class || ! class_exists( $class ) ) {
			return array();
		}
		try {
			$ref = new \ReflectionClass( $class );
			$obj = $ref->newInstanceWithoutConstructor();
		} catch ( \Throwable $e ) {
			return array();
		}

		$key = self::option_key( $provider );
		foreach ( array( 'get_settings', 'get_settings_for_payment_section' ) as $method ) {
			if ( ! method_exists( $obj, $method ) ) {
				continue;
			}
			try {
				$settings = $obj->{$method}();
			} catch ( \Throwable $e ) {
				continue;
			}
			foreach ( (array) $settings as $field ) {
				if ( is_array( $field ) && ( $field['id'] ?? '' ) === $key && ! empty( $field['options'] ) ) {
					return (array) $field['options'];
				}
			}
		}
		return array();
	}

	private static function enabled_ids( string $provider ): array {
		$val = get_option( self::option_key( $provider ), array() );
		return is_array( $val ) ? array_values( array_filter( array_map( 'strval', $val ) ) ) : array();
	}

	private static function norm( string $s ): string {
		$s = mb_strtolower( trim( $s ) );
		return str_replace( array( ' ', '　', '（', '）', '(', ')', '-', '_' ), '', $s );
	}

	/**
	 * 已知「單一付款方式」的金流(本外掛只實作一種方式,無細分可開關)。
	 * slug => [ label, method(該唯一方式中文名) ]。
	 *
	 * @return array<string, array{label:string, method:string}>
	 */
	private static function single_method_gateways(): array {
		return array(
			'tappay'  => array(
				'label'  => __( 'TapPay', 'mo-ectools' ),
				'method' => __( '信用卡', 'mo-ectools' ),
			),
			'linepay' => array(
				'label'  => __( 'LINE Pay', 'mo-ectools' ),
				'method' => __( 'LINE Pay', 'mo-ectools' ),
			),
		);
	}

	/**
	 * 精確 → 前綴比對(刻意不用任意子字串,否則「linepay」會誤命中
	 * 「shoplinepayments」、「tappay」含「pa」會誤命中其他家)。
	 *
	 * @param string               $input 使用者輸入。
	 * @param array<string,string> $map   slug => label。
	 * @return string 命中的 slug,否則空。
	 */
	private static function match_gateway( string $input, array $map ): string {
		$ni = self::norm( trim( $input ) );
		if ( '' === $ni ) {
			return '';
		}
		foreach ( $map as $slug => $label ) {
			if ( self::norm( $slug ) === $ni || self::norm( $label ) === $ni ) {
				return $slug;
			}
		}
		foreach ( $map as $slug => $label ) {
			$ns = self::norm( $slug );
			$nl = self::norm( $label );
			if ( 0 === mb_strpos( $ns, $ni ) || 0 === mb_strpos( $nl, $ni ) || 0 === mb_strpos( $ni, $ns ) || 0 === mb_strpos( $ni, $nl ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * 輸入是否為已知單一付款方式金流。
	 *
	 * @return array{slug:string, label:string, method:string}|null
	 */
	private static function resolve_single( string $input ): ?array {
		$gw     = self::single_method_gateways();
		$labels = array_map( static fn( $v ) => $v['label'], $gw );
		$slug   = self::match_gateway( $input, $labels );
		if ( '' === $slug ) {
			return null;
		}
		return array(
			'slug'   => $slug,
			'label'  => $gw[ $slug ]['label'],
			'method' => $gw[ $slug ]['method'],
		);
	}

	/** 支援細分付款方式的金流清單(錯誤訊息用,動態避免漏列)。 */
	private static function supported_list(): string {
		return implode( '、', array_values( self::providers() ) );
	}

	private static function resolve_provider( string $input ): string {
		$map = self::providers();
		$key = sanitize_key( trim( $input ) );
		if ( isset( $map[ $key ] ) ) {
			return $key;
		}
		return self::match_gateway( $input, $map );
	}

	/**
	 * 把使用者給的方式名稱陣列解析成 [ 命中 id=>label, 未命中名稱[] ]。
	 *
	 * @param string[]             $names 使用者給的名稱。
	 * @param array<string,string> $map   id=>label。
	 * @return array{0:array<string,string>, 1:string[]}
	 */
	private static function resolve_methods( array $names, array $map ): array {
		$matched   = array();
		$unmatched = array();
		foreach ( $names as $name ) {
			$n = self::norm( (string) $name );
			if ( '' === $n ) {
				continue;
			}
			$hit = '';
			foreach ( $map as $id => $label ) {
				$nl  = self::norm( $label );
				$nid = self::norm( $id );
				if ( $nl === $n || false !== mb_strpos( $nl, $n ) || false !== mb_strpos( $n, $nl ) || false !== mb_strpos( $nid, $n ) ) {
					$hit = $id;
					break;
				}
			}
			if ( '' !== $hit ) {
				$matched[ $hit ] = $map[ $hit ];
			} else {
				$unmatched[] = (string) $name;
			}
		}
		return array( $matched, $unmatched );
	}

	private static function names_arg( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[,，、]+/u', $raw ) ?: array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param mixed $input { provider: string }。
	 * @return array<string,mixed>
	 */
	public static function list_methods( $input ): array {
		if ( ! current_user_can( self::CAP ) ) {
			return array( 'methods' => array() );
		}
		$raw      = is_array( $input ) && isset( $input['provider'] ) ? (string) $input['provider'] : '';
		$provider = self::resolve_provider( $raw );
		if ( '' === $provider ) {
			$single = self::resolve_single( $raw );
			if ( null !== $single ) {
				$on = 'yes' === get_option( 'moksafowo_' . $single['slug'] . '_enabled', 'no' );
				return array(
					'provider'     => $single['slug'],
					'display_mode' => 'single',
					'methods'      => array(
						array(
							'id'      => $single['slug'],
							'name'    => $single['method'],
							'enabled' => $on,
						),
					),
					/* translators: 1: gateway, 2: the only method */
					'note'         => sprintf( __( '%1$s 在本外掛只有「%2$s」一種付款方式,沒有其他可細分開關的方式。', 'mo-ectools' ), $single['label'], $single['method'] ),
				);
			}
			return array(
				'methods' => array(),
				'message' => __( '找不到此金流。', 'mo-ectools' ),
			);
		}
		$map     = self::method_map( $provider );
		$enabled = self::enabled_ids( $provider );
		$rows    = array();
		foreach ( $map as $id => $label ) {
			$rows[] = array(
				'id'      => $id,
				'name'    => $label,
				'enabled' => in_array( $id, $enabled, true ),
			);
		}
		return array(
			'provider'     => $provider,
			'display_mode' => (string) get_option( 'moksafowo_' . $provider . '_display_mode', 'multi' ),
			'methods'      => $rows,
		);
	}

	/**
	 * @param mixed $args { provider: string, methods: array|string, enable: bool }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function toggle_prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$raw      = is_array( $args ) && isset( $args['provider'] ) ? (string) $args['provider'] : '';
		$provider = self::resolve_provider( $raw );
		if ( '' === $provider ) {
			$single = self::resolve_single( $raw );
			if ( null !== $single ) {
				/* translators: 1: gateway, 2: the only method */
				return new \WP_Error( 'moksafowo_ai_single_method', sprintf( __( '%1$s 在本外掛只有「%2$s」一種付款方式,沒有可細分開關的方式(整體啟用 / 停用請用管道開關)。', 'mo-ectools' ), $single['label'], $single['method'] ) );
			}
			/* translators: %s: supported gateway list */
			return new \WP_Error( 'moksafowo_ai_bad_provider', sprintf( __( '找不到此金流(支援細分方式:%s)。', 'mo-ectools' ), self::supported_list() ) );
		}
		$map = self::method_map( $provider );
		if ( empty( $map ) ) {
			return new \WP_Error( 'moksafowo_ai_no_methods', __( '此金流不支援個別付款方式設定。', 'mo-ectools' ) );
		}

		[ $matched, $unmatched ] = self::resolve_methods( self::names_arg( is_array( $args ) ? ( $args['methods'] ?? array() ) : array() ), $map );
		if ( empty( $matched ) ) {
			return new \WP_Error( 'moksafowo_ai_no_match', __( '找不到對應的付款方式,請確認名稱。', 'mo-ectools' ) );
		}
		$enable = self::truthy( is_array( $args ) ? ( $args['enable'] ?? true ) : true );

		$plabel  = self::providers()[ $provider ];
		$labels  = implode( '、', array_values( $matched ) );
		$summary = sprintf(
			/* translators: 1: enable/disable, 2: provider, 3: method labels */
			__( '%1$s %2$s 的付款方式:%3$s。', 'mo-ectools' ),
			$enable ? __( '啟用', 'mo-ectools' ) : __( '停用', 'mo-ectools' ),
			$plabel,
			$labels
		);
		if ( ! empty( $unmatched ) ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: unmatched names */
				__( '(無法對應並略過:%s)', 'mo-ectools' ),
				implode( '、', $unmatched )
			);
		}
		if ( 'single' === get_option( 'moksafowo_' . $provider . '_display_mode', 'multi' ) ) {
			$summary .= ' ' . __( '⚠️ 此金流目前是「合併顯示」模式,個別方式開關不會生效;要分開顯示才有作用。', 'mo-ectools' );
		}

		return array(
			'provider' => $provider,
			'ids'      => array_keys( $matched ),
			'enable'   => $enable,
			'summary'  => $summary,
		);
	}

	/**
	 * @param array<string,mixed> $params toggle_prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function toggle_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$provider = (string) ( $params['provider'] ?? '' );
		$ids      = is_array( $params['ids'] ?? null ) ? array_map( 'strval', $params['ids'] ) : array();
		$enable   = ! empty( $params['enable'] );
		$map      = self::method_map( $provider );
		if ( '' === $provider || empty( $map ) || empty( $ids ) ) {
			return new \WP_Error( 'moksafowo_ai_bad_input', __( '資料不完整,無法變更。', 'mo-ectools' ) );
		}

		$current = self::enabled_ids( $provider );
		if ( $enable ) {
			$new = array_values( array_unique( array_merge( $current, $ids ) ) );
		} else {
			$new = array_values( array_diff( $current, $ids ) );
		}
		update_option( self::option_key( $provider ), $new );

		$labels = array();
		foreach ( $ids as $id ) {
			$labels[] = $map[ $id ] ?? $id;
		}
		return sprintf(
			/* translators: 1: enable/disable, 2: provider, 3: method labels */
			__( '✅ 已%1$s %2$s 的付款方式:%3$s。', 'mo-ectools' ),
			$enable ? __( '啟用', 'mo-ectools' ) : __( '停用', 'mo-ectools' ),
			self::providers()[ $provider ] ?? $provider,
			implode( '、', $labels )
		);
	}

	private static function truthy( $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		$s = mb_strtolower( trim( (string) $v ) );
		return ! in_array( $s, array( '0', 'false', 'no', 'off', 'disable', 'disabled', '停用', '關', '關閉' ), true );
	}
}
