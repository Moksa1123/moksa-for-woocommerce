<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared\Email;

use MoksaWeb\Mowc\Modules\Shared\Frontend\PaymentInfoBox;
use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

// 取號繳費通知 email guard — 4 個 rows-based IPN handler 共用（Ecpay 走 rtn_code-based 不走這）
final class PaymentInfoEmailDispatcher {

	public static function maybe_dispatch( \WC_Order $order ): void {
		if ( empty( PaymentInfoBox::rows( $order ) ) ) {
			return;
		}
		if ( $order->get_meta( Keys::PAYMENT_INFO_EMAIL_SENT ) ) {
			return;
		}
		$order->update_meta_data( Keys::PAYMENT_INFO_EMAIL_SENT, '1' );
		$order->save();
		do_action( 'moksafowo_payment_info_email', $order->get_id() );
	}
}
