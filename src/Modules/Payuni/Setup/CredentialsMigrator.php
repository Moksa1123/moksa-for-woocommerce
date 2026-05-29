<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Payuni\Setup;

defined( 'ABSPATH' ) || exit;

// copy-only migration: legacy `payuni_payment_*` → `mo_payuni_payment_*`，legacy 永久保留作 backup
final class CredentialsMigrator {

	private const SENTINEL = 'mo_payuni_credentials_migrated_at';

	private const MAP = [
		'payuni_payment_testmode_enabled' => 'mo_payuni_payment_testmode_enabled',
		'payuni_payment_merchant_id'      => 'mo_payuni_payment_merchant_id',
		'payuni_payment_merchant_id_test' => 'mo_payuni_payment_merchant_id_test',
		'payuni_payment_hashkey'          => 'mo_payuni_payment_hashkey',
		'payuni_payment_hashkey_test'     => 'mo_payuni_payment_hashkey_test',
		'payuni_payment_hashiv'           => 'mo_payuni_payment_hashiv',
		'payuni_payment_hashiv_test'      => 'mo_payuni_payment_hashiv_test',
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
