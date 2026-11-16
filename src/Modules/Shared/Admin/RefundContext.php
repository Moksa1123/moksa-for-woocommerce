<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Admin;

use Moksafowo\Http\Input;

defined( 'ABSPATH' ) || exit;

/**
 * 商家停用某個付款方式（改顯示模式、從清單取消勾選）後，用它付款的舊訂單仍要能從後台退款。
 * WC 靠「找得到這個 gateway 物件」決定要不要顯示「透過金流退款」，所以在後台訂單頁與退款請求時
 * 把所有付款方式都註冊進來；結帳頁照舊只出現啟用的那些。
 */
final class RefundContext {

	public static function needs_all_gateways(): bool {
		if ( ! is_admin() ) {
			return false;
		}
		if ( wp_doing_ajax() ) {
			$action = sanitize_key( Input::request( 'action' ) );
			return 'woocommerce_refund_line_items' === $action;
		}
		$page = sanitize_key( Input::get( 'page' ) );
		if ( 'wc-orders' === $page ) {
			return true;
		}
		global $pagenow;
		return 'post.php' === $pagenow;
	}
}
