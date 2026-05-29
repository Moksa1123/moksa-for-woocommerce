<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\ShoplinePayments\Blocks;

use MoksaWeb\Mowc\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class ShoplinePaymentsBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'shopline-payments';
	}
}
