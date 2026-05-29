<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shipping\Emails;

defined( 'ABSPATH' ) || exit;

final class EmailCvsArrived extends AbstractShippingEmail {

	public function __construct() {
		$this->title       = __( '物流：包裹到店通知', 'mo-ectools' );
		$this->description = __( '包裹送達超商門市時寄給顧客（含店名 + 代號 + 取件期限）。', 'mo-ectools' );
		parent::__construct();
	}

	protected function get_status_slug(): string {
		return 'mo-cvs-arrived';
	}

	public function get_default_subject(): string {
		return __( '您的包裹已送達 {store_name}（訂單 #{order_number}）', 'mo-ectools' );
	}

	public function get_default_heading(): string {
		return __( '包裹已到店待取', 'mo-ectools' );
	}
}
