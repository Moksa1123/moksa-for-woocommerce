<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 外掛設定彙整(唯讀)。回傳商家常問的非敏感設定:哪些金流/物流/發票管道開著、
 * 各模組測試/正式模式、發票開立時機、訂單查號可搜尋欄位、AI 助手是否啟用。
 *
 * 安全:**絕不回傳任何憑證**(merchant_id / hash_key / hash_iv / secret / api_key 等)。
 */
final class PluginSettings {

	const CAP = 'manage_woocommerce';

	/**
	 * @param mixed $input 未使用。
	 * @return array<string,mixed>
	 */
	public static function execute( $input ): array {
		if ( ! current_user_can( self::CAP ) ) {
			return array();
		}

		$channels = ChannelOps::list_channels( array( 'category' => 'all' ) )['channels'] ?? array();
		$rows     = array();
		foreach ( $channels as $c ) {
			$slug   = (string) $c['slug'];
			$rows[] = array(
				'slug'     => $slug,
				'name'     => (string) $c['name'],
				'category' => (string) $c['category'],
				'enabled'  => (bool) $c['enabled'],
				'sandbox'  => 'yes' === get_option( 'moksafowo_' . $slug . '_sandbox_enabled', 'no' ),
			);
		}

		$invoice_timing = array();
		foreach ( array( 'ecpay', 'ezpay', 'smilepay', 'paynow', 'amego' ) as $p ) {
			if ( 'yes' === get_option( 'moksafowo_' . $p . '_invoice_enabled', 'no' ) ) {
				$invoice_timing[ $p ] = (string) get_option( 'moksafowo_' . $p . '_invoice_issue_when', 'paid' );
			}
		}

		$search_fields = array();
		foreach ( array_keys( SearchableKeys::field_defaults() ) as $field ) {
			$search_fields[ $field ] = SearchableKeys::field_on( $field );
		}

		return array(
			'channels'             => $rows,
			'invoice_issue_timing' => $invoice_timing,
			'order_search_fields'  => $search_fields,
			'ai_assistant_enabled' => 'yes' === get_option( 'moksafowo_ai_assistant_enabled', 'no' ),
			'note'                 => __( '此處不含任何憑證(MerchantID/HashKey 等)。', 'mo-ectools' ),
		);
	}
}
