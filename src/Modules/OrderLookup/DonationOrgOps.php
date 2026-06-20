<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

use MoksaWeb\Mowc\Modules\Shared\Invoice\InvoiceChannels;

defined( 'ABSPATH' ) || exit;

/**
 * 新增電子發票「捐贈單位」(社福團體名稱 + 愛心碼)到啟用中發票模組的設定。
 * 影響前台結帳的捐贈下拉,故走人工確認關卡(確認摘要也讓使用者核對 AI 抓對名稱與愛心碼)。
 *
 * 設定格式:option <prefix>_donate_orgs,每行「名稱|愛心碼」(見 InvoiceChannels::donate_orgs)。
 */
final class DonationOrgOps {

	const CAP = 'manage_woocommerce';

	/** 愛心碼:3-7 碼數字,或 X + 2-6 碼數字(同 EcpayInvoice\Api\Helper)。 */
	private static function valid_code( string $code ): bool {
		return 1 === preg_match( '#^([xX][0-9]{2,6}|[0-9]{3,7})$#', $code );
	}

	/** 啟用中的發票模組(優先序);回 [provider, option_prefix] 或 null。 */
	private static function active_invoice(): ?array {
		foreach ( array( 'ecpay', 'ezpay', 'smilepay', 'paynow', 'amego' ) as $p ) {
			if ( 'yes' === get_option( 'moksafowo_' . $p . '_invoice_enabled', 'no' ) ) {
				return array( $p, 'moksafowo_' . $p . '_invoice' );
			}
		}
		return null;
	}

	/**
	 * @param mixed $args { name: string, code: string }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$name = is_array( $args ) && isset( $args['name'] ) ? trim( (string) $args['name'] ) : '';
		$code = is_array( $args ) && isset( $args['code'] ) ? trim( (string) $args['code'] ) : '';
		$code = (string) preg_replace( '/[^0-9xX]/', '', $code );

		if ( '' === $name ) {
			return new \WP_Error( 'moksafowo_ai_no_name', __( '請提供捐贈單位名稱。', 'mo-ectools' ) );
		}
		if ( str_contains( $name, '|' ) ) {
			return new \WP_Error( 'moksafowo_ai_bad_name', __( '單位名稱不可含「|」符號。', 'mo-ectools' ) );
		}
		if ( ! self::valid_code( $code ) ) {
			return new \WP_Error( 'moksafowo_ai_bad_code', __( '愛心碼格式不正確(應為 3-7 碼數字)。', 'mo-ectools' ) );
		}

		$active = self::active_invoice();
		if ( null === $active ) {
			return new \WP_Error( 'moksafowo_ai_no_invoice', __( '沒有啟用任何電子發票模組。', 'mo-ectools' ) );
		}
		[ $provider, $prefix ] = $active;

		$dupe = InvoiceChannels::donate_org_name( $prefix, $code );
		if ( '' !== $dupe ) {
			return new \WP_Error(
				'moksafowo_ai_dupe',
				sprintf(
					/* translators: 1: love code, 2: existing org name */
					__( '愛心碼 %1$s 已存在(%2$s)。', 'mo-ectools' ),
					$code,
					$dupe
				)
			);
		}

		return array(
			'prefix'  => $prefix,
			'name'    => $name,
			'code'    => $code,
			'summary' => sprintf(
				/* translators: 1: org name, 2: love code, 3: provider */
				__( '新增捐贈單位「%1$s」(愛心碼 %2$s)到 %3$s 電子發票的捐贈名單。', 'mo-ectools' ),
				$name,
				$code,
				$provider
			),
		);
	}

	/**
	 * @param array<string,mixed> $params prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$prefix = (string) ( $params['prefix'] ?? '' );
		$name   = (string) ( $params['name'] ?? '' );
		$code   = (string) ( $params['code'] ?? '' );
		if ( '' === $prefix || '' === $name || ! self::valid_code( $code ) ) {
			return new \WP_Error( 'moksafowo_ai_bad_input', __( '資料不完整,無法新增。', 'mo-ectools' ) );
		}

		$raw  = (string) get_option( $prefix . '_donate_orgs', '' );
		$line = $name . '|' . $code;
		$new  = '' === trim( $raw ) ? $line : rtrim( $raw, "\r\n" ) . "\n" . $line;
		update_option( $prefix . '_donate_orgs', $new );

		return sprintf(
			/* translators: 1: org name, 2: love code */
			__( '✅ 已新增捐贈單位「%1$s」(愛心碼 %2$s),結帳捐贈選單即可選用。', 'mo-ectools' ),
			$name,
			$code
		);
	}
}
