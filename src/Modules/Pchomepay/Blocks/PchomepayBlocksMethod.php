<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Pchomepay\Blocks;

use Moksafowo\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class PchomepayBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'pchomepay';
	}
}
