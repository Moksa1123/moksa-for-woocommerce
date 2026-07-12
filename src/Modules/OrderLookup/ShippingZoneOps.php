<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce 運送區域(Shipping Zones)的個別運送方式查詢與啟用切換。
 *
 * 物流的「子方式」(7-11 取貨 / 黑貓常溫冷凍…)不是 plugin option,而是 WC 運送區域內的
 * 方式實例(instance)。啟用狀態存在 wp_woocommerce_shipping_zone_methods.is_enabled,
 * 無 ORM setter,WC 核心本身也是 $wpdb 改這張專用表 + bust shipping cache,故沿用。
 * 查詢唯讀;切換是破壞性(影響前台結帳可選的運送方式)→ 走人工確認關卡。
 */
final class ShippingZoneOps {

	const CAP = 'manage_woocommerce';

	/**
	 * 全部運送方式實例。instance_id => [ title, method_id, zone_id, zone, enabled ]。
	 *
	 * @return array<int, array{title:string, method_id:string, zone_id:int, zone:string, enabled:bool}>
	 */
	private static function all_methods(): array {
		$out = array();
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return $out;
		}
		$zone_ids = array( 0 );
		foreach ( \WC_Shipping_Zones::get_zones() as $z ) {
			$zone_ids[] = (int) $z['id'];
		}
		foreach ( array_unique( $zone_ids ) as $zid ) {
			$zone = new \WC_Shipping_Zone( $zid );
			$name = 0 === $zid ? __( '其他地區', 'mo-ectools' ) : $zone->get_zone_name();
			foreach ( $zone->get_shipping_methods( false ) as $m ) {
				$out[ (int) $m->get_instance_id() ] = array(
					'title'     => (string) $m->get_title(),
					'method_id' => (string) $m->id,
					'zone_id'   => $zid,
					'zone'      => (string) $name,
					'enabled'   => (bool) $m->is_enabled(),
				);
			}
		}
		return $out;
	}

	private static function norm( string $s ): string {
		$s = mb_strtolower( trim( $s ) );
		return str_replace( array( ' ', '　', '（', '）', '(', ')', '-', '—', '/' ), '', $s );
	}

	/**
	 * 由名稱(模糊)解析出符合的 instance_id。
	 *
	 * @param string $name 運送方式名稱。
	 * @return int[] 命中的 instance_id。
	 */
	private static function resolve( string $name ): array {
		$n = self::norm( $name );
		if ( '' === $n ) {
			return array();
		}
		$hits = array();
		foreach ( self::all_methods() as $iid => $info ) {
			$t = self::norm( $info['title'] );
			if ( $t === $n || false !== mb_strpos( $t, $n ) || false !== mb_strpos( $n, $t ) ) {
				$hits[] = $iid;
			}
		}
		return $hits;
	}

	/**
	 * @param mixed $input 未使用。
	 * @return array<string,mixed>
	 */
	public static function list_zones( $input ): array {
		if ( ! current_user_can( self::CAP ) ) {
			return array( 'zones' => array() );
		}
		$by_zone = array();
		foreach ( self::all_methods() as $iid => $info ) {
			$by_zone[ $info['zone'] ][] = array(
				'instance_id' => $iid,
				'title'       => $info['title'],
				'method_id'   => $info['method_id'],
				'enabled'     => $info['enabled'],
			);
		}
		$zones = array();
		foreach ( $by_zone as $zname => $methods ) {
			$zones[] = array(
				'zone'    => $zname,
				'methods' => $methods,
			);
		}
		return array( 'zones' => $zones );
	}

	private static function truthy( $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		$s = mb_strtolower( trim( (string) $v ) );
		return ! in_array( $s, array( '0', 'false', 'no', 'off', 'disable', 'disabled', '停用', '關', '關閉' ), true );
	}

	/**
	 * @param mixed $args { method: string, enable: bool }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function toggle_prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$name = is_array( $args ) && isset( $args['method'] ) ? (string) $args['method'] : '';
		$iids = self::resolve( $name );
		if ( empty( $iids ) ) {
			return new \WP_Error( 'moksafowo_ai_no_method', __( '找不到對應的運送方式(可先用列出運送區域確認名稱)。', 'mo-ectools' ) );
		}
		$enable = self::truthy( is_array( $args ) ? ( $args['enable'] ?? true ) : true );

		$all    = self::all_methods();
		$titles = array();
		foreach ( $iids as $iid ) {
			$titles[] = ( $all[ $iid ]['title'] ?? '' ) . '(' . ( $all[ $iid ]['zone'] ?? '' ) . ')';
		}

		return array(
			'instance_ids' => $iids,
			'enable'       => $enable,
			'summary'      => sprintf(
				/* translators: 1: enable/disable, 2: method titles */
				__( '%1$s 運送方式:%2$s。', 'mo-ectools' ),
				$enable ? __( '啟用', 'mo-ectools' ) : __( '停用', 'mo-ectools' ),
				implode( '、', $titles )
			),
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
		$iids   = is_array( $params['instance_ids'] ?? null ) ? array_map( 'absint', $params['instance_ids'] ) : array();
		$enable = ! empty( $params['enable'] );
		$iids   = array_values( array_filter( $iids ) );
		if ( empty( $iids ) ) {
			return new \WP_Error( 'moksafowo_ai_bad_input', __( '沒有可變更的運送方式。', 'mo-ectools' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
		$all   = self::all_methods();
		$done  = array();
		foreach ( $iids as $iid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WC 專用設定表,核心亦以 $wpdb 改 is_enabled;無 ORM setter。
			$wpdb->update( $table, array( 'is_enabled' => $enable ? 1 : 0 ), array( 'instance_id' => $iid ) );
			$done[] = $all[ $iid ]['title'] ?? ( '#' . $iid );
		}
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}

		return sprintf(
			/* translators: 1: enable/disable, 2: method titles */
			__( '✅ 已%1$s運送方式:%2$s。', 'mo-ectools' ),
			$enable ? __( '啟用', 'mo-ectools' ) : __( '停用', 'mo-ectools' ),
			implode( '、', $done )
		);
	}
}
