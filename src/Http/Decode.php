<?php

declare( strict_types=1 );

namespace Moksafowo\Http;

defined( 'ABSPATH' ) || exit;

/**
 * 解析金流商回傳的 XML／十六進位字串。格式壞掉時回 false，不丟 PHP 警告：
 * libxml 的錯誤收進內部緩衝後清掉，不用 @ 抑制。
 */
final class Decode {

	/** @return \SimpleXMLElement|false */
	public static function xml( string $xml ) {
		if ( '' === $xml ) {
			return false;
		}
		$previous = libxml_use_internal_errors( true );
		$result   = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $result;
	}

	public static function xml_dom( \DOMDocument $doc, string $xml ): bool {
		if ( '' === $xml ) {
			return false;
		}
		$previous = libxml_use_internal_errors( true );
		$loaded   = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return (bool) $loaded;
	}

	/** 合法的十六進位字串才轉二進位，其餘回 false（hex2bin 對奇數長度或非 hex 字元會丟警告）。 */
	public static function hex( string $hex ) {
		if ( '' === $hex || 0 !== strlen( $hex ) % 2 || ! ctype_xdigit( $hex ) ) {
			return false;
		}
		return hex2bin( $hex );
	}

	private function __construct() {}
}
