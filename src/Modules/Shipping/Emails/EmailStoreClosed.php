<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Emails;

defined( 'ABSPATH' ) || exit;

final class EmailStoreClosed extends AbstractShippingEmail {

	public function __construct() {
		$this->title       = __( '物流：門市關轉通知', 'moksa-for-woocommerce' );
		$this->description = __( '取件 / 退貨門市暫歇，催顧客重選門市的緊急通知。', 'moksa-for-woocommerce' );
		parent::__construct();
	}

	protected function get_status_slug(): string {
		return 'moksa-store-closed';
	}

	public function get_default_subject(): string {
		return __( '【重要】請重新選擇取件門市（訂單 #{order_number}）', 'moksa-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( '門市關轉，請重新選擇取件門市', 'moksa-for-woocommerce' );
	}
}
