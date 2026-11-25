<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Returns;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;

defined( 'ABSPATH' ) || exit;

/**
 * 統一金流退貨 API：7-11 退貨便要號、黑貓退貨單、退貨查詢。
 *
 * 這兩支建立 API 要商店先向統一金流申請「物流幕後 API」才能用；沒開通時回 DEF05004／HOME01064。
 * 回應一律先驗 HashInfo 再解密。
 */
final class Api {

	/** 錯誤碼：請求可能已經送到統一金流（逾時、回應無法辨識或驗不過、成功卻缺欄位），不確定有沒有建立。 */
	public const UNCERTAIN = 'moksafowo_payuni_return_uncertain';

	/**
	 * 常見錯誤碼對成商家看得懂的說明；其他錯誤顯示統一金流的原文。
	 *
	 * @return array<string, string>
	 */
	private static function error_messages(): array {
		return [
			'DEF05004'  => __( 'This PAYUNi store has not been enabled for returns yet. Ask PAYUNi to enable the back-end logistics API (7-ELEVEN returns also need merchant shipping).', 'moksa-for-woocommerce' ),
			'HOME01064' => __( 'This PAYUNi store has not been enabled for T-Cat returns yet. Ask PAYUNi to enable back-end home delivery.', 'moksa-for-woocommerce' ),
			'LGR04004'  => __( 'PAYUNi could not find the original shipment for this return.', 'moksa-for-woocommerce' ),
			'HOME01127' => __( 'The original T-Cat shipment is past the return period.', 'moksa-for-woocommerce' ),
			'HOME01128' => __( 'The original T-Cat shipment cannot be returned in its current status.', 'moksa-for-woocommerce' ),
			'HOME01029' => __( 'PAYUNi could not verify the postcode of the address. Check the address and try again.', 'moksa-for-woocommerce' ),
		];
	}

	/**
	 * 建立 7-11 退貨便。
	 *
	 * @param array{service_type:int, trade_amt:int, ship_amt:int, shopper_name:string, ship_trade_no:string} $args 已檢查過的欄位。
	 * @return array<string, string>|\WP_Error 解密後的回應。
	 */
	public static function create_c2b( array $args ) {
		$info = [
			'MerID'       => PayuniShipping::get_mer_id(),
			'Timestamp'   => time(),
			'GoodsType'   => 1,
			'LgsType'     => 'C2B',
			'ShipType'    => 1,
			'TradeAmt'    => $args['trade_amt'],
			'ServiceType' => $args['service_type'],
			'ShipAmt'     => $args['ship_amt'],
			'ProcessType' => 1,
		];
		if ( '' !== $args['ship_trade_no'] ) {
			$info['ShipTradeNo'] = $args['ship_trade_no'];
		}
		if ( '' !== $args['shopper_name'] ) {
			$info['ShopperName'] = $args['shopper_name'];
		}
		$resp = self::post( '/logistics/refund', $info, '1.0' );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		if ( '' === (string) ( $resp['RefundODNO'] ?? '' ) || '' === (string) ( $resp['ValidationNo'] ?? '' ) ) {
			return new \WP_Error( self::UNCERTAIN, __( 'PAYUNi reported success but did not return a return code.', 'moksa-for-woocommerce' ) );
		}
		return $resp;
	}

	/**
	 * 建立黑貓退貨單。
	 *
	 * @param array<string, string|int> $fields EncryptInfo 欄位（不含 MerID、Timestamp）。
	 * @return array<string, string>|\WP_Error
	 */
	public static function create_home( array $fields ) {
		$info = array_merge(
			[
				'MerID'     => PayuniShipping::get_mer_id(),
				'Timestamp' => time(),
				'LgsType'   => 'HOME',
				'ShipType'  => 2,
			],
			$fields
		);
		$resp = self::post( '/home_delivery/refund', $info, '1.0' );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		if ( '' === (string) ( $resp['ShipTradeNo'] ?? '' ) ) {
			return new \WP_Error( self::UNCERTAIN, __( 'PAYUNi reported success but did not return a shipment number.', 'moksa-for-woocommerce' ) );
		}
		return $resp;
	}

	/**
	 * 查一筆退貨的貨態。
	 *
	 * @param array<string, mixed> $record Store 的紀錄。
	 * @return array<string, string>|\WP_Error
	 */
	public static function query( array $record ) {
		$info = [
			'MerID'     => PayuniShipping::get_mer_id(),
			'Timestamp' => time(),
		];
		if ( Store::TYPE_C2B === ( $record['type'] ?? '' ) ) {
			$info['LgsType']    = 'C2B';
			$info['ReturnOdno'] = (string) ( $record['ref'] ?? '' );
		} else {
			$info['LgsType']     = 'HOME';
			$info['TradeType']   = 2;
			$info['ShipTradeNo'] = (string) ( $record['ref'] ?? '' );
		}
		return self::post( '/logistics/query', $info, '1.1' );
	}

	/**
	 * @param string               $path    API 路徑。
	 * @param array<string, mixed> $info    EncryptInfo 欄位。
	 * @param string               $version API 版本。
	 * @return array<string, string>|\WP_Error
	 */
	private static function post( string $path, array $info, string $version ) {
		if ( '' === (string) PayuniShipping::get_mer_id() ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'The PAYUNi merchant ID is not set.', 'moksa-for-woocommerce' ) );
		}
		try {
			$encrypted = PayuniShipping::encrypt( $info );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'The request to PAYUNi could not be encrypted. Check the PAYUNi credentials.', 'moksa-for-woocommerce' ) );
		}
		$response = wp_remote_post(
			PayuniShipping::$api_url . $path,
			[
				'timeout' => 45,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent'   => 'payuni',
				],
				'body'    => [
					'MerID'       => PayuniShipping::get_mer_id(),
					'Version'     => $version,
					'EncryptInfo' => $encrypted,
					'HashInfo'    => PayuniShipping::hash_info( $encrypted ),
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			PayuniShipping::log( 'PAYUNi return API transport error: ' . $path . ' ' . $response->get_error_message() );
			return new \WP_Error( self::UNCERTAIN, __( 'Could not get a reply from PAYUNi.', 'moksa-for-woocommerce' ) );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return new \WP_Error( self::UNCERTAIN, __( 'PAYUNi returned an unexpected response.', 'moksa-for-woocommerce' ) );
		}
		$json         = map_deep( $json, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
		$encrypt_info = (string) ( $json['EncryptInfo'] ?? '' );
		$hash_info    = (string) ( $json['HashInfo'] ?? '' );
		if ( '' === $encrypt_info ) {
			return self::error( (string) ( $json['Status'] ?? '' ), (string) ( $json['Message'] ?? '' ), $path );
		}
		if ( '' === $hash_info || ! hash_equals( PayuniShipping::hash_info( $encrypt_info ), strtoupper( $hash_info ) ) ) {
			PayuniShipping::log( 'PAYUNi return API response HashInfo mismatch: ' . $path );
			return new \WP_Error( self::UNCERTAIN, __( 'The PAYUNi response could not be verified.', 'moksa-for-woocommerce' ) );
		}
		$decrypted = PayuniShipping::decrypt( $encrypt_info );
		$decrypted = is_array( $decrypted ) ? map_deep( $decrypted, static fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v ) : [];
		$status    = (string) ( $decrypted['Status'] ?? '' );
		PayuniShipping::log( 'PAYUNi return API ' . $path . ' status=' . $status );
		if ( 'SUCCESS' !== $status ) {
			return self::error( $status, (string) ( $decrypted['Message'] ?? '' ), $path );
		}
		return array_map( 'strval', array_filter( $decrypted, 'is_scalar' ) );
	}

	private static function error( string $code, string $message, string $path ): \WP_Error {
		PayuniShipping::log( 'PAYUNi return API ' . $path . ' failed: ' . $code );
		$known = self::error_messages();
		$text  = $known[ $code ] ?? ( '' !== $message ? $message : __( 'PAYUNi did not accept the request.', 'moksa-for-woocommerce' ) );
		return new \WP_Error(
			'moksafowo_payuni_return',
			'' !== $code ? $text . ' (' . $code . ')' : $text
		);
	}
}
