<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayInvoice\Operations;

use Moksafowo\Modules\EcpayInvoice\Api\Helper;
use Moksafowo\Modules\Shared\Compat\LegacyOrderData;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 舊外掛發票的開立日期。綠界的作廢、折讓都要帶正確的 InvoiceDate（日期錯或空白一律「查無發票」），
 * 但 woomp 沒存：先看訂單備註，再用「查詢特定多筆發票」（B2CInvoice/GetIssueList，官方 /17229）
 * 在訂單建立後的區間找同號碼。找到就存進 Moksa 自己的 meta，舊外掛的資料不改。
 */
final class LegacyInvoiceDate {

	/** 往後找幾天（woomp 手動開立，可能隔很久才開）。 */
	private const SEARCH_DAYS = 120;

	/** 每頁筆數（官方上限 200）與最多翻幾頁。 */
	private const PER_PAGE  = 200;
	private const MAX_PAGES = 10;

	/**
	 * @param \WC_Order                                               $order  訂單。
	 * @param array{number: string, date: string, source_key: string} $legacy LegacyOrderData::invoice() 的回傳。
	 * @return string Y-m-d；查不到回空字串。
	 */
	public static function resolve( \WC_Order $order, array $legacy ): string {
		$date = substr( (string) $legacy['date'], 0, 10 );
		if ( self::valid( $date ) ) {
			return $date;
		}
		// 備註取到的日期每次重新解析、不存；只有綠界查回來的才存（日期來自綠界本身）。
		$noted = 'woomp' === $legacy['source_key'] ? LegacyOrderData::woomp_issue_date( $order, $legacy['number'] ) : '';
		if ( '' !== $noted ) {
			return substr( $noted, 0, 10 );
		}
		$found = self::search( $order, $legacy['number'] );
		if ( '' === $found ) {
			return '';
		}
		$order->update_meta_data( Keys::LEGACY_INVOICE_DATE, $found );
		$order->save();
		return substr( $found, 0, 10 );
	}

	private static function valid( string $date ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	private static function search( \WC_Order $order, string $number ): string {
		$created = $order->get_date_created();
		if ( ! $created || ! preg_match( '/^[A-Z]{2}\d{8}$/', $number ) ) {
			return '';
		}
		$begin = $created->date( 'Y-m-d' );
		$end   = min( current_time( 'Y-m-d' ), gmdate( 'Y-m-d', $created->getTimestamp() + self::SEARCH_DAYS * DAY_IN_SECONDS ) );
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$result = Helper::post(
				'/B2CInvoice/GetIssueList',
				[
					'MerchantID'  => Helper::merchant_id(),
					'BeginDate'   => $begin,
					'EndDate'     => $end,
					'NumPerPage'  => self::PER_PAGE,
					'ShowingPage' => $page,
					'DataType'    => 1,
				]
			);
			$rows   = (array) ( $result['data']['InvoiceData'] ?? [] );
			if ( ! $result['ok'] || ! $rows ) {
				return '';
			}
			foreach ( $rows as $row ) {
				$row = map_deep( (array) $row, 'sanitize_text_field' );
				if ( ( $row['IIS_Number'] ?? '' ) === $number ) {
					$at = (string) ( $row['IIS_Create_Date'] ?? '' );
					return preg_match( '/^\d{4}-\d{2}-\d{2}/', $at ) ? $at : '';
				}
			}
			if ( $page * self::PER_PAGE >= (int) ( $result['data']['TotalCount'] ?? 0 ) ) {
				return '';
			}
		}
		return '';
	}
}
