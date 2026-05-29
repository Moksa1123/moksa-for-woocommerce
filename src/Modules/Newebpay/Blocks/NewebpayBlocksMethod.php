<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Newebpay\Blocks;

use MoksaWeb\Mowc\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class NewebpayBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'newebpay';
	}
}
