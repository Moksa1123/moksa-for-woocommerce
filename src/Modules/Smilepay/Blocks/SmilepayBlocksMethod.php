<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Smilepay\Blocks;

use Moksafowo\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class SmilepayBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'smilepay';
	}
}
