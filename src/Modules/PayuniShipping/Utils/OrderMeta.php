<?php

namespace Moksafowo\Modules\PayuniShipping\Utils;

defined( 'ABSPATH' ) || exit;

class OrderMeta {

	const StoreId   = '_moksafowo_payuni_shipping_store_id';
	const StoreName = '_moksafowo_payuni_shipping_store_name';
	const StoreAddr = '_moksafowo_payuni_shipping_store_addr';

	const STORE_DATA_JSON = '_moksafowo_payuni_shipping_store_data'; // 主要 JSON 格式

	const ShipNo      = '_moksafowo_payuni_shipping_sno'; // 物流單查詢編號(Seven=Odno+ValidationNo, 黑貓=OBTNumber)
	const GoodsType   = '_moksafowo_payuni_shipping_goods_type';// 溫層.
	const LgsType     = '_moksafowo_payuni_shipping_lgs_type';// 溫送方式.
	const ShipType    = '_moksafowo_payuni_shipping_ship_type';// 通路. 1:seven, 2:黑貓
	const ShipTradeNo = '_moksafowo_payuni_shipping_trade_no'; // UNi 物流序號.
	const Odno        = '_moksafowo_payuni_shipping_odno'; // 物流商出貨編號(8碼).
	const TradeAmt    = '_moksafowo_payuni_shipping_trade_amt'; // 訂單金額 OR 貨到付款金額.
	const ServiceType = '_moksafowo_payuni_shipping_service_type'; // 物流服務類型. 1=取貨付款，3=取貨不付款

	const FileNo       = '_moksafowo_payuni_shipping_file_no'; // 黑貓標籤檔案編號.
	const PrintDate    = '_moksafowo_payuni_shipping_print_date'; // 標籤列印日期.
	const PartnerId    = '_moksafowo_payuni_shipping_partnerId';
	const ValidationNo = '_moksafowo_payuni_shipping_validation_no'; // 物流商驗證碼如需使用 IboN 列印請搭配 paymentno 使用.
	const PackageSpec  = '_moksafowo_payuni_shipping_package_spec'; // 黑貓包裹規格.

	const ShipStatus     = '_moksafowo_payuni_shipping_ship_status'; // 物流狀態.
	const ShipStatusDesc = '_moksafowo_payuni_shipping_ship_status_desc'; // 物流狀態描述.
	const ShipStatusTime = '_moksafowo_payuni_shipping_ship_status_time'; // 物流狀態時間.
}
