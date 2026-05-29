<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Ecpay\Blocks;

use MoksaWeb\Mowc\Modules\Shared\Blocks\AbstractMowcBlocksMethod;

defined( 'ABSPATH' ) || exit;

final class EcpayBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'ecpay';
	}
}
