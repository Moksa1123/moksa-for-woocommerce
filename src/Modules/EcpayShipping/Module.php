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
		return __( '綠界物流 — 7-11 / 全家 / 萊爾富 / OK 取貨 + 黑貓 / 中華郵政 宅配', 'moksa-for-woocommerce' );
	}

	public function category(): string {
		return 'shipping';
	}

	public function name(): string {
		return __( '綠界物流', 'moksa-for-woocommerce' );
	}

	public function tagline(): string {
		return __( '7-11 / 全家 / 萊爾富 / OK 取貨 + 黑貓 / 郵局 宅配', 'moksa-for-woocommerce' );
	}

	public function methods(): array {
		return [
			__( '7-11 取貨', 'moksa-for-woocommerce' ),
			__( '全家取貨', 'moksa-for-woocommerce' ),
			__( '萊爾富取貨', 'moksa-for-woocommerce' ),
			__( 'OK 取貨', 'moksa-for-woocommerce' ),
			__( '黑貓宅配', 'moksa-for-woocommerce' ),
			__( '中華郵政', 'moksa-for-woocommerce' ),
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
		Emails\EmailTrackingProvider::init();
	}

	public static function register_batch_print( array $providers ): array {
		$titles = [
			'moksafowo_ecpay_shipping_cvs_711'            => __( '綠界 7-11 取貨', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_cvs_711_b2c_freeze' => __( '綠界 7-11 B2C 冷凍', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_cvs_family'         => __( '綠界 全家取貨', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_cvs_hilife'         => __( '綠界 萊爾富取貨', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_cvs_okmart'         => __( '綠界 OK 取貨', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_home_tcat'          => __( '綠界 黑貓宅配', 'moksa-for-woocommerce' ),
			'moksafowo_ecpay_shipping_home_post'          => __( '綠界 中華郵政', 'moksa-for-woocommerce' ),
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
				'label'           => __( '綠界 超商標籤', 'moksa-for-woocommerce' ),
				'category'        => 'cvs',
				'method_ids'      => $cvs,
				'handler'         => [ Operations\BatchPrint::class, 'render' ],
				'record_counter'  => $counter,
				'record_temps'    => $temps,
				'paper_modes'     => [ '1', '2' ],
				'row_paper_modes' => $row_modes,
			];
		}
		if ( ! empty( $home ) ) {
			$providers['ecpay-home'] = [
				'label'           => __( '綠界 宅配標籤', 'moksa-for-woocommerce' ),
				'category'        => 'home',
				'method_ids'      => $home,
				'handler'         => [ Operations\BatchPrint::class, 'render' ],
				'record_counter'  => $counter,
				'record_temps'    => $temps,
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
