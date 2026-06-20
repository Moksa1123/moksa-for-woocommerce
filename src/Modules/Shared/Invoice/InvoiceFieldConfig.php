<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared\Invoice;

defined( 'ABSPATH' ) || exit;

/**
 * 單一發票 provider 在結帳欄位上的差異點 — 餵給 InvoiceCheckoutFields 共用 registrar。
 *
 * 其餘行為（欄位 markup / 驗證 regex / 協調優先序 / meta 對應）全部共用，
 * 只有這四項因 provider 而異。
 */
final class InvoiceFieldConfig {

	/**
	 * @param string        $provider_slug         寫入 Keys::INVOICE_PROVIDER 的值（ecpay / ezpay / ...）。
	 * @param string        $option_prefix         設定鍵前綴（moksafowo_ecpay_invoice 等）。
	 * @param string        $member_label          會員載具顯示名（空字串 → 「會員載具」）。
	 * @param callable|null $carrier_api_validator  載具真驗 callback（僅 ECPay 注入，其餘 null）。
	 */
	public function __construct(
		public readonly string $provider_slug,
		public readonly string $option_prefix,
		public readonly string $member_label = '',
		public readonly mixed $carrier_api_validator = null,
	) {}
}
