<?php

declare( strict_types=1 );

namespace Moksafowo\Settings;

use Moksafowo\Modules\CustomerService\Schema;
use Moksafowo\Modules\OrderLookup\Index\Backfill;
use Moksafowo\Modules\OrderLookup\Index\Table;
use Moksafowo\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * 記下本外掛寫過的 option 名稱，解除安裝時照表 delete_option()。
 *
 * WordPress 沒有「依前綴列出 option」的 API，所以改用登記表：
 * 核心每新增一個 option 都會觸發 added_option，名稱屬於本外掛就記進登記表。
 * 已經在用的站升級時補登一次：自動載入的從 wp_load_alloptions() 取，不自動載入的照下面
 * known_unautoloaded() 逐一確認存不存在。
 *
 * 不記：transient（會自己過期）、鎖（用完就刪）、登記表本身。
 */
final class OptionRegistry {

	public const OPTION       = 'moksafowo_option_registry';
	private const BACKFILLED  = 'moksafowo_option_registry_backfilled';
	private const PREFIXES    = [ 'moksafowo_', 'Moksafowo_', 'Mo_LinePay_', 'woocommerce_moksafowo_', 'woocommerce_moksafowo-' ];
	private const SKIP_PREFIX = [ 'moksafowo_lock_', 'moksafowo_cs_lock_', 'moksafowo_cs_rl_lock_' ];

	public static function boot(): void {
		add_action( 'added_option', [ self::class, 'track' ] );
		add_action( 'init', [ self::class, 'maybe_backfill' ], 20 );
	}

	/** @param mixed $name 新增的 option 名稱。 */
	public static function track( $name ): void {
		if ( is_string( $name ) && self::ours( $name ) ) {
			self::add( [ $name ] );
		}
	}

	public static function ours( string $name ): bool {
		if ( self::OPTION === $name || str_ends_with( $name, '.lock' ) || str_contains( $name, '_transient_' ) ) {
			return false;
		}
		foreach ( self::SKIP_PREFIX as $skip ) {
			if ( str_starts_with( $name, $skip ) ) {
				return false;
			}
		}
		foreach ( self::PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] */
	public static function names(): array {
		$names = get_option( self::OPTION, [] );
		return is_array( $names ) ? array_values( array_filter( $names, 'is_string' ) ) : [];
	}

	public static function maybe_backfill(): void {
		if ( 'yes' === \Moksafowo\Support\FreshOption::get( self::BACKFILLED ) ) {
			return;
		}
		$found = array_filter( array_keys( wp_load_alloptions() ), static fn( $n ): bool => is_string( $n ) && self::ours( $n ) );
		foreach ( self::known_unautoloaded() as $name ) {
			if ( null !== get_option( $name, null ) ) {
				$found[] = $name;
			}
		}
		self::add( $found );
		update_option( self::BACKFILLED, 'yes', false );
	}

	/**
	 * 本外掛以 autoload=false 寫入、wp_load_alloptions() 看不到的 option。
	 * 新增這種 option 不用改這裡：之後寫入都會被 track() 記下；這份只給升級前就存在的站補登用。
	 *
	 * @return string[]
	 */
	private static function known_unautoloaded(): array {
		$names = [
			Schema::DB_VERSION_OPTION,
			Table::DB_VERSION_OPTION,
			Backfill::PROGRESS_OPTION,
			'moksafowo_option_keys_migrated_189',
			'moksafowo_ai_master_migrated',
			'moksafowo_tw_address_postcode_autofill',
			'moksafowo_ai_assistant_enabled',
			'moksafowo_customer_service_enabled',
			// 舊版寫過、現在的程式已不再用的。
			'moksafowo_payuni_credentials_migrated_at',
		];
		foreach ( [ 'shipped', 'cvs_arrived', 'store_closed' ] as $status ) {
			$names[] = 'moksafowo_shipping_status_moksa_' . $status . '_enabled';
		}
		foreach ( array_keys( Plugin::instance()->modules()->all() ) as $slug ) {
			$names[] = 'moksafowo_' . $slug . '_enabled_methods';
			$names[] = 'moksafowo_' . $slug . '_methods_seeded';
		}
		return $names;
	}

	/** @param string[] $names */
	private static function add( array $names ): void {
		$current = self::names();
		$merged  = array_values( array_unique( array_merge( $current, $names ) ) );
		if ( count( $merged ) !== count( $current ) ) {
			update_option( self::OPTION, $merged, false );
		}
	}

	private function __construct() {}
}
