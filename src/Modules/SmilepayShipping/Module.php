<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\SmilepayShipping;

use Moksafowo\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'smilepay_shipping';
	}

	public function label(): string {
		return __( 'SmilePay shipping — 7-ELEVEN and FamilyMart pickup, plus T-Cat ambient, chilled and frozen', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'shipping';
	}

	public function name(): string {
		return __( 'SmilePay shipping', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( '7-ELEVEN and FamilyMart pickup, plus T-Cat ambient, chilled and frozen', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( '7-ELEVEN pickup', 'moksa-for-woocommerce' ),
			__( 'FamilyMart pickup', 'moksa-for-woocommerce' ),
			__( 'T-Cat ambient', 'moksa-for-woocommerce' ),
			__( 'T-Cat chilled', 'moksa-for-woocommerce' ),
			__( 'T-Cat frozen', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'smilepay-shipping';
	}

	public function boot(): void {
		add_filter( 'woocommerce_shipping_methods', [ __CLASS__, 'register_methods' ] );

		add_action( 'woocommerce_api_moksafowo_smilepay_shipping_status', [ Api\IpnHandler::class, 'handle' ] );
		add_filter( 'woocommerce_default_address_fields', [ __CLASS__, 'relax_cvs_required_fields' ] );
		add_filter( 'moksafowo_shipping_batch_print_providers', [ __CLASS__, 'register_batch_print' ] );
		add_filter( \Moksafowo\Modules\Shipping\Admin\CvsStoreEditor::PROVIDERS_FILTER, [ __CLASS__, 'register_cvs_store_editor' ] );
		Frontend\StoreSelector::init();
		Frontend\CustomerOrderView::init();
		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
			Operations\PrintProxy::init();
		}
		Emails\EmailTrackingProvider::init();
	}

	/**
	 * @param array<string,array<string,mixed>> $providers Registered providers.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_cvs_store_editor( array $providers ): array {
		$providers['smilepay'] = [
			'label'            => __( 'SmilePay convenience store pickup', 'moksa-for-woocommerce' ),
			'methods'          => [
				'moksafowo_smilepay_shipping_cvs_711'  => 'moksafowo_smilepay_shipping_cvs_711',
				'moksafowo_smilepay_shipping_cvs_fami' => 'moksafowo_smilepay_shipping_cvs_fami',
			],
			// 速買配自己一組鍵，沒併進共用鍵 —— 建單與顯示都讀這三個
			'meta'             => [
				'id'      => \Moksafowo\Order\Meta\Keys::SMILEPAY_SHIPPING_STORE_ID,
				'name'    => \Moksafowo\Order\Meta\Keys::SMILEPAY_SHIPPING_STORE_NAME,
				'address' => \Moksafowo\Order\Meta\Keys::SMILEPAY_SHIPPING_STORE_ADDR,
			],
			'open_map'         => [ Frontend\StoreSelector::class, 'admin_map_payload' ],
			'session_store_id' => [ Frontend\StoreSelector::class, 'session_store_id' ],
		];
		return $providers;
	}

	public static function method_map(): array {
		return [
			'moksafowo_smilepay_shipping_cvs_711'      => Methods\Cvs711::class,
			'moksafowo_smilepay_shipping_cvs_fami'     => Methods\CvsFami::class,
			'moksafowo_smilepay_shipping_tcat'         => Methods\Tcat::class,
			'moksafowo_smilepay_shipping_tcat_normal'  => Methods\TcatNormal::class,
			'moksafowo_smilepay_shipping_tcat_refrige' => Methods\TcatRefrige::class,
			'moksafowo_smilepay_shipping_tcat_freeze'  => Methods\TcatFreeze::class,
		];
	}

	public static function register_methods( array $methods ): array {
		foreach ( self::method_map() as $id => $class ) {
			$methods[ $id ] = $class;
		}
		return $methods;
	}

	public static function relax_cvs_required_fields( array $fields ): array {
		// 暫不修改 — 留待結帳頁 hook 動態判斷 chosen shipping
		return $fields;
	}

	public static function register_batch_print( array $providers ): array {
		$counter = static fn( \WC_Order $o ): int => Operations\BatchPrint::record_count( $o );

		$cvs_titles = [
			'moksafowo_smilepay_shipping_cvs_711'  => __( 'SmilePay 7-ELEVEN pickup', 'moksa-for-woocommerce' ),
			'moksafowo_smilepay_shipping_cvs_fami' => __( 'SmilePay FamilyMart pickup', 'moksa-for-woocommerce' ),
		];
		// AI / MCP 用：列出與刪除物流單記錄（走 ShipmentRecords 門面）
		$lister  = static function ( \WC_Order $o ): array {
			$out = [];
			foreach ( Operations\CreateOrder::get_records( $o ) as $r ) {
				$out[] = [
					'id'         => (string) ( $r['smseid'] ?? '' ),
					'tracking'   => (string) ( $r['track_num'] ?? '' ),
					'created_at' => (string) ( $r['created_at'] ?? '' ),
					'temp'       => (string) ( $r['temp'] ?? '' ),
					'note'       => (string) ( $r['rtn_msg'] ?? '' ),
				];
			}
			return $out;
		};
		$deleter = static fn( \WC_Order $o, string $id ): bool => Operations\CreateOrder::delete_record( $o, $id );

		$providers['smilepay-cvs'] = [
			'label'          => __( 'SmilePay convenience store labels', 'moksa-for-woocommerce' ),
			'category'       => 'cvs',
			'method_ids'     => $cvs_titles,
			'handler'        => [ Operations\BatchPrint::class, 'cvs' ],
			'record_counter' => $counter,
			'record_lister'  => $lister,
			'record_deleter' => $deleter,
			// SmilePay CVS print API 固定格式，不分紙張
			'paper_modes'    => [ '1' ],
		];

		$home_titles                = [
			'moksafowo_smilepay_shipping_tcat'         => __( 'SmilePay T-Cat home delivery', 'moksa-for-woocommerce' ),
			'moksafowo_smilepay_shipping_tcat_normal'  => __( 'SmilePay T-Cat ambient', 'moksa-for-woocommerce' ),
			'moksafowo_smilepay_shipping_tcat_refrige' => __( 'SmilePay T-Cat chilled', 'moksa-for-woocommerce' ),
			'moksafowo_smilepay_shipping_tcat_freeze'  => __( 'SmilePay T-Cat frozen', 'moksa-for-woocommerce' ),
		];
		$temps                      = static function ( \WC_Order $o ): array {
			$out = [];
			foreach ( Operations\CreateOrder::get_records( $o ) as $r ) {
				$t = (int) ( $r['temp'] ?? 0 );
				if ( $t > 0 ) {
					$out[ $t ] = true;
				}
			}
			return array_keys( $out );
		};
		$providers['smilepay-home'] = [
			'label'          => __( 'SmilePay T-Cat labels', 'moksa-for-woocommerce' ),
			'category'       => 'home',
			'method_ids'     => $home_titles,
			'handler'        => [ Operations\BatchPrint::class, 'home' ],
			'record_counter' => $counter,
			'record_temps'   => $temps,
			'record_lister'  => $lister,
			'record_deleter' => $deleter,
			'paper_modes'    => [ '1' ],
		];

		return $providers;
	}
}
