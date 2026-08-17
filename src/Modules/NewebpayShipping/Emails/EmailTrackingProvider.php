<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\NewebpayShipping\Emails;

use Moksafowo\Modules\NewebpayShipping\Module;
use Moksafowo\Modules\Shipping\Tracking\TrackingLink;
use Moksafowo\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 把藍新的物流編號送進出貨通知信。
 *
 * 綠界／速買配／統一金流都有這一層，只有藍新沒有 —— 顧客收到出貨通知卻看不到
 * 單號，只能來問客服。這裡補齊。
 *
 * 藍新的物流資料是平鋪在 order meta（沒有 records 陣列），所以直接讀 meta 組一筆。
 */
final class EmailTrackingProvider {

	public static function init(): void {
		add_filter( 'moksafowo_shipping_tracking_entries', [ __CLASS__, 'maybe_provide' ], 10, 3 );
	}

	/**
	 * @param array<int,array<string,string>> $entries   其他物流商已提供的條目。
	 * @param \WC_Order                       $order     訂單。
	 * @param string                          $method_id 該訂單的運送方式 id。
	 * @return array<int,array<string,string>>
	 */
	public static function maybe_provide( array $entries, \WC_Order $order, string $method_id ): array {
		if ( ! isset( Module::method_map()[ $method_id ] ) ) {
			return $entries;
		}

		$info = TrackingLink::for_newebpay_record(
			[
				'ship_type' => (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_SHIP_TYPE ),
				'lgs_no'    => (string) $order->get_meta( Keys::NEWEBPAY_SHIPPING_LGS_NO ),
			]
		);
		if ( null === $info ) {
			return $entries;
		}

		$entries[] = [
			'carrier'     => (string) $info['carrier'],
			'tracking_no' => (string) $info['tracking_no'],
			'url'         => (string) $info['url'],
			'mode'        => (string) $info['mode'],
			'temp_label'  => '', // 藍新物流沒有溫層分類
		];
		return $entries;
	}
}
