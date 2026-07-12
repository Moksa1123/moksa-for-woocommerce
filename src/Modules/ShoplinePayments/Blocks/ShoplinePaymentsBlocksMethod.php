<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\ShoplinePayments\Blocks;

use Moksafowo\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class ShoplinePaymentsBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'shopline-payments';
	}
}
