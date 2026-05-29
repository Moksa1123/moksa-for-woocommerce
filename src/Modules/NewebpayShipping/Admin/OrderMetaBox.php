<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\NewebpayShipping\Admin;

use MoksaWeb\Mowc\Modules\Shared\Admin\CardRenderers;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox {

	public static function init(): void {
		// 確保 CardRenderers 已 boot — 共用層會註冊 metabox + dispatch shipping renderer
		CardRenderers::boot();
	}
}
