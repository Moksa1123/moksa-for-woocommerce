<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Linepay;

defined( 'ABSPATH' ) || exit;

final class StatusCode {

	const UNAUTH            = '0000';
	const AUTHED            = '0110';
	const CANCELLED_EXPIRED = '0121';
	const FAILED            = '0122';
	const COMPLETED         = '0123';
	const NO_MERCHANT       = '1104';
	const CANNOT_USE        = '1105';
	const INTERNAL_ERROR    = '9000';
}
