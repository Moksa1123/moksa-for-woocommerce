<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Smilepay\Blocks;

use MoksaWeb\Mowc\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class SmilepayBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'smilepay';
	}
}
