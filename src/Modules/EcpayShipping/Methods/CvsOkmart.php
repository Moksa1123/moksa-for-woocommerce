<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Methods;

use Moksafowo\Modules\Shipping\Methods\AbstractCvsShippingMethod;

defined( 'ABSPATH' ) || exit;

final class CvsOkmart extends AbstractCvsShippingMethod {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'moksafowo_ecpay_shipping_cvs_okmart';
		$this->method_title       = __( 'ECPay — OK Mart pickup', 'moksa-for-woocommerce' );
		$this->method_description = __( 'ECPay ended OK Mart store-to-store shipping on 1 July 2026, so this method is no longer offered at checkout. Existing orders still display normally.', 'moksa-for-woocommerce' );
		parent::__construct( $instance_id );
	}

	public function carrier(): string {
		return 'ok';
	}

	public function carrier_label(): string {
		return __( 'OK', 'moksa-for-woocommerce' );
	}

	/** 綠界的 OK 只有店到店（OKMARTC2C），沒有 B2C；B2C 合約時不提供這個選項，以免下了單卻建不了物流單。 */
	public function logistics_sub_type(): string {
		return 'OKMARTC2C';
	}

	/**
	 * 綠界 OK 超商店到店（OKMARTC2C）2026-07-01 終止服務、規格已移除（developers.ecpay.com.tw/36099）。
	 * 結帳一律不提供：選了也選不了店、建不了物流單。類別保留註冊，運送區域設定與舊訂單照常顯示。
	 */
	public function is_available( $package ): bool {
		return false;
	}
}
