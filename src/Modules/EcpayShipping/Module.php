<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping;

use Moksafowo\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'ecpay_shipping';
	}

	public function label(): string {
		return __( 'ECPay shipping — 7-ELEVEN, FamilyMart, Hi-Life and OK Mart pickup, plus T-Cat and Chunghwa Post home delivery', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'shipping';
	}

	public function name(): string {
		return __( 'ECPay shipping', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( '7-ELEVEN, FamilyMart, Hi-Life and OK Mart pickup, plus T-Cat and post office delivery', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( '7-ELEVEN pickup', 'moksa-for-woocommerce' ),
			__( 'FamilyMart pickup', 'moksa-for-woocommerce' ),
			__( 'Hi-Life pickup', 'moksa-for-woocommerce' ),
			__( 'OK Mart pickup', 'moksa-for-woocommerce' ),
			__( 'T-Cat home delivery', 'moksa-for-woocommerce' ),
			__( 'Chunghwa Post', 'moksa-for-woocommerce' ),
		];
	}

	public function settings_section(): string {
		return 'ecpay-shipping';
	}

	public function boot(): void {
		add_filter( 'woocommerce_shipping_methods', [ __CLASS__, 'register_methods' ] );

		add_action( 'woocommerce_api_moksafowo_ecpay_shipping_status', [ Webhook\IpnHandler::class, 'handle' ] );
		Webhook\StatusMapper::init();
		Frontend\StoreSelector::init();
		Frontend\CustomerOrderView::init();
		// WC 用 woocommerce_get_order_address（不是 woocommerce_order_get_address）
		add_filter( 'woocommerce_order_get_formatted_shipping_address', [ Admin\OrderMetaBox::class, 'inject_cvs_shipping_address' ], 10, 3 );
		add_filter( 'woocommerce_get_order_address', [ Admin\OrderMetaBox::class, 'inject_cvs_address_fields' ], 10, 3 );
		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
			Operations\PrintProxy::init();
		}
		add_filter( 'moksafowo_shipping_batch_print_providers', [ __CLASS__, 'register_batch_print' ] );
		add_filter( \Moksafowo\Modules\Shipping\Admin\CvsStoreEditor::PROVIDERS_FILTER, [ __CLASS__, 'register_cvs_store_editor' ] );
		Emails\EmailTrackingProvider::init();
	}

	/**
	 * @param array<string,array<string,mixed>> $providers Registered providers.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_cvs_store_editor( array $providers ): array {
		$methods = [];
		foreach ( self::method_map() as $id => $class ) {
			if ( is_subclass_of( $class, \Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod::class ) ) {
				$methods[ $id ] = $id;
			}
		}
		if ( empty( $methods ) ) {
			return $providers;
		}
		$providers['ecpay'] = [
			'label'    => __( 'ECPay convenience store pickup', 'moksa-for-woocommerce' ),
			'methods'  => $methods,
			'meta'     => [
				'id'      => \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_ID,
				'name'    => \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_NAME,
				'address' => \Moksafowo\Order\Meta\Keys::SHIPPING_CVS_STORE_ADDRESS,
			],
			'open_map' => [ Frontend\StoreSelector::class, 'admin_map_payload' ],
		];
		return $providers;
	}

	public static function register_batch_print( array $providers ): array {
		$titles = [
			'moksafowo_ecpay_shipping_cvs_711'            => __( 'ECPay 7-ELEVEN pickup', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_cvs_711_b2c_freeze' => __( 'ECPay 7-ELEVEN B2C frozen', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_cvs_family'         => __( 'ECPay FamilyMart pickup', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_cvs_hilife'         => __( 'ECPay Hi-Life pickup', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_cvs_okmart'         => __( 'ECPay OK Mart pickup', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_home_tcat'          => __( 'ECPay T-Cat home delivery', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_home_post'          => __( 'ECPay Chunghwa Post', 'moksa-for-woocommerce' ),
		];
		$cvs    = [];
		$home   = [];
		foreach ( self::method_map() as $id => $class ) {
			$title = $titles[ $id ] ?? $id;
			if ( str_contains( $id, '_cvs_' ) ) {
				$cvs[ $id ] = $title;
			} elseif ( str_contains( $id, '_home_' ) ) {
				$home[ $id ] = $title;
			}
		}
		$counter = static fn( \WC_Order $o ): int => count( Operations\CreateOrder::get_records( $o ) );
		// AI / MCP 用：列出與刪除物流單記錄（走 ShipmentRecords 門面）
		$lister  = static function ( \WC_Order $o ): array {
			$out = [];
			foreach ( Operations\CreateOrder::get_records( $o ) as $r ) {
				$out[] = [
					'id'         => (string) ( $r['id'] ?? '' ),
					'tracking'   => trim( (string) ( $r['cvs_payment_no'] ?? '' ) . ' ' . (string) ( $r['cvs_validation_no'] ?? '' ) ),
					'created_at' => (string) ( $r['created_at'] ?? '' ),
					'temp'       => (string) ( $r['temp'] ?? '' ),
					'note'       => (string) ( $r['rtn_msg'] ?? '' ),
				];
			}
			return $out;
		};
		$deleter = static fn( \WC_Order $o, string $id ): bool => Operations\CreateOrder::delete_record( $o, $id );
		// A6 僅 UNIMARTC2C / UNIMART / UNIMARTFREEZE / POST 支援
		$row_modes = static function ( \WC_Order $o ): array {
			$records = Operations\CreateOrder::get_records( $o );
			if ( empty( $records ) ) {
				return [ '1', '2' ];
			}
			$latest  = end( $records );
			$subtype = (string) ( $latest['subtype'] ?? '' );
			return in_array( $subtype, [ 'UNIMARTC2C', 'UNIMART', 'UNIMARTFREEZE', 'POST' ], true ) ? [ '1', '2' ] : [ '1' ];
		};
		// 舊 records 未記 temp 時 subtype fallback：UNIMARTFREEZE=3，其餘=1
		$temps = static function ( \WC_Order $o ): array {
			$out = [];
			foreach ( Operations\CreateOrder::get_records( $o ) as $r ) {
				$t = (int) ( $r['temp'] ?? 0 );
				if ( 0 === $t ) {
					$t = 'UNIMARTFREEZE' === ( $r['subtype'] ?? '' ) ? 3 : 1;
				}
				$out[ $t ] = true;
			}
			return array_keys( $out );
		};
		if ( ! empty( $cvs ) ) {
			$providers['ecpay-cvs'] = [
				'label'           => __( 'ECPay convenience store labels', 'moksa-for-woocommerce' ),
				'category'        => 'cvs',
				'method_ids'      => $cvs,
				'handler'         => [ Operations\BatchPrint::class, 'render' ],
				'record_counter'  => $counter,
				'record_temps'    => $temps,
				'record_lister'   => $lister,
				'record_deleter'  => $deleter,
				'paper_modes'     => [ '1', '2' ],
				'row_paper_modes' => $row_modes,
			];
		}
		if ( ! empty( $home ) ) {
			$providers['ecpay-home'] = [
				'label'           => __( 'ECPay home delivery labels', 'moksa-for-woocommerce' ),
				'category'        => 'home',
				'method_ids'      => $home,
				'handler'         => [ Operations\BatchPrint::class, 'render' ],
				'record_counter'  => $counter,
				'record_temps'    => $temps,
				'record_lister'   => $lister,
				'record_deleter'  => $deleter,
				'paper_modes'     => [ '1', '2' ], // POST 支援 A6；TCAT 只 A4（row_paper_modes 細控）
				'row_paper_modes' => $row_modes,
			];
		}
		return $providers;
	}

	public static function method_map(): array {
		// ECAN（嘉里大榮）2022/06/30 被 ECPay 終止合作，永不註冊
		return [
			'moksafowo_ecpay_shipping_cvs_711'            => Methods\Cvs711::class,
			'moksafowo_ecpay_shipping_cvs_711_b2c_freeze' => Methods\Cvs711B2CFreeze::class,
			'moksafowo_ecpay_shipping_cvs_family'         => Methods\CvsFamily::class,
			'moksafowo_ecpay_shipping_cvs_hilife'         => Methods\CvsHilife::class,
			'moksafowo_ecpay_shipping_cvs_okmart'         => Methods\CvsOkmart::class,
			'moksafowo_ecpay_shipping_home_tcat'          => Methods\HomeTcat::class,
			'moksafowo_ecpay_shipping_home_post'          => Methods\HomePost::class,
		];
	}

	public static function register_methods( array $methods ): array {
		foreach ( self::method_map() as $id => $class ) {
			$methods[ $id ] = $class;
		}
		return $methods;
	}
}
