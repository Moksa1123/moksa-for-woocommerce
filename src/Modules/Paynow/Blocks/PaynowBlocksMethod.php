<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Paynow\Blocks;

use Moksafowo\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class PaynowBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'paynow';
	}
}
