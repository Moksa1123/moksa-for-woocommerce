<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Payuni\Setup;

defined( 'ABSPATH' ) || exit;

// copy-only migration: legacy `payuni_payment_*` → `moksafowo_payuni_payment_*`，legacy 永久保留作 backup
final class CredentialsMigrator {

	private const SENTINEL = 'moksafowo_payuni_credentials_migrated_at';

	private const MAP = [
		'moksafowo_payuni_payment_testmode_enabled' => 'moksafowo_payuni_payment_testmode_enabled',
		'moksafowo_payuni_payment_merchant_id'      => 'moksafowo_payuni_payment_merchant_id',
		'moksafowo_payuni_payment_merchant_id_test' => 'moksafowo_payuni_payment_merchant_id_test',
		'moksafowo_payuni_payment_hashkey'          => 'moksafowo_payuni_payment_hashkey',
		'moksafowo_payuni_payment_hashkey_test'     => 'moksafowo_payuni_payment_hashkey_test',
		'moksafowo_payuni_payment_hashiv'           => 'moksafowo_payuni_payment_hashiv',
		'moksafowo_payuni_payment_hashiv_test'      => 'moksafowo_payuni_payment_hashiv_test',
	];

	public static function run_once(): void {
		if ( false !== get_option( self::SENTINEL ) ) {
			return;
		}
		$copied = 0;
		foreach ( self::MAP as $legacy => $new ) {
			$existing_new = get_option( $new );
			if ( false !== $existing_new && '' !== (string) $existing_new ) {
				continue;
			}
			$legacy_value = get_option( $legacy );
			if ( false === $legacy_value || '' === (string) $legacy_value ) {
				continue;
			}
			update_option( $new, $legacy_value, false );
			++$copied;
		}
		update_option( self::SENTINEL, gmdate( 'c' ) . ' copied=' . $copied, false );
	}
}
