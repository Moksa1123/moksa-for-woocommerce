<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce 運送區域(Shipping Zones)的個別運送方式查詢與啟用切換。
 *
 * 物流的「子方式」(7-11 取貨 / 黑貓常溫冷凍…)不是 plugin option,而是 WC 運送區域內的
 * 方式實例(instance)。啟用狀態存在 wp_woocommerce_shipping_zone_methods.is_enabled，WC 沒有
 * 提供改它的 API，所以這裡全部唯讀：查詢，以及給商家運送區域頁的連結自己切換。
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
			$name = 0 === $zid ? __( 'Other regions', 'moksa-for-woocommerce' ) : $zone->get_zone_name();
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
	 * 找出運送方式，回傳 WC 運送區域頁的連結，請商家自己按啟用／停用。
	 *
	 * WooCommerce 沒有提供改「啟用狀態」的 API（核心自己是直接寫 woocommerce_shipping_zone_methods
	 * 表），本外掛不直接改那張表，所以 AI 這裡只負責找到、給連結。
	 *
	 * @param mixed $args { method: string, enable?: bool }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function find_method( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$name = is_array( $args ) && isset( $args['method'] ) ? (string) $args['method'] : '';
		$iids = self::resolve( $name );
		if ( empty( $iids ) ) {
			return new \WP_Error( 'moksafowo_ai_no_method', __( 'No matching shipping method was found. List the shipping zones first to confirm the name.', 'moksa-for-woocommerce' ) );
		}
		$all     = self::all_methods();
		$methods = array();
		$lines   = array();
		foreach ( $iids as $iid ) {
			$info = $all[ $iid ] ?? null;
			if ( null === $info ) {
				continue;
			}
			$url       = admin_url( 'admin.php?page=wc-settings&tab=shipping&zone_id=' . (int) $info['zone_id'] );
			$methods[] = array(
				'title'   => $info['title'],
				'zone'    => $info['zone'],
				'enabled' => $info['enabled'],
				'url'     => $url,
			);
			$lines[]   = sprintf(
				/* translators: 1: shipping method title, 2: zone name, 3: enabled/disabled, 4: link */
				__( '%1$s (%2$s, currently %3$s): %4$s', 'moksa-for-woocommerce' ),
				$info['title'],
				$info['zone'],
				$info['enabled'] ? __( 'enabled', 'moksa-for-woocommerce' ) : __( 'disabled', 'moksa-for-woocommerce' ),
				$url
			);
		}

		return array(
			'methods' => $methods,
			'summary' => __( 'Open the shipping zone below and switch the method on or off there:', 'moksa-for-woocommerce' ) . "\n" . implode( "\n", $lines ),
		);
	}
}
