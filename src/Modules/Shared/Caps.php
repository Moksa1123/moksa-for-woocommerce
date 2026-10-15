<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * 不可逆動作需要的權限。後台按鈕與 AI／MCP 能力共用同一個判斷，
 * 不然只能編輯訂單的帳號在後台就能做到 AI 那邊擋下的事。
 *
 * 預設店長等級（manage_woocommerce）；店家可用 moksafowo_cap_<action> filter 調整。
 */
final class Caps {

	public const INVOICE_VOID      = 'invoice_void';
	public const INVOICE_ALLOWANCE = 'invoice_allowance';
	public const CARD_REFUND       = 'card_refund';
	public const SHIPMENT_DELETE   = 'shipment_delete';

	public static function for( string $action ): string {
		return (string) apply_filters( 'moksafowo_cap_' . $action, 'manage_woocommerce' );
	}

	public static function user_can( string $action ): bool {
		return current_user_can( self::for( $action ) );
	}

	private function __construct() {}
}
