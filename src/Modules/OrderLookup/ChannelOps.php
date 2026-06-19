<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 金流 / 物流 / 電子發票「管道」的啟用狀態查詢與切換。
 *
 * 查詢(list)唯讀直接給;切換(toggle)是破壞性(停用會影響結帳結帳可用方式、
 * 啟用未設憑證的管道會在前台壞掉),故走人工確認關卡。憑證、測試/正式切換不在此處理。
 */
final class ChannelOps {

	const CAP = 'manage_woocommerce';

	/**
	 * 可切換的管道(只含金流 / 物流 / 發票;排除工具類模組)。slug => [label, category]。
	 *
	 * @return array<string, array{label:string, category:string}>
	 */
	private static function channels(): array {
		return array(
			'ecpay'             => array(
				'label'    => __( '綠界金流', 'mo-ectools' ),
				'category' => 'payment',
			),
			'newebpay'          => array(
				'label'    => __( '藍新金流', 'mo-ectools' ),
				'category' => 'payment',
			),
			'smilepay'          => array(
				'label'    => __( '速買配金流', 'mo-ectools' ),
				'category' => 'payment',
			),
			'linepay'           => array(
				'label'    => __( 'LINE Pay', 'mo-ectools' ),
				'category' => 'payment',
			),
			'payuni'            => array(
				'label'    => __( '統一金流 PAYUNi', 'mo-ectools' ),
				'category' => 'payment',
			),
			'paynow'            => array(
				'label'    => __( 'PayNow 立吉富', 'mo-ectools' ),
				'category' => 'payment',
			),
			'pchomepay'         => array(
				'label'    => __( 'PChomePay', 'mo-ectools' ),
				'category' => 'payment',
			),
			'tappay'            => array(
				'label'    => __( 'TapPay', 'mo-ectools' ),
				'category' => 'payment',
			),
			'shopline_payments' => array(
				'label'    => __( 'Shopline Payments', 'mo-ectools' ),
				'category' => 'payment',
			),
			'ecpay_shipping'    => array(
				'label'    => __( '綠界物流', 'mo-ectools' ),
				'category' => 'shipping',
			),
			'newebpay_shipping' => array(
				'label'    => __( '藍新物流', 'mo-ectools' ),
				'category' => 'shipping',
			),
			'payuni_shipping'   => array(
				'label'    => __( 'PAYUNi 物流', 'mo-ectools' ),
				'category' => 'shipping',
			),
			'smilepay_shipping' => array(
				'label'    => __( '速買配物流', 'mo-ectools' ),
				'category' => 'shipping',
			),
			'ecpay_invoice'     => array(
				'label'    => __( '綠界電子發票', 'mo-ectools' ),
				'category' => 'invoice',
			),
			'ezpay_invoice'     => array(
				'label'    => __( 'ezPay 電子發票', 'mo-ectools' ),
				'category' => 'invoice',
			),
			'paynow_invoice'    => array(
				'label'    => __( 'PayNow 電子發票', 'mo-ectools' ),
				'category' => 'invoice',
			),
			'amego_invoice'     => array(
				'label'    => __( 'Amego 電子發票', 'mo-ectools' ),
				'category' => 'invoice',
			),
			'smilepay_invoice'  => array(
				'label'    => __( '速買配電子發票', 'mo-ectools' ),
				'category' => 'invoice',
			),
		);
	}

	private static function is_on( string $slug ): bool {
		return 'yes' === get_option( 'moksafowo_' . $slug . '_enabled', 'no' );
	}

	/**
	 * 由 slug 或管道名稱(模糊)解析出 slug,讓 AI 不必先查清單。
	 *
	 * @param string $input slug 或名稱。
	 * @return string slug,解析不到回空字串。
	 */
	private static function resolve_slug( string $input ): string {
		$input    = trim( $input );
		$channels = self::channels();
		$key      = sanitize_key( $input );
		if ( isset( $channels[ $key ] ) ) {
			return $key;
		}
		foreach ( $channels as $slug => $info ) {
			if ( $info['label'] === $input ) {
				return $slug;
			}
		}
		foreach ( $channels as $slug => $info ) {
			if ( '' !== $input && ( false !== mb_strpos( $info['label'], $input ) || false !== mb_strpos( $input, $info['label'] ) ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * 唯讀:列出管道與啟用狀態。category 用 all 取全部,或 payment / shipping / invoice 篩選。
	 *
	 * @param mixed $input { category: string }。
	 * @return array<string,mixed>
	 */
	public static function list_channels( $input ): array {
		if ( ! current_user_can( self::CAP ) ) {
			return array( 'channels' => array() );
		}
		$cat = is_array( $input ) && isset( $input['category'] ) ? sanitize_key( (string) $input['category'] ) : 'all';
		if ( ! in_array( $cat, array( 'payment', 'shipping', 'invoice' ), true ) ) {
			$cat = 'all';
		}
		$rows = array();
		foreach ( self::channels() as $slug => $info ) {
			if ( 'all' !== $cat && $info['category'] !== $cat ) {
				continue;
			}
			$rows[] = array(
				'slug'     => $slug,
				'name'     => $info['label'],
				'category' => $info['category'],
				'enabled'  => self::is_on( $slug ),
			);
		}
		return array( 'channels' => $rows );
	}

	/**
	 * @param mixed $args { channel: string(slug), enable: bool }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function toggle_prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$raw      = is_array( $args ) && isset( $args['channel'] ) ? (string) $args['channel'] : '';
		$slug     = self::resolve_slug( $raw );
		$channels = self::channels();
		if ( ! isset( $channels[ $slug ] ) ) {
			return new \WP_Error( 'mo_ai_bad_channel', __( '找不到此管道。', 'mo-ectools' ) );
		}
		$enable  = self::truthy( is_array( $args ) ? ( $args['enable'] ?? null ) : null );
		$current = self::is_on( $slug );
		if ( $enable === $current ) {
			return new \WP_Error(
				'mo_ai_noop',
				sprintf(
					/* translators: 1: channel label, 2: state */
					__( '「%1$s」目前已是%2$s,無需變更。', 'mo-ectools' ),
					$channels[ $slug ]['label'],
					$enable ? __( '啟用', 'mo-ectools' ) : __( '停用', 'mo-ectools' )
				)
			);
		}

		return array(
			'slug'    => $slug,
			'enable'  => $enable,
			'summary' => sprintf(
				/* translators: 1: action, 2: channel label */
				__( '%1$s「%2$s」管道。', 'mo-ectools' ),
				$enable ? __( '啟用', 'mo-ectools' ) : __( '停用', 'mo-ectools' ),
				$channels[ $slug ]['label']
			),
		);
	}

	/**
	 * @param array<string,mixed> $params toggle_prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function toggle_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'mo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$slug     = (string) ( $params['slug'] ?? '' );
		$channels = self::channels();
		if ( ! isset( $channels[ $slug ] ) ) {
			return new \WP_Error( 'mo_ai_bad_channel', __( '找不到此管道。', 'mo-ectools' ) );
		}
		$enable = ! empty( $params['enable'] );
		update_option( 'moksafowo_' . $slug . '_enabled', $enable ? 'yes' : 'no' );

		return sprintf(
			/* translators: 1: channel label, 2: state */
			__( '✅ 已%2$s「%1$s」。設定即時生效;部分前台變更可能需清快取。', 'mo-ectools' ),
			$channels[ $slug ]['label'],
			$enable ? __( '啟用', 'mo-ectools' ) : __( '停用', 'mo-ectools' )
		);
	}

	private static function truthy( $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		$s = strtolower( trim( (string) $v ) );
		return in_array( $s, array( '1', 'true', 'yes', 'on', 'enable', 'enabled', '啟用', '開' ), true );
	}
}
