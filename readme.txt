=== Moksa for WooCommerce ===
Contributors: moksa0923
Tags: woocommerce, taiwan, payment, shipping, invoice
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.14.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: woocommerce
WC requires at least: 9.9
WC tested up to: 11.1

A Taiwan e-commerce toolkit for WooCommerce. Bundles Taiwanese payment, shipping and e-invoice integrations.

== Description ==

A Taiwan-focused WooCommerce extension. Toggleable modules cover ECPay (綠界), NewebPay (藍新), SmilePay (速買配), LINE Pay, PAYUNi (統一金流), PayNow (立吉富), PChomePay (支付連), TapPay and Shopline Payments for payments; ECPay, NewebPay, SmilePay, PAYUNi and PayNow for convenience-store + home-delivery shipping; ezPay, ECPay, SmilePay, PayNow and AMEGO for Taiwan e-invoicing.

Enable only the providers you need from a single settings page — payment, shipping and invoice modules are fully independent and can be mixed in any combination.

HPOS-native, Block Checkout-native, PHP 8.2+ strict-typed, GPLv3, no premium gating.

= Moksa AI assistant (new in 1.3.0) =

Built on the WordPress 7.0 AI Client, Moksa AI is an optional in-admin chat assistant that lets you run common store tasks in natural language: find orders by invoice / shipping / payment number, look up order details and counts, change order status (single or batch), add order notes, issue / void / allowance e-invoices, create and print shipping labels, and enable or disable modules, individual payment methods and invoice issuing methods. Every action that changes data first shows a summary and waits for your explicit confirmation before it runs. The assistant uses whichever AI provider you connect under Settings → Connectors — the plugin never handles your AI keys, refunds, or credentials.

= Source =

Source code and issue tracker: [github.com/Moksa1123/moksa-for-woocommerce](https://github.com/Moksa1123/moksa-for-woocommerce).

== External Services ==

This plugin is a toolkit of optional integrations. Each integration only loads and only transmits data when you, the site administrator, explicitly enable that module and a customer chooses the corresponding payment / shipping / invoice option. No data is sent to any third party unless the relevant module is enabled and used. The plugin never sends data to Moksa or any analytics/telemetry service.

For every integration below, requests are made server-to-server over HTTPS using your own merchant credentials, and typically include the order number, order amount, buyer name, e-mail, phone, shipping/billing address, item descriptions, and (for e-invoices) the buyer tax ID or carrier number that the customer enters at checkout.

When a module is set to test/sandbox mode, requests go to the provider's corresponding staging hostname instead of the production one listed below (for example payment-stage.ecpay.com.tw, logistics-stage.ecpay.com.tw, einvoice-stage.ecpay.com.tw, ccore.newebpay.com, sandbox-api.payuni.com.tw, sandbox-api.pchomepay.com.tw, sandbox-api-pay.line.me, sandbox.tappaysdk.com, api-sandbox.shoplinepayments.com, cinv.ezpay.com.tw, test.paynow.com.tw, testinvoice.paynow.com.tw, ssl.smse.com.tw/api_test).

= Payment gateways =

These run when a customer selects the gateway at checkout (to create the payment) and when you query, capture, refund or void the payment from the order screen.

* **ECPay (綠界科技)** — credit card, ATM, CVS, barcode, installments, wallets. Endpoints: payment.ecpay.com.tw, ecpayment.ecpay.com.tw (test mode: payment-stage.ecpay.com.tw, ecpayment-stage.ecpay.com.tw). Terms: https://support.ecpay.com.tw/10075/ — Privacy: https://www.ecpay.com.tw/CreditCard/Privacy
* **NewebPay (藍新金流)** — credit card, ATM, CVS, barcode, wallets. Endpoints: core.newebpay.com (test mode: ccore.newebpay.com). Terms: https://www.newebpay.com/website/Page/content/new_service_policy — Privacy: https://www.newebpay.com/website/Page/content/privacy
* **PAYUNi (統一金流)** — credit card, ATM, CVS, wallets. Endpoints: api.payuni.com.tw (test mode: sandbox-api.payuni.com.tw). Terms: https://www.payuni.com.tw/terms — Privacy: https://www.payuni.com.tw/privacy
* **SmilePay (速買配)** — credit card, ATM, CVS, barcode. Endpoints: ssl.smse.com.tw (SmilePay's own API hostname — smse.com.tw and smilepay.net are both operated by the same company, 訊航科技 Shinhang Technology; its policies are published on the smilepay.net brand site; test mode uses the /api_test path on the same hostname). Terms: https://www.smilepay.net/em/servicepolicy.asp — Privacy: https://www.smilepay.net/em/servicepolicy.asp (SmilePay publishes a single combined service & personal-data-protection document, so both links point at that document)
* **PayNow (立吉富)** — credit card, ATM, CVS, installments. Endpoints: www.paynow.com.tw (test mode: test.paynow.com.tw). Terms: https://www.paynow.com.tw/PayNowUserAgreement.aspx — Privacy: https://www.paynow.com.tw/safepolicy.aspx
* **PChomePay (支付連)** — credit card, ATM, CVS, barcode. Endpoints: api.pchomepay.com.tw (test mode: sandbox-api.pchomepay.com.tw). Terms: https://www.pchomepay.com.tw/other/service_treaty — Privacy: https://web.pchomepay.com.tw/introduction/privacy
* **LINE Pay** — LINE Pay wallet. Endpoints: api-pay.line.me (test mode: sandbox-api-pay.line.me). Terms: https://terms2.line.me/linepay_TW_TermsofUse?lang=zh-Hant — Privacy: https://terms2.line.me/linepay_TW_PP
* **TapPay** — credit card via the TapPay Fields SDK loaded in the browser (js.tappaysdk.com). Card data is tokenised client-side; only the token reaches your server. Endpoints: prod.tappaysdk.com, js.tappaysdk.com (test mode: sandbox.tappaysdk.com). Terms: https://www.tappaysdk.com/taiwan-en/privacy-term — Privacy: https://www.tappaysdk.com/taiwan-en/privacy-term (TapPay publishes a single combined terms & privacy document, so both links point at that document)
* **Shopline Payments** — credit card, wallets. Endpoints: api.shoplinepayments.com (test mode: api-sandbox.shoplinepayments.com). Terms: https://book.shoplineapp.com/pages/shopline-payments-terms-and-conditions — Privacy: https://www.shopline.com/shopline-payments-privacy

= Shipping / logistics =

These run when a customer opens the convenience-store map at checkout (the store-selection map is hosted by the provider), when a shipment is created after an order is placed, and when you print a label or query shipment status.

* **ECPay Logistics (綠界物流)** — 7-11 / FamilyMart / Hi-Life / OK / home delivery. Endpoints: logistics.ecpay.com.tw (test mode: logistics-stage.ecpay.com.tw). Terms: https://support.ecpay.com.tw/10075/ — Privacy: https://www.ecpay.com.tw/CreditCard/Privacy
* **NewebPay Logistics (藍新物流)** — CVS / home delivery. Endpoints: core.newebpay.com (test mode: ccore.newebpay.com). Terms: https://www.newebpay.com/website/Page/content/new_service_policy — Privacy: https://www.newebpay.com/website/Page/content/privacy
* **PAYUNi Logistics (統一物流)** — 7-11 / home delivery (incl. cold chain). Endpoints: api.payuni.com.tw (test mode: sandbox-api.payuni.com.tw). Terms: https://www.payuni.com.tw/terms — Privacy: https://www.payuni.com.tw/privacy
* **SmilePay Logistics (速買配物流)** — 7-11 / FamilyMart / home delivery. Endpoints: ssl.smse.com.tw (SmilePay's own API hostname; see the SmilePay entry above — same operator as smilepay.net; test mode uses the /api_test path on the same hostname). Terms: https://www.smilepay.net/em/servicepolicy.asp — Privacy: https://www.smilepay.net/em/servicepolicy.asp (single combined service & personal-data-protection document)

= E-invoice (Taiwan electronic invoicing) =

These run when an invoice is issued for an order (immediately on payment, on completion, or manually, per your setting) and when you void / issue an allowance / query an invoice. Data includes the order amount, item descriptions and the buyer's carrier number, donation code or company tax ID entered at checkout.

* **ECPay e-Invoice (綠界電子發票)** — Endpoints: einvoice.ecpay.com.tw (test mode: einvoice-stage.ecpay.com.tw). Terms: https://support.ecpay.com.tw/10075/ — Privacy: https://www.ecpay.com.tw/CreditCard/Privacy
* **ezPay e-Invoice (ezPay 電子發票)** — Endpoints: inv.ezpay.com.tw (test mode: cinv.ezpay.com.tw). Terms: https://www.ezpay.com.tw/info/Site_description/service_page/member — Privacy: https://www.ezpay.com.tw/info/Site_description/service_page/member (ezPay publishes a single combined membership & data-protection terms page, so both links point at that page)
* **SmilePay e-Invoice (速買配電子發票)** — Endpoints: ssl.smse.com.tw (SmilePay's own API hostname; see the SmilePay entry above — same operator as smilepay.net; test mode uses the /api_test path on the same hostname). Terms: https://www.smilepay.net/em/servicepolicy.asp — Privacy: https://www.smilepay.net/em/servicepolicy.asp (single combined service & personal-data-protection document)
* **PayNow e-Invoice (立吉富電子發票)** — Endpoints: invoice.paynow.com.tw (test mode: testinvoice.paynow.com.tw). Terms: https://www.paynow.com.tw/PayNowUserAgreement.aspx — Privacy: https://www.paynow.com.tw/safepolicy.aspx
* **AMEGO e-Invoice (光貿電子發票)** — Endpoints: invoice-api.amego.tw. Terms: https://invoice.amego.tw/term — Privacy: https://invoice.amego.tw/privacy

= Carrier tracking links (hyperlinks only — the plugin itself never contacts these hosts) =

When a shipment has a tracking number, the order screen renders a plain hyperlink to the carrier's own public parcel-tracking page. The plugin makes no HTTP request to any of these hosts and transmits no data to them; the shipment number only leaves your site if a person clicks the link, at which point the carrier's own terms and privacy policy apply in their browser:

* **T-Cat 黑貓宅配** (t-cat.com.tw, incl. the tracking link shown for PAYUNi home-delivery shipments) — Privacy: https://www.t-cat.com.tw/member/privacy.aspx
* **7-ELEVEN** (eservice.7-11.com.tw) — Privacy: https://www.7-11.com.tw/privacy.asp
* **7-ELEVEN pickup status via PAYUNi logistics** (tracking.shopmore.com.tw, operated by the Uni-President group for PAYUNi shipments) — service info: https://help.shopmore.com.tw/ ; the PAYUNi logistics policies above apply to the shipment itself
* **FamilyMart 全家** (fmec.famiport.com.tw), **Hi-Life 萊爾富** (www.hilife.com.tw), **OK Mart** (ecservice.okmart.com.tw) — public tracking pages of each chain; their site policies are linked from those pages
* **Chunghwa Post 中華郵政** (postserv.post.gov.tw) — Privacy: https://www.post.gov.tw/post/internet/Group/index.jsp?ID=156739569921

= Moksa AI assistant (optional, admin-only) =

When an administrator actively uses the in-admin Moksa AI assistant, the typed question and the store/order data needed to answer it (for example an order number, status, totals or invoice/shipping numbers) are sent to the AI provider you have connected in WordPress under **Settings → Connectors** — Anthropic, Google or OpenAI — through the WordPress 7.0 AI Client. This never happens automatically and only for the administrator using the assistant. The plugin does not store these conversations on any Moksa server, sends nothing to Moksa, and never transmits your AI provider keys (WordPress manages the connector credentials). The transmitted data is governed by the terms and privacy policy of the provider you choose: Anthropic — Terms: https://www.anthropic.com/legal/commercial-terms — Privacy: https://www.anthropic.com/legal/privacy ; Google — Terms: https://ai.google.dev/gemini-api/terms — Privacy: https://policies.google.com/privacy ; OpenAI — Terms: https://openai.com/policies/terms-of-use/ — Privacy: https://openai.com/policies/privacy-policy/

= MCP server (optional, off by default) =

This plugin can optionally expose a standards-compliant, stateless MCP (Model Context Protocol) endpoint on **your own site** at `/wp-json/moksa-for-woocommerce/v1/mcp`, so a standard MCP client you control (for example mcp-remote or Claude) can look up orders and reports through the WordPress REST API. This is **not a Moksa service and is not a phone-home**: nothing is sent to Moksa, the endpoint only runs on your server and only exposes the plugin's own WordPress Abilities. It is **off by default** and must be turned on under WooCommerce → Moksa AI → Settings. Access requires authentication with a WordPress Application Password for a user that has the "edit orders" capability (use a dedicated, limited account). By default only read-only tools are exposed; order-changing tools stay hidden unless you also enable the separate "allow external AI to make changes" option, and every request is permission-checked on your server.

== Installation ==

= Minimum Requirements =

* PHP 8.2+
* WordPress 7.0+
* WooCommerce 9.9+

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

= What is the Moksa AI assistant and what does it need? =

It is an optional in-admin chat assistant for managing orders, e-invoices, shipping labels and module settings in natural language. It requires WordPress 7.0 (for the built-in AI Client) and an AI provider connected under **Settings → Connectors**; enable it under the plugin's Advanced settings. Every action that changes data first asks for your confirmation, and the assistant can never read or change your provider credentials, switch sandbox/live mode, or issue refunds.

= Can external AI tools connect to my store over MCP? =

Yes, optional and off by default. Turn it on under **WooCommerce → Moksa AI → Settings → "Enable external MCP server"**. The plugin then serves a standards-compliant, stateless MCP (Model Context Protocol) endpoint at `/wp-json/moksa-for-woocommerce/v1/mcp` that any standard MCP client (for example mcp-remote or Claude) can connect to directly — no bridge required.

Authentication uses a WordPress Application Password for a user that has the "edit orders" capability; use a dedicated, limited account rather than an administrator. Connect your client to the endpoint with an `Authorization: Basic <base64 of username:application-password>` header. By default only read-only tools (look up orders, reports, settings overview) are exposed; order-changing tools stay hidden unless you also enable the "allow external AI to make changes" option, and destructive actions still require in-store confirmation. Every request is permission-checked on the server.

== Screenshots ==

1. Modules overview — enable only the payment, shipping and e-invoice integrations you need, all from one settings page.
2. Block-based Checkout rendering Taiwanese payment methods natively, alongside the order summary.
3. Order list with the plugin's own order statuses plus shipping-method and tracking-number columns (HPOS-native).
4. Order edit screen with per-provider payment, shipping and e-invoice cards.
5. Issuing an e-invoice from the order screen, including carrier type and mobile barcode entry.

== Changelog ==

= 1.14.0 - 2026-10-15 =
New
* PAYUNi: card payments that have not been captured yet can be cancelled in full from the WooCommerce refund screen. Payment types PAYUNi cannot refund through the API now say so.

Fixed
* Orders paid with a payment method you later disabled can still be refunded through the provider from the order screen.
* Shipping status follows the slowest parcel when an order is split by temperature, and no longer moves backwards. PAYUNi status notices for the first parcel of a split order were ignored.
* PAYUNi bulk printing sent every order on the site instead of the selected ones, and now prints different shipping types and temperatures separately.
* Cash on delivery is no longer offered when the order total is outside what the chosen carrier can collect; declared values are kept within the carrier limits. ECPay B2C no longer offers OK Mart pickup.
* PAYUNi statuses 92 and 98 mean "processing" and no longer mark an order as shipped. A lowercase PAYUNi merchant ID no longer causes every payment notice to be ignored.
* Phone numbers are checked on the block checkout for PAYUNi and NewebPay store pickup; NewebPay requires a 09 mobile number.
* Block checkout now validates invoice fields and ECPay carriers; the classic checkout requires a company name for business invoices and no longer saves hidden invoice fields.
* Virtual-only and overseas orders are no longer asked for a Taiwan district. With the country field hidden, the block checkout starts from Taiwan.
* SmilePay: test mode now works, T-Cat statuses and exceptions are handled, and the payment details email is sent when the payment code is issued.
* Emails show "Free!" for free store pickup instead of repeating the method name; the store-arrival email shows the store; the store-closed email no longer points to a page that does not exist.
* Store cards no longer stay on screen after switching to local pickup, and the PAYUNi card no longer reloads on every click.
* Smaller fixes to customer service lookups, invoice numbers, tracking numbers, labels and translations.

= 1.13.0 - 2026-10-15 =
New
* Orders list: when an e-invoice provider is set to issue manually, paid orders without an invoice get an "Issue invoice" button in the invoice column.
* Advanced settings: an option to hide "Downloads" from the customer's My Account menu.

Fixed
* PAYUNi ATM and convenience-store payment codes: the order is now put on hold when the code is issued, instead of staying "Pending payment" and being cancelled automatically while the code is still valid. A payment whose amount does not match the order total is put on hold for review.
* PAYUNi: orders whose payment notice never arrived can now be looked up; a failed refund response no longer leaves a refund recorded in WooCommerce; an order returning to Processing no longer creates a second shipment.
* NewebPay shipping status notices were never processed. SmilePay shipping status notices were always rejected; they are now verified with a per-order key.
* SmilePay shipping now sends the cash-on-pickup and pickup-only codes defined by SmilePay. FamilyMart bulk (B2C) is reported as unavailable.
* Cash on pickup now collects the full amount due, including fees and minus refunds. ECPay Chunghwa Post no longer accepts cash on delivery. PAYUNi mixed-temperature shipments keep the declared value within 30-20,000.
* The shipping row in order details, My Account and emails showed only the method name and lost the shipping cost.
* Payment codes are no longer shown on cancelled, failed or refunded orders, and no longer appear twice on the classic thank-you page.
* ECPay and SmilePay: switching to another convenience store brand no longer reuses the store chosen for the previous one.
* PAYUNi frozen 7-ELEVEN pickup opens the frozen store map. 7-ELEVEN tracking numbers include the verification code. PAYUNi status settings are no longer overwritten. NewebPay and SmilePay now send the shipping emails.

Older entries are available in the plugin's repository.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
