<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayShipping\Operations;

use MoksaWeb\Mowc\Modules\EcpayShipping\Api\Helper;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

final class PrintLabel {

	
	public static function build( \WC_Order $order, string $mode = '1' ): array {
		$records = CreateOrder::get_records( $order );
		if ( empty( $records ) ) {
			return [ 'ok' => false, 'message' => __( '此訂單尚未建立物流單，無法列印。', 'mo-ectools' ) ];
		}
		$latest = end( $records );
		return self::build_for_ids( [ (string) $latest['id'] ], (string) $latest['subtype'], $order, $mode );
	}

	
	public static function build_for_ids( array $logistics_ids, string $subtype, ?\WC_Order $order_for_audit = null, string $mode = '1' ): array {
		$logistics_ids = array_values( array_filter( array_map( 'strval', $logistics_ids ) ) );
		if ( empty( $logistics_ids ) ) {
			return [ 'ok' => false, 'message' => __( '沒有可列印的物流單。', 'mo-ectools' ) ];
		}
		if ( '' === $subtype ) {
			return [ 'ok' => false, 'message' => __( '物流型別缺漏。', 'mo-ectools' ) ];
		}

		// browser auto-submit form 不能直接打 ECPay V2（要 JSON body + AES）
		// → 改 form 打到我們 admin-post proxy，由 proxy 組 envelope 後 server-to-server 打 ECPay
		// → 把 ECPay 回的 label HTML 直接 echo 給 browser 顯示
		$payload = [
			'action'        => 'moksafowo_ecpay_shipping_print_v2',
			'_wpnonce'      => wp_create_nonce( PrintProxy::nonce_action() ),
			'logistics_ids' => implode( ',', $logistics_ids ),
			'subtype'       => $subtype,
			'mode'          => '2' === $mode ? '2' : '1',  // 1=A4，2=A6
		];

		if ( $order_for_audit instanceof \WC_Order ) {
			$order_for_audit->update_meta_data( Keys::SHIPPING_LABEL_PROVIDER, 'ecpay' );
			$order_for_audit->update_meta_data( Keys::SHIPPING_LABEL_NUMBER, implode( ',', $logistics_ids ) );
			$order_for_audit->update_meta_data( Keys::SHIPPING_LABEL_PRINTED_AT, current_time( 'mysql' ) );
			$order_for_audit->save();
		}

		return [
			'ok'        => true,
			'message'   => 'ok',
			'api_url'   => admin_url( 'admin-post.php' ),
			'form_data' => $payload,
		];
	}
}
