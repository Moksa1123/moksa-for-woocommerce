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
		return __( 'SmilePay 物流 — 7-11 / 全家 / 黑貓 (常溫/冷藏/冷凍)', 'mo-ectools' );
	}

	public function category(): string {
		return 'shipping';
	}

	public function name(): string {
		return __( 'SmilePay 物流', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '7-11 / 全家 取貨 + 黑貓 常溫/冷藏/冷凍', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( '7-11 取貨', 'mo-ectools' ),
			__( '全家取貨', 'mo-ectools' ),
			__( '黑貓常溫', 'mo-ectools' ),
			__( '黑貓冷藏', 'mo-ectools' ),
			__( '黑貓冷凍', 'mo-ectools' ),
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
		Frontend\StoreSelector::init();
		Frontend\CustomerOrderView::init();
		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
			Operations\PrintProxy::init();
		}
		Emails\EmailTrackingProvider::init();
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

		$cvs_titles                = [
			'moksafowo_smilepay_shipping_cvs_711'  => __( '速買配 7-11 取貨', 'mo-ectools' ),
			'moksafowo_smilepay_shipping_cvs_fami' => __( '速買配 全家取貨', 'mo-ectools' ),
		];
		$providers['smilepay-cvs'] = [
			'label'          => __( '速買配 超商標籤', 'mo-ectools' ),
			'category'       => 'cvs',
			'method_ids'     => $cvs_titles,
			'handler'        => [ Operations\BatchPrint::class, 'cvs' ],
			'record_counter' => $counter,
			// SmilePay CVS print API 固定格式，不分紙張
			'paper_modes'    => [ '1' ],
		];

		$home_titles                = [
			'moksafowo_smilepay_shipping_tcat'         => __( '速買配 黑貓宅配', 'mo-ectools' ),
			'moksafowo_smilepay_shipping_tcat_normal'  => __( '速買配 黑貓常溫', 'mo-ectools' ),
			'moksafowo_smilepay_shipping_tcat_refrige' => __( '速買配 黑貓冷藏', 'mo-ectools' ),
			'moksafowo_smilepay_shipping_tcat_freeze'  => __( '速買配 黑貓冷凍', 'mo-ectools' ),
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
			'label'          => __( '速買配 黑貓標籤', 'mo-ectools' ),
			'category'       => 'home',
			'method_ids'     => $home_titles,
			'handler'        => [ Operations\BatchPrint::class, 'home' ],
			'record_counter' => $counter,
			'record_temps'   => $temps,
			'paper_modes'    => [ '1' ],
		];

		return $providers;
	}
}
