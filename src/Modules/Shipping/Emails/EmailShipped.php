<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Emails;

defined( 'ABSPATH' ) || exit;

final class EmailShipped extends AbstractShippingEmail {

	public function __construct() {
		$this->title       = __( '物流：已出貨通知', 'mo-ectools' );
		$this->description = __( '訂單狀態切到「已出貨」時寄給顧客。', 'mo-ectools' );
		parent::__construct();
	}

	protected function get_status_slug(): string {
		return 'moksa-shipped';
	}

	public function get_default_subject(): string {
		return __( '您在 {site_title} 的訂單 #{order_number} 已出貨', 'mo-ectools' );
	}

	public function get_default_heading(): string {
		return __( '訂單已出貨', 'mo-ectools' );
	}
}
