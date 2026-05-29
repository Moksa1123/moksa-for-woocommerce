<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayShipping;

use MoksaWeb\Mowc\Modules\AbstractModule;

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

		// 物流 IPN 接 SmilePay 物流貨態回傳
		add_action( 'woocommerce_api_mo_smilepay_shipping_status', [ Api\IpnHandler::class, 'handle' ] );

		// CVS 取貨地址不需要收件電話 / first_name / last_name 必填
		add_filter( 'woocommerce_default_address_fields', [ __CLASS__, 'relax_cvs_required_fields' ] );

		// 批次列印 — 走 SP_B2C_CVS_PRINT_API 或 SP_TCAT_PRINT_API
		add_filter( 'mo_shipping_batch_print_providers', [ __CLASS__, 'register_batch_print' ] );

		// 結帳選店流程
		Frontend\StoreSelector::init();
		// 顧客 my-account/view-order 物流卡片 + tracking buttons
		Frontend\CustomerOrderView::init();

		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
			Operations\PrintProxy::init();
		}

		// Email 貨態追蹤 — 自己 register filter callback 提供 entries（Shipping core 解耦）
		Emails\EmailTrackingProvider::init();
	}

	public static function method_map(): array {
		return [
			'mo_smilepay_shipping_cvs_711'      => Methods\Cvs711::class,
			'mo_smilepay_shipping_cvs_fami'     => Methods\CvsFami::class,
			'mo_smilepay_shipping_tcat'         => Methods\Tcat::class,
			'mo_smilepay_shipping_tcat_normal'  => Methods\TcatNormal::class,
			'mo_smilepay_shipping_tcat_refrige' => Methods\TcatRefrige::class,
			'mo_smilepay_shipping_tcat_freeze'  => Methods\TcatFreeze::class,
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

		// CVS bucket — 7-11 + 全家
		$cvs_titles = [
			'mo_smilepay_shipping_cvs_711'  => __( '速買配 7-11 取貨', 'mo-ectools' ),
			'mo_smilepay_shipping_cvs_fami' => __( '速買配 全家取貨', 'mo-ectools' ),
		];
		$providers['smilepay-cvs'] = [
			'label'          => __( '速買配 超商標籤', 'mo-ectools' ),
			'category'       => 'cvs',
			'method_ids'     => $cvs_titles,
			'handler'        => [ Operations\BatchPrint::class, 'cvs' ],
			'record_counter' => $counter,
			// SmilePay B2C CVS print API 不分紙張，固定一格式
			'paper_modes'    => [ '1' ],
		];

		// HOME bucket — 黑貓 統一 method（多溫層拆單）+ 既有 3 個單溫層 method
		$home_titles = [
			'mo_smilepay_shipping_tcat'         => __( '速買配 黑貓宅配', 'mo-ectools' ),
			'mo_smilepay_shipping_tcat_normal'  => __( '速買配 黑貓常溫', 'mo-ectools' ),
			'mo_smilepay_shipping_tcat_refrige' => __( '速買配 黑貓冷藏', 'mo-ectools' ),
			'mo_smilepay_shipping_tcat_freeze'  => __( '速買配 黑貓冷凍', 'mo-ectools' ),
		];
		// records 的溫層集合（給拆單訂單顯示溫層 pill 用），對應 ECPay register_batch_print 邏輯
		$temps = static function ( \WC_Order $o ): array {
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
