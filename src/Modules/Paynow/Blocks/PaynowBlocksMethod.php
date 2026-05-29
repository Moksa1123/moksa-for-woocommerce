<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Paynow\Blocks;

use MoksaWeb\Mowc\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class PaynowBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'paynow';
	}
}
