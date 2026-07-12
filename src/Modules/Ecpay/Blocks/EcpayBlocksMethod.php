<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Ecpay\Blocks;

use Moksafowo\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class EcpayBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'ecpay';
	}
}
