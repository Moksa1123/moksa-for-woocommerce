<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Admin;

use Moksafowo\Settings\SettingsTab;

defined( 'ABSPATH' ) || exit;

/**
 * 金流測試模式開著時，後台顯示警告。
 *
 * 測試模式收到的付款通知不會入帳（見 PaidOrder::complete），店家正式上線後忘了關，
 * 顧客付的錢不會讓訂單變成處理中，要讓店家一眼看到。
 */
final class SandboxNotice {

	/** @var array<string,array{0:string,1:string}> 金流名稱 => [設定頁 section, 付款方式 ID 前綴] */
	private static array $providers = [];

	public static function add( string $provider_label, string $section, string $gateway_prefix ): void {
		if ( ! is_admin() ) {
			return;
		}
		if ( [] === self::$providers ) {
			add_action( 'admin_notices', [ self::class, 'render' ] );
		}
		self::$providers[ $provider_label ] = [ $section, $gateway_prefix ];
	}

	public static function render(): void {
		if ( [] === self::$providers || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'WC' ) ) {
			return;
		}
		$enabled = array_keys(
			array_filter(
				WC()->payment_gateways()->payment_gateways(),
				static fn( $g ): bool => $g instanceof \WC_Payment_Gateway && 'yes' === $g->enabled
			)
		);
		foreach ( self::$providers as $label => [ $section, $prefix ] ) {
			// 只有至少一個這家的付款方式開著才提醒；模組開了但付款方式都關著，測試模式不會收到付款。
			$active = array_filter( $enabled, static fn( $id ): bool => str_starts_with( (string) $id, $prefix ) );
			if ( [] === $active ) {
				continue;
			}
			$url = admin_url( sprintf( 'admin.php?page=wc-settings&tab=%s&section=%s', SettingsTab::TAB_ID, $section ) );
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %s: payment provider name */
						__( '%s is in test mode. Payments reported in test mode are not real, so those orders are put on hold instead of being marked as paid. Turn off test mode before taking real payments.', 'moksa-for-woocommerce' ),
						$label
					)
				),
				esc_url( $url ),
				esc_html__( 'Payment settings', 'moksa-for-woocommerce' )
			);
		}
	}

	private function __construct() {}
}
