<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Newebpay\Blocks;

use Moksafowo\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class NewebpayBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'newebpay';
	}
}
