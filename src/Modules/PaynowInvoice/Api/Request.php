<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PaynowInvoice\Api;

defined( 'ABSPATH' ) || exit;

/**
 * PayNowEInvoice.asmx 只接受 SOAP（WSDL 只有 Soap／Soap12 binding），表單 POST 會回 500。
 */
final class Request {

	private const SOAP_NS = 'https://invoice.PayNow.com.tw/';

	public static function upload_invoice_patch( string $mem_cid, string $mem_password, string $csv_b64 ): array {
		$resp = self::call(
			'UploadInvoice_Patch',
			[
				'mem_cid'      => $mem_cid,
				'mem_password' => $mem_password,
				'csvStr'       => $csv_b64,
			]
		);
		if ( ! $resp['ok'] ) {
			return [
				'ok'      => false,
				'message' => $resp['message'],
				'raw'     => $resp['raw'],
				'count'   => 0,
				'items'   => [],
			];
		}

		// 成功：S_<筆數>,<orderno>_<發票號碼>,<orderno>_<發票號碼>…；失敗：錯誤訊息
		$raw    = trim( $resp['body'] );
		$chunks = explode( ',', $raw );
		$head   = explode( '_', (string) array_shift( $chunks ), 2 );
		if ( 'S' !== $head[0] ) {
			return [
				'ok'      => false,
				'message' => $raw,
				'raw'     => $raw,
				'count'   => 0,
				'items'   => [],
			];
		}

		return [
			'ok'      => true,
			'message' => 'OK',
			'raw'     => $raw,
			'count'   => (int) ( $head[1] ?? 0 ),
			'items'   => array_values( array_filter( array_map( 'trim', $chunks ), 'strlen' ) ),
		];
	}


	/** CancelInvoice_I 只收 mem_cid、InvoiceNo（WSDL），不帶密碼。 */
	public static function cancel_invoice( string $mem_cid, string $mem_password, string $invoice_no ): array {
		unset( $mem_password );
		$resp = self::call(
			'CancelInvoice_I',
			[
				'mem_cid'   => $mem_cid,
				'InvoiceNo' => $invoice_no,
			]
		);
		if ( ! $resp['ok'] ) {
			return [
				'ok'      => false,
				'message' => $resp['message'],
				'raw'     => $resp['raw'],
			];
		}
		// 成功：S；失敗：F_<錯誤訊息>
		$raw = trim( $resp['body'] );
		if ( 'S' !== $raw ) {
			return [
				'ok'      => false,
				'message' => str_starts_with( $raw, 'F_' ) ? substr( $raw, 2 ) : $raw,
				'raw'     => $raw,
			];
		}
		return [
			'ok'      => true,
			'message' => 'OK',
			'raw'     => $raw,
		];
	}


	/**
	 * 查發票狀態（Invoice_Info）：開立,800／作廢／折讓,總額,未確認折讓,已確認折讓／查無發票資訊。
	 */
	public static function select_invoice( string $mem_cid, string $mem_password, string $invoice_no ): array {
		unset( $mem_password );
		$resp = self::call(
			'Invoice_Info',
			[
				'mem_cid'   => $mem_cid,
				'InvoiceNo' => $invoice_no,
			]
		);
		if ( ! $resp['ok'] ) {
			return [
				'ok'      => false,
				'message' => $resp['message'],
				'raw'     => $resp['raw'],
			];
		}
		$raw   = trim( $resp['body'] );
		$parts = array_map( 'trim', explode( ',', $raw ) );
		if ( ! in_array( $parts[0], [ '開立', '作廢', '折讓' ], true ) ) {
			return [
				'ok'      => false,
				'message' => $raw,
				'raw'     => $raw,
			];
		}
		return [
			'ok'      => true,
			'message' => 'OK',
			'raw'     => $raw,
			'parts'   => $parts,
		];
	}

	private static function call( string $method, array $params ): array {
		$fields = '';
		foreach ( $params as $name => $value ) {
			$fields .= '<' . $name . '>' . htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</' . $name . '>';
		}
		$envelope = '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Body><' . $method . ' xmlns="' . self::SOAP_NS . '">' . $fields . '</' . $method . '></soap:Body></soap:Envelope>';

		$resp = wp_remote_post(
			Helper::endpoint(),
			[
				'timeout'   => 30,
				'sslverify' => true,
				'headers'   => [
					'Content-Type' => 'text/xml; charset=utf-8',
					'SOAPAction'   => '"' . self::SOAP_NS . $method . '"',
				],
				'body'      => $envelope,
			]
		);
		if ( is_wp_error( $resp ) ) {
			return [
				'ok'      => false,
				'message' => $resp->get_error_message(),
				'raw'     => '',
				'body'    => '',
			];
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$xml  = (string) wp_remote_retrieve_body( $resp );

		$doc = new \DOMDocument();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- remote XML; a parse failure returns false and is handled below.
		$loaded = '' !== $xml && @$doc->loadXML( $xml, LIBXML_NONET );
		if ( ! $loaded ) {
			return [
				'ok'      => false,
				'message' => "HTTP $code",
				'raw'     => substr( $xml, 0, 500 ),
				'body'    => '',
			];
		}
		$result = $doc->getElementsByTagNameNS( self::SOAP_NS, $method . 'Result' )->item( 0 );
		if ( 200 !== $code || null === $result ) {
			$fault = $doc->getElementsByTagName( 'faultstring' )->item( 0 );
			return [
				'ok'      => false,
				'message' => null !== $fault ? trim( $fault->textContent ) : "HTTP $code",
				'raw'     => substr( $xml, 0, 500 ),
				'body'    => '',
			];
		}
		return [
			'ok'      => true,
			'message' => 'OK',
			'raw'     => $result->textContent,
			'body'    => $result->textContent,
		];
	}
}
