<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping;

use MoksaWeb\Mowc\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'ecpay_shipping';
	}

	public function label(): string {
		return __( '綠界物流 — 7-11 / 全家 / 萊爾富 / OK 取貨 + 黑貓 / 中華郵政 宅配', 'mo-ectools' );
	}

	public function category(): string {
		return 'shipping';
	}

	public function name(): string {
		return __( '綠界物流', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '7-11 / 全家 / 萊爾富 / OK 取貨 + 黑貓 / 郵局 宅配', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( '7-11 取貨', 'mo-ectools' ),
			__( '全家取貨', 'mo-ectools' ),
			__( '萊爾富取貨', 'mo-ectools' ),
			__( 'OK 取貨', 'mo-ectools' ),
			__( '黑貓宅配', 'mo-ectools' ),
			__( '中華郵政', 'mo-ectools' ),
		];
	}

	public function settings_section(): string {
		return 'ecpay-shipping';
	}

	public function boot(): void {
		add_filter( 'woocommerce_shipping_methods', [ __CLASS__, 'register_methods' ] );

		// IPN 接 ECPay 物流貨態回傳
		add_action( 'woocommerce_api_mo_ecpay_shipping_status', [ Webhook\IpnHandler::class, 'handle' ] );

		// StatusMapper — listen mo_ecpay_shipping_status_received action
		Webhook\StatusMapper::init();

		// 結帳 CVS 選店流程
		Frontend\StoreSelector::init();

		// 顧客「我的帳戶 → 訂單詳情」物流資訊區塊
		Frontend\CustomerOrderView::init();

		// 運送地址注入「carrier + 門市」— admin / frontend / email 都要，所以在 is_admin() 之外註冊
		add_filter( 'woocommerce_order_get_formatted_shipping_address', [ Admin\OrderMetaBox::class, 'inject_cvs_shipping_address' ], 10, 3 );
		// HPOS 訂單列表的 Google Maps URL 走 raw `get_address('shipping')`，CVS 訂單注入 store_address 修連結
		// 注意 filter 名稱：WC 用 `woocommerce_get_order_address` 不是 `woocommerce_order_get_address`
		add_filter( 'woocommerce_get_order_address', [ Admin\OrderMetaBox::class, 'inject_cvs_address_fields' ], 10, 3 );

		// 訂單編輯頁 meta box（建單 / 列印按鈕）
		if ( is_admin() ) {
			Admin\OrderMetaBox::init();
			Operations\PrintProxy::init();
		}

		// 註冊批次列印能力（CVS + HOME 各一條）
		add_filter( 'mo_shipping_batch_print_providers', [ __CLASS__, 'register_batch_print' ] );

		// Email 貨態追蹤 — 自己 register filter callback 提供 entries（Shipping core 解耦）
		Emails\EmailTrackingProvider::init();
	}

	public static function register_batch_print( array $providers ): array {
		$titles = [
			'mo_ecpay_shipping_cvs_711'            => __( '綠界 7-11 取貨', 'mo-ectools' ),
			'mo_ecpay_shipping_cvs_711_b2c_freeze' => __( '綠界 7-11 B2C 冷凍', 'mo-ectools' ),
			'mo_ecpay_shipping_cvs_family'         => __( '綠界 全家取貨', 'mo-ectools' ),
			'mo_ecpay_shipping_cvs_hilife'         => __( '綠界 萊爾富取貨', 'mo-ectools' ),
			'mo_ecpay_shipping_cvs_okmart'         => __( '綠界 OK 取貨', 'mo-ectools' ),
			'mo_ecpay_shipping_home_tcat'          => __( '綠界 黑貓宅配', 'mo-ectools' ),
			'mo_ecpay_shipping_home_post'          => __( '綠界 中華郵政', 'mo-ectools' ),
		];
		$cvs  = [];
		$home = [];
		foreach ( self::method_map() as $id => $class ) {
			$title = $titles[ $id ] ?? $id;
			if ( str_contains( $id, '_cvs_' ) ) {
				$cvs[ $id ] = $title;
			} elseif ( str_contains( $id, '_home_' ) ) {
				$home[ $id ] = $title;
			}
		}
		$counter = static fn( \WC_Order $o ): int => count( Operations\CreateOrder::get_records( $o ) );
		// 依最新 logistic record 的 subtype 判斷此訂單支援哪些紙張：A6 限 UNIMARTC2C / UNIMART / UNIMARTFREEZE / POST。
		$row_modes = static function ( \WC_Order $o ): array {
			$records = Operations\CreateOrder::get_records( $o );
			if ( empty( $records ) ) {
				return [ '1', '2' ];
			}
			$latest  = end( $records );
			$subtype = (string) ( $latest['subtype'] ?? '' );
			return in_array( $subtype, [ 'UNIMARTC2C', 'UNIMART', 'UNIMARTFREEZE', 'POST' ], true ) ? [ '1', '2' ] : [ '1' ];
		};
		// records 的溫層集合（給拆單訂單顯示溫層 pill 用）。
		// 新 records 直接讀 temp 欄位；舊 records 沒記 temp 時用 subtype fallback：
		//   UNIMARTFREEZE      → 冷凍 (3)
		//   其他 CVS / TCAT / POST → 常溫 (1)（TCAT 多溫 legacy 拿不回來，預設常溫）
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
				'label'           => __( '綠界 超商標籤', 'mo-ectools' ),
				'category'        => 'cvs',
				'method_ids'      => $cvs,
				'handler'         => [ Operations\BatchPrint::class, 'render' ],
				'record_counter'  => $counter,
				'record_temps'    => $temps,
				// provider 級允許 A4+A6；row 級會依 subtype 過濾（FAMI/HILIFE/OK 只 A4，UNIMART 才 A4+A6）
				'paper_modes'     => [ '1', '2' ],
				'row_paper_modes' => $row_modes,
			];
		}
		if ( ! empty( $home ) ) {
			$providers['ecpay-home'] = [
				'label'           => __( '綠界 宅配標籤', 'mo-ectools' ),
				'category'        => 'home',
				'method_ids'      => $home,
				'handler'         => [ Operations\BatchPrint::class, 'render' ],
				'record_counter'  => $counter,
				'record_temps'    => $temps,
				// 中華郵政 (POST) A4+A6；黑貓 (TCAT) 只 A4
				'paper_modes'     => [ '1', '2' ],
				'row_paper_modes' => $row_modes,
			];
		}
		return $providers;
	}

	public static function method_map(): array {
		// 注意：嘉里大榮 (ECAN) 已被 ECPay 於 2022/06/30 終止合作，永不註冊。
		return [
			'mo_ecpay_shipping_cvs_711'            => Methods\Cvs711::class,
			'mo_ecpay_shipping_cvs_711_b2c_freeze' => Methods\Cvs711B2CFreeze::class,
			'mo_ecpay_shipping_cvs_family'         => Methods\CvsFamily::class,
			'mo_ecpay_shipping_cvs_hilife'         => Methods\CvsHilife::class,
			'mo_ecpay_shipping_cvs_okmart'         => Methods\CvsOkmart::class,
			'mo_ecpay_shipping_home_tcat'          => Methods\HomeTcat::class,
			'mo_ecpay_shipping_home_post'          => Methods\HomePost::class,
		];
	}

	public static function register_methods( array $methods ): array {
		foreach ( self::method_map() as $id => $class ) {
			$methods[ $id ] = $class;
		}
		return $methods;
	}
}
