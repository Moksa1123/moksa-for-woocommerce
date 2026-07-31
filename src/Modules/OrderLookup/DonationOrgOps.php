<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

use Moksafowo\Modules\Shared\Invoice\InvoiceChannels;

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
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$name = is_array( $args ) && isset( $args['name'] ) ? trim( (string) $args['name'] ) : '';
		$code = is_array( $args ) && isset( $args['code'] ) ? trim( (string) $args['code'] ) : '';
		$code = (string) preg_replace( '/[^0-9xX]/', '', $code );

		if ( '' === $name ) {
			return new \WP_Error( 'moksafowo_ai_no_name', __( 'Please provide the charity name.', 'moksa-for-woocommerce' ) );
		}
		if ( str_contains( $name, '|' ) ) {
			return new \WP_Error( 'moksafowo_ai_bad_name', __( 'The charity name cannot contain the “|” character.', 'moksa-for-woocommerce' ) );
		}
		if ( ! self::valid_code( $code ) ) {
			return new \WP_Error( 'moksafowo_ai_bad_code', __( 'The love code is not valid; it must be 3 to 7 digits.', 'moksa-for-woocommerce' ) );
		}

		$active = self::active_invoice();
		if ( null === $active ) {
			return new \WP_Error( 'moksafowo_ai_no_invoice', __( 'No e-invoice module is enabled.', 'moksa-for-woocommerce' ) );
		}
		[ $provider, $prefix ] = $active;

		$dupe = InvoiceChannels::donate_org_name( $prefix, $code );
		if ( '' !== $dupe ) {
			return new \WP_Error(
				'moksafowo_ai_dupe',
				sprintf(
					/* translators: 1: love code, 2: existing org name */
					__( 'Love code %1$s already exists (%2$s).', 'moksa-for-woocommerce' ),
					$code,
					$dupe
				)
			);
		}

		return array(
			'provider' => $provider,
			'name'     => $name,
			'code'     => $code,
			'summary'  => sprintf(
				/* translators: 1: org name, 2: love code, 3: provider */
				__( 'Add the charity “%1$s” with love code %2$s to the %3$s e-invoice donation list.', 'moksa-for-woocommerce' ),
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
			return new \WP_Error( 'moksafowo_ai_cap', __( 'This action requires the Manage WooCommerce permission.', 'moksa-for-woocommerce' ) );
		}
		$provider = (string) ( $params['provider'] ?? '' );
		$name     = (string) ( $params['name'] ?? '' );
		$code     = (string) ( $params['code'] ?? '' );
		// provider 白名單:option 名一律 moksafowo_<provider>_invoice_donate_orgs（字面前綴,不接受任意值）。
		if ( ! in_array( $provider, array( 'ecpay', 'ezpay', 'smilepay', 'paynow', 'amego' ), true ) || '' === $name || ! self::valid_code( $code ) ) {
			return new \WP_Error( 'moksafowo_ai_bad_input', __( 'The details are incomplete, so nothing was added.', 'moksa-for-woocommerce' ) );
		}

		$option = 'moksafowo_' . $provider . '_invoice_donate_orgs';
		$raw    = (string) get_option( $option, '' );
		$line   = $name . '|' . $code;
		$new    = '' === trim( $raw ) ? $line : rtrim( $raw, "\r\n" ) . "\n" . $line;
		update_option( $option, $new );

		return sprintf(
			/* translators: 1: org name, 2: love code */
			__( '✅ The charity “%1$s” with love code %2$s was added and can now be chosen from the donation list at checkout.', 'moksa-for-woocommerce' ),
			$name,
			$code
		);
	}
}
