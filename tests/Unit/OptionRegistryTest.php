<?php
declare( strict_types=1 );

namespace Moksafowo\Tests\Unit;

use Moksafowo\Settings\OptionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * OptionRegistry::ours() — 解除安裝時照登記表刪 option，所以「哪些算本外掛的」不能漏也不能多：
 * 漏了會留下設定，多了會刪到別的外掛（例如 miniOrange 的 mo_、官方 LINE Pay 外掛的設定）。
 */
final class OptionRegistryTest extends TestCase {

	/** @dataProvider ours_cases */
	public function test_ours( string $name, bool $expected ): void {
		self::assertSame( $expected, OptionRegistry::ours( $name ) );
	}

	/** @return array<string,array{string,bool}> */
	public static function ours_cases(): array {
		return [
			'模組開關'               => [ 'moksafowo_ecpay_enabled', true ],
			'閘道設定'               => [ 'woocommerce_moksafowo_ecpay_credit_settings', true ],
			'LINE Pay 閘道設定'      => [ 'woocommerce_moksafowo-linepay_settings', true ],
			'LINE Pay fork 設定'     => [ 'Moksafowo_LinePay_channel_id', true ],
			'LINE Pay 舊拼法'        => [ 'Mo_LinePay_channel_id', true ],
			'登記表本身'             => [ OptionRegistry::OPTION, false ],
			'transient'              => [ '_transient_moksafowo_cs_try_abc', false ],
			'transient 逾時'         => [ '_transient_timeout_moksafowo_cs_try_abc', false ],
			'核心鎖'                 => [ 'moksafowo_cs_lock_12.lock', false ],
			'訂閱鎖'                 => [ 'moksafowo_lock_0123abcd', false ],
			'客服節流鎖'             => [ 'moksafowo_cs_rl_lock_0123abcd', false ],
			'別的外掛 mo_'           => [ 'mo_ldap_local_config', false ],
			'官方 LINE Pay 外掛'     => [ 'woocommerce_linepay_settings', false ],
			'WC 本身'                => [ 'woocommerce_currency', false ],
		];
	}
}
