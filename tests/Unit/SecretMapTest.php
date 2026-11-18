<?php
/**
 * SecretMap：程式裡看起來像金鑰的 option 都要列在清單裡，設定頁才會一律不印明文。
 *
 * @package Moksafowo
 */

declare( strict_types=1 );

namespace Moksafowo\Tests\Unit;

use Moksafowo\Settings\SecretMap;
use PHPUnit\Framework\TestCase;

final class SecretMapTest extends TestCase {

	/** 新增金流、物流、發票時，金鑰 option 沒加進 SecretMap 就會被這裡抓到。 */
	public function test_every_credential_option_in_src_is_mapped(): void {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			preg_match_all( "/'(moksafowo_[a-z0-9_]*(?:hash_?key|hash_?iv|_secret|api_key|app_key|partner_key|sign_key|client_key|verify_key|password)(?:_test)?)'/", (string) file_get_contents( $file->getPathname() ), $m );
			foreach ( $m[1] as $name ) {
				$found[ $name ] = true;
			}
		}
		$missing = array_diff( array_keys( $found ), SecretMap::OPTIONS );
		$this->assertSame( array(), array_values( $missing ), '這些金鑰 option 沒列進 SecretMap' );
	}

	public function test_has(): void {
		$this->assertTrue( SecretMap::has( 'moksafowo_ecpay_hash_key' ) );
		$this->assertFalse( SecretMap::has( 'moksafowo_ecpay_merchant_id' ) );
	}
}
