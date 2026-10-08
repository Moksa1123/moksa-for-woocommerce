<?php

namespace Moksafowo\Modules\Payuni\Admin;

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\Payuni\PayuniPayment;

class OrderList {

	public static function init() {
		// 發票號碼欄由共用層統一提供（各家發票商都讀得到），這裡只負責在開啟 PAYUNi 發票時掛上。
		if ( PayuniPayment::$einvoice_enabled && is_admin() ) {
			\Moksafowo\Modules\Shared\Invoice\OrderListColumn::init();
		}
	}
}
