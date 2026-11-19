<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Address;

defined( 'ABSPATH' ) || exit;

/**
 * 只賣／只寄一國的商店，新訪客的國家預設成那一國。
 *
 * WooCommerce「預設顧客位置」設成「不預設」時，新訪客國家是空的；區塊結帳沒國家就不算運費，
 * 整塊運送選項（超商取貨、選門市）都不出現。只在預設位置的國家是空的時候補，
 * 已存地址的會員（WC 只在顧客國家空白時才套預設）、商店基地址、地理定位結果都不動。
 */
final class SingleCountryDefault {

	public const OPTION = 'moksafowo_single_country_default';

	public static function init(): void {
		if ( 'yes' !== get_option( self::OPTION, 'yes' ) ) {
			return;
		}
		add_filter( 'woocommerce_customer_default_location_array', [ __CLASS__, 'fill_country' ], 20 );
	}

	/**
	 * @param mixed $location 預設位置。
	 * @return mixed
	 */
	public static function fill_country( $location ) {
		if ( ! is_array( $location ) || ! empty( $location['country'] ) ) {
			return $location;
		}
		$country = self::only_country();
		if ( '' !== $country ) {
			$location['country'] = $country;
			$location['state']   = '';
		}
		return $location;
	}

	/** 銷售國家只有一國就用它；否則運送國家只有一國、且也在銷售國家內就用它；都不是回空字串。 */
	public static function only_country(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}
		$allowed = array_keys( WC()->countries->get_allowed_countries() );
		if ( 1 === count( $allowed ) ) {
			return (string) $allowed[0];
		}
		if ( ! function_exists( 'wc_shipping_enabled' ) || ! wc_shipping_enabled() ) {
			return '';
		}
		$ship = array_keys( WC()->countries->get_shipping_countries() );
		if ( 1 === count( $ship ) && in_array( $ship[0], $allowed, true ) ) {
			return (string) $ship[0];
		}
		return '';
	}
}
