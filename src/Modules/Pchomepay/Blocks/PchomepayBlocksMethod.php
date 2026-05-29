<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Pchomepay\Blocks;

use MoksaWeb\Mowc\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class PchomepayBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'pchomepay';
	}
}
