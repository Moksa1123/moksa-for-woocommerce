=== Moksa for WooCommerce ===
Contributors: moksa0923
Tags: woocommerce, taiwan, payment, shipping, invoice
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 10.7

A Taiwan e-commerce toolkit for WooCommerce. Bundles Taiwanese payment, shipping and e-invoice integrations.

== Description ==

> 難用版台灣電商外掛，非常難用謹慎安裝。結合多家金流物流電子發票，但哪幾間請自行安裝才知道。😉 （以上純屬玩笑，其實很好用 👇）

A Taiwan-focused WooCommerce extension. Toggleable modules cover ECPay (綠界), NewebPay (藍新), SmilePay (速買配), LINE Pay, PAYUNi (統一金流), PayNow (立即富), PChomePay (支付連), TapPay and Shopline Payments for payments; ECPay, NewebPay, SmilePay, PAYUNi and PayNow for convenience-store + home-delivery shipping; ezPay, ECPay, SmilePay, PayNow and AMEGO for Taiwan e-invoicing.

Enable only the providers you need from a single settings page — payment, shipping and invoice modules are fully independent and can be mixed in any combination.

HPOS-native, Block Checkout-native, PHP 8.2+ strict-typed, GPLv3, no premium gating.

= Source =

Source code and issue tracker: [github.com/Moksa1123/moksa-for-woocommerce](https://github.com/Moksa1123/moksa-for-woocommerce).

== External Services ==

This plugin is a toolkit of optional integrations. Each integration only loads and only transmits data when you, the site administrator, explicitly enable that module and a customer chooses the corresponding payment / shipping / invoice option. No data is sent to any third party unless the relevant module is enabled and used. The plugin never sends data to Moksa or any analytics/telemetry service.

For every integration below, requests are made server-to-server over HTTPS using your own merchant credentials, and typically include the order number, order amount, buyer name, e-mail, phone, shipping/billing address, item descriptions, and (for e-invoices) the buyer tax ID or carrier number that the customer enters at checkout.

= Payment gateways =

These run when a customer selects the gateway at checkout (to create the payment) and when you query, capture, refund or void the payment from the order screen.

* **ECPay (綠界科技)** — credit card, ATM, CVS, barcode, installments, wallets. Endpoints: payment.ecpay.com.tw. Terms: https://support.ecpay.com.tw/10075/ — Privacy: https://www.ecpay.com.tw/CreditCard/Privacy
* **NewebPay (藍新金流)** — credit card, ATM, CVS, barcode, wallets. Endpoints: core.newebpay.com. Terms: https://www.newebpay.com/website/Page/content/new_service_policy — Privacy: https://www.newebpay.com/website/Page/content/privacy
* **PAYUNi (統一金流)** — credit card, ATM, CVS, wallets. Endpoints: api.payuni.com.tw. Terms: https://www.payuni.com.tw/terms — Privacy: https://www.payuni.com.tw/privacy
* **SmilePay (速買配)** — credit card, ATM, CVS, barcode. Endpoints: ssl.smse.com.tw. Terms: https://www.smilepay.net/em/servicepolicy.asp — Privacy: https://www.smilepay.net/em/servicepolicy.asp
* **PayNow (立吉富)** — credit card, ATM, CVS, installments. Endpoints: www.paynow.com.tw. Terms: https://www.paynow.com.tw/PayNowUserAgreement.aspx — Privacy: https://www.paynow.com.tw/safepolicy.aspx
* **PChomePay (支付連)** — credit card, ATM, CVS, barcode. Endpoints: api.pchomepay.com.tw. Terms: https://www.pchomepay.com.tw/other/service_treaty — Privacy: https://web.pchomepay.com.tw/introduction/privacy
* **LINE Pay** — LINE Pay wallet. Endpoints: api-pay.line.me. Terms: https://terms2.line.me/linepay_TW_TermsofUse?lang=zh-Hant — Privacy: https://terms2.line.me/linepay_TW_PP
* **TapPay** — credit card via the TapPay Fields SDK loaded in the browser (js.tappaysdk.com). Card data is tokenised client-side; only the token reaches your server. Endpoints: prod.tappaysdk.com. Terms & Privacy: https://www.tappaysdk.com/taiwan-en/privacy-term
* **Shopline Payments** — credit card, wallets. Endpoints: api.shoplinepayments.com. Terms: https://book.shoplineapp.com/pages/shopline-payments-terms-and-conditions — Privacy: https://www.shopline.com/shopline-payments-privacy

= Shipping / logistics =

These run when a customer opens the convenience-store map at checkout (the store-selection map is hosted by the provider), when a shipment is created after an order is placed, and when you print a label or query shipment status.

* **ECPay Logistics (綠界物流)** — 7-11 / FamilyMart / Hi-Life / OK / home delivery. Endpoints: logistics.ecpay.com.tw. Terms: https://support.ecpay.com.tw/10075/ — Privacy: https://www.ecpay.com.tw/CreditCard/Privacy
* **NewebPay Logistics (藍新物流)** — CVS / home delivery. Endpoints: core.newebpay.com. Terms: https://www.newebpay.com/website/Page/content/new_service_policy — Privacy: https://www.newebpay.com/website/Page/content/privacy
* **PAYUNi Logistics (統一物流)** — 7-11 / home delivery (incl. cold chain). Endpoints: api.payuni.com.tw. Terms: https://www.payuni.com.tw/terms — Privacy: https://www.payuni.com.tw/privacy
* **SmilePay Logistics (速買配物流)** — 7-11 / FamilyMart / home delivery. Endpoints: ssl.smse.com.tw. Terms: https://www.smilepay.net/em/servicepolicy.asp — Privacy: https://www.smilepay.net/em/servicepolicy.asp

= E-invoice (Taiwan electronic invoicing) =

These run when an invoice is issued for an order (immediately on payment, on completion, or manually, per your setting) and when you void / issue an allowance / query an invoice. Data includes the order amount, item descriptions and the buyer's carrier number, donation code or company tax ID entered at checkout.

* **ECPay e-Invoice (綠界電子發票)** — Endpoints: einvoice.ecpay.com.tw. Terms: https://support.ecpay.com.tw/10075/ — Privacy: https://www.ecpay.com.tw/CreditCard/Privacy
* **ezPay e-Invoice (ezPay 電子發票)** — Endpoints: inv.ezpay.com.tw. Terms: https://www.ezpay.com.tw/info/Site_description/service_page/member — Privacy: https://www.ezpay.com.tw/info/Site_description/service_page/member
* **SmilePay e-Invoice (速買配電子發票)** — Endpoints: ssl.smse.com.tw. Terms: https://www.smilepay.net/em/servicepolicy.asp — Privacy: https://www.smilepay.net/em/servicepolicy.asp
* **PayNow e-Invoice (立吉富電子發票)** — Endpoints: invoice.paynow.com.tw. Terms: https://www.paynow.com.tw/PayNowUserAgreement.aspx — Privacy: https://www.paynow.com.tw/safepolicy.aspx
* **AMEGO e-Invoice (光貿電子發票)** — Endpoints: invoice-api.amego.tw. Terms: https://invoice.amego.tw/ — Privacy: https://invoice.amego.tw/privacy

= Carrier tracking links =

When a shipment has a tracking number, the order page shows a link to the carrier's own public tracking page (no data is sent by the plugin; the customer clicks the link): T-Cat 黑貓宅配 (t-cat.com.tw), 7-11 (eservice.7-11.com.tw), FamilyMart 全家 (fmec.famiport.com.tw), Hi-Life 萊爾富 (hilife.com.tw), OK Mart (ecservice.okmart.com.tw), Chunghwa Post 中華郵政 (postserv.post.gov.tw).

== Installation ==

= Minimum Requirements =

* PHP 8.2+
* WordPress 6.7+
* WooCommerce 8.0+

= Setup =

1. Install and activate the plugin.
2. Go to **WooCommerce → Settings → Moksa for WooCommerce** to enable the modules you need.
3. Configure the credentials for each enabled provider on its dedicated tab.

== Frequently Asked Questions ==

= Does it work with the Block-based Checkout? =

Yes. Every payment method ships an `AbstractPaymentMethodType` + React component, and the convenience-store picker / invoice fields render inside the Checkout block.

= Is it HPOS-compatible? =

Yes. All order meta uses `$order->update_meta_data()` / `$order->save()`, never `update_post_meta()`.

= Can I mix providers? =

Yes. Payment, shipping and invoice modules are fully independent — any combination works.

== Screenshots ==

1. Modules overview — toggle payment / shipping / invoice integrations from a single settings page.
2. Block-based Checkout with native payment method rendering.
3. Order list with extra columns for shipping status and invoice number (HPOS-aware).
4. Order edit screen showing per-provider info cards.
5. Invoice metabox with Issue / Void actions.

== Changelog ==

= 1.0.0 - 2026-05-26 =
* Initial public release on the WordPress.org Plugin Directory.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
