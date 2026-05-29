<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PaynowInvoice\Api;

defined( 'ABSPATH' ) || exit;


final class Request {

	
	public static function upload_invoice_patch( string $mem_cid, string $mem_password, string $csv_b64 ): array {
		$resp = self::post( '/UploadInvoice_Patch', [
			'mem_cid'      => $mem_cid,
			'mem_password' => $mem_password,
			'csvStr'       => $csv_b64,
		] );
		if ( ! $resp['ok'] ) {
			return [ 'ok' => false, 'message' => $resp['message'], 'raw' => $resp['raw'], 'count' => 0, 'items' => [] ];
		}

		// returnStr 格式：S_<count>_<orderno>_<invoiceNo>,_<orderno>_<invoiceNo>,...
		// 失敗：F_xxx 或非 S 開頭
		$raw   = trim( $resp['body'] );
		$parts = explode( '_', $raw, 3 );
		$flag  = $parts[0] ?? '';
		if ( 'S' !== $flag ) {
			return [ 'ok' => false, 'message' => $raw, 'raw' => $raw, 'count' => 0, 'items' => [] ];
		}

		$count = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$rest  = $parts[2] ?? '';
		// rest e.g. "20170630001_AA12345678,20170630002_AA12345679"
		$items = '' === $rest ? [] : explode( ',', $rest );
		return [ 'ok' => true, 'message' => 'OK', 'raw' => $raw, 'count' => $count, 'items' => $items ];
	}

	
	public static function cancel_invoice( string $mem_cid, string $mem_password, string $invoice_no ): array {
		$resp = self::post( '/CancelInvoice_I', [
			'mem_cid'      => $mem_cid,
			'mem_password' => $mem_password,
			'InvoiceNo'    => $invoice_no,
		] );
		if ( ! $resp['ok'] ) {
			return [ 'ok' => false, 'message' => $resp['message'], 'raw' => $resp['raw'] ];
		}
		$raw  = trim( $resp['body'] );
		$flag = substr( $raw, 0, 1 );
		if ( 'S' !== $flag ) {
			return [ 'ok' => false, 'message' => $raw, 'raw' => $raw ];
		}
		return [ 'ok' => true, 'message' => 'OK', 'raw' => $raw ];
	}

	
	private static function post( string $method_path, array $body ): array {
		$url  = Helper::endpoint() . $method_path;
		$resp = wp_remote_post( $url, [
			'timeout'   => 30,
			'sslverify' => true,
			'headers'   => [
				'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
			],
			'body'      => $body,
		] );
		if ( is_wp_error( $resp ) ) {
			return [ 'ok' => false, 'message' => $resp->get_error_message(), 'raw' => '', 'body' => '' ];
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$bdy  = (string) wp_remote_retrieve_body( $resp );
		if ( 200 !== $code ) {
			return [ 'ok' => false, 'message' => "HTTP $code", 'raw' => $bdy, 'body' => $bdy ];
		}
		// ASMX 回 XML wrapped string — 抓 <string>...</string> 內容
		if ( preg_match( '#<string[^>]*>(.*?)</string>#s', $bdy, $m ) ) {
			$bdy = $m[1];
		}
		return [ 'ok' => true, 'message' => 'OK', 'raw' => $bdy, 'body' => $bdy ];
	}
}
