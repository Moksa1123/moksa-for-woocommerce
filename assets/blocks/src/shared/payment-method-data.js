/**
 * 取區塊結帳注入的付款方式設定。
 *
 * WooCommerce 11.1 換了形狀：以前每個方式各自一個 `<id>_data` setting，
 * 現在改成單一 `paymentMethodData` 物件、以 gateway id 為 key
 * （旁邊還多了 `paymentMethodSortOrder`）。舊 key 在 11.1 已經完全不存在，
 * 讀不到就 return 的寫法會讓整個付款方式靜默消失 —— 前台空白、後台一切正常。
 *
 * 本外掛宣告支援 WC 9.9 以上，所以兩種都要吃：先問新的，再退回舊的。
 */

import { getSetting } from '@woocommerce/settings';

export function getPaymentMethodData( id ) {
	const bundle = getSetting( 'paymentMethodData', null );
	if ( bundle && typeof bundle === 'object' && bundle[ id ] ) {
		return bundle[ id ];
	}
	// WC < 11.1
	return getSetting( `${ id }_data`, null );
}
