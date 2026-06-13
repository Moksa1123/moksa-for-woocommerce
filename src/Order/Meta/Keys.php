<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Order\Meta;

defined( 'ABSPATH' ) || exit;

final class Keys {

	public const SHIPPING_CVS_STORE_ID      = '_moksafowo_shipping_cvs_store_id';
	public const SHIPPING_CVS_STORE_NAME    = '_moksafowo_shipping_cvs_store_name';
	public const SHIPPING_CVS_STORE_ADDRESS = '_moksafowo_shipping_cvs_store_address';
	public const SHIPPING_CVS_STORE_PROVIDER = '_moksafowo_shipping_cvs_store_provider';
	public const SHIPPING_LABEL_NUMBER      = '_moksafowo_shipping_label_number';
	public const SHIPPING_LABEL_PROVIDER    = '_moksafowo_shipping_label_provider';
	public const SHIPPING_LABEL_PRINTED_AT  = '_moksafowo_shipping_label_printed_at';

	public const INVOICE_TYPE             = '_moksafowo_invoice_type';            // b2c_carrier|b2c_donate|b2b|paper.
	public const INVOICE_BUYER_UBN        = '_moksafowo_invoice_buyer_ubn';
	public const INVOICE_BUYER_NAME       = '_moksafowo_invoice_buyer_name';
	public const INVOICE_CARRIER_TYPE     = '_moksafowo_invoice_carrier_type';
	public const INVOICE_CARRIER_NUM      = '_moksafowo_invoice_carrier_num';
	public const INVOICE_LOVE_CODE        = '_moksafowo_invoice_love_code';
	public const INVOICE_PROVIDER         = '_moksafowo_invoice_provider';        // ecpay|ezpay|paynow.

	// 取號類付款（ATM/CVS/Barcode）「付款資訊 email 已寄出」旗標 — 共用於 5 家金流的 IpnHandler
	// 避免顧客 IPN 短時間多次（webhook retry / dual-IPN）觸發重發 email。
	public const PAYMENT_INFO_EMAIL_SENT = '_moksafowo_payment_info_email_sent';

	public const ECPAY_TRADE_NO          = '_moksafowo_ecpay_trade_no';
	public const ECPAY_MERCHANT_TRADE_NO = '_moksafowo_ecpay_merchant_trade_no';
	public const ECPAY_PAYMENT_TYPE      = '_moksafowo_ecpay_payment_type';
	public const ECPAY_RTN_CODE          = '_moksafowo_ecpay_rtn_code';
	public const ECPAY_PAYMENT_DATE      = '_moksafowo_ecpay_payment_date';
	public const ECPAY_CARD_LAST4        = '_moksafowo_ecpay_card_last4';
	// 取號類付款資訊（ATM 虛擬帳號 / CVS 繳費代碼 / 條碼）— 顧客需這些才能完成付款。
	public const ECPAY_ATM_BANK_CODE     = '_moksafowo_ecpay_atm_bank_code';
	public const ECPAY_ATM_V_ACCOUNT     = '_moksafowo_ecpay_atm_v_account';
	public const ECPAY_ATM_EXPIRE_DATE   = '_moksafowo_ecpay_atm_expire_date';
	public const ECPAY_CVS_PAYMENT_NO    = '_moksafowo_ecpay_cvs_payment_no';
	public const ECPAY_CVS_EXPIRE_DATE   = '_moksafowo_ecpay_cvs_expire_date';
	public const ECPAY_BARCODE_1         = '_moksafowo_ecpay_barcode_1';
	public const ECPAY_BARCODE_2         = '_moksafowo_ecpay_barcode_2';
	public const ECPAY_BARCODE_3         = '_moksafowo_ecpay_barcode_3';
	public const ECPAY_BARCODE_EXPIRE_DATE = '_moksafowo_ecpay_barcode_expire_date';
	public const ECPAY_LOGISTIC_RECORDS              = '_moksafowo_ecpay_logistic_records';
	public const ECPAY_LOGISTIC_ID                  = '_moksafowo_ecpay_logistic_id';
	public const ECPAY_LOGISTIC_TYPE                = '_moksafowo_ecpay_logistic_type';
	public const ECPAY_LOGISTIC_SUBTYPE             = '_moksafowo_ecpay_logistic_subtype';
	public const ECPAY_LOGISTIC_MERCHANT_TRADE_NO   = '_moksafowo_ecpay_logistic_merchant_trade_no';
	public const ECPAY_LOGISTIC_RTN_CODE            = '_moksafowo_ecpay_logistic_rtn_code';
	public const ECPAY_LOGISTIC_RTN_MSG             = '_moksafowo_ecpay_logistic_rtn_msg';
	public const ECPAY_LOGISTIC_CVS_PAYMENT_NO      = '_moksafowo_ecpay_logistic_cvs_payment_no';
	public const ECPAY_LOGISTIC_CVS_VALIDATION_NO   = '_moksafowo_ecpay_logistic_cvs_validation_no';
	public const ECPAY_LOGISTIC_BOOKING_NOTE        = '_moksafowo_ecpay_logistic_booking_note';
	public const ECPAY_LOGISTIC_CREATED_AT          = '_moksafowo_ecpay_logistic_created_at';
	public const ECPAY_INVOICE_NUMBER               = '_moksafowo_ecpay_invoice_number';
	public const ECPAY_INVOICE_RANDOM               = '_moksafowo_ecpay_invoice_random';
	public const ECPAY_INVOICE_RELATE_NUMBER        = '_moksafowo_ecpay_invoice_relate_number';
	public const ECPAY_INVOICE_ISSUED_AT            = '_moksafowo_ecpay_invoice_issued_at';
	public const ECPAY_INVOICE_INVALID_AT           = '_moksafowo_ecpay_invoice_invalid_at';
	public const ECPAY_INVOICE_INVALID_REASON       = '_moksafowo_ecpay_invoice_invalid_reason';
	public const ECPAY_INVOICE_ALLOWANCE_NO         = '_moksafowo_ecpay_invoice_allowance_no';
	public const ECPAY_INVOICE_ALLOWANCE_AMT        = '_moksafowo_ecpay_invoice_allowance_amt';
	public const ECPAY_INVOICE_TAX_TYPE             = '_moksafowo_ecpay_invoice_tax_type';
	public const ECPAY_INVOICE_DELAY_DAYS           = '_moksafowo_ecpay_invoice_delay_days';
	public const ECPAY_INVOICE_SCHEDULED_AT         = '_moksafowo_ecpay_invoice_scheduled_at';

	public const NEWEBPAY_TRADE_NO          = '_moksafowo_newebpay_trade_no';
	public const NEWEBPAY_MERCHANT_ORDER_NO = '_moksafowo_newebpay_merchant_order_no';
	public const NEWEBPAY_PAYMENT_TYPE      = '_moksafowo_newebpay_payment_type';
	public const NEWEBPAY_PAY_TIME          = '_moksafowo_newebpay_pay_time';
	public const NEWEBPAY_CARD_LAST4        = '_moksafowo_newebpay_card_last4';

	public const NEWEBPAY_ATM_BANK_CODE       = '_moksafowo_newebpay_atm_bank_code';
	public const NEWEBPAY_ATM_CODE_NO         = '_moksafowo_newebpay_atm_code_no';
	public const NEWEBPAY_ATM_EXPIRE_DATE     = '_moksafowo_newebpay_atm_expire_date';
	public const NEWEBPAY_CVS_CODE_NO         = '_moksafowo_newebpay_cvs_code_no';
	public const NEWEBPAY_CVS_EXPIRE_DATE     = '_moksafowo_newebpay_cvs_expire_date';
	public const NEWEBPAY_BARCODE_1           = '_moksafowo_newebpay_barcode_1';
	public const NEWEBPAY_BARCODE_2           = '_moksafowo_newebpay_barcode_2';
	public const NEWEBPAY_BARCODE_3           = '_moksafowo_newebpay_barcode_3';
	public const NEWEBPAY_BARCODE_EXPIRE_DATE = '_moksafowo_newebpay_barcode_expire_date';

	public const NEWEBPAY_SHIPPING_LGS_NO            = '_moksafowo_newebpay_shipping_lgs_no';     // NewebPay LgsNo (物流單號).
	public const NEWEBPAY_SHIPPING_LGS_TYPE          = '_moksafowo_newebpay_shipping_lgs_type';   // CVS LgsType: B2C / C2C.
	public const NEWEBPAY_SHIPPING_STORE_ID          = '_moksafowo_newebpay_shipping_store_id';   // 顧客選擇的門市代碼.
	public const NEWEBPAY_SHIPPING_STORE_NAME        = '_moksafowo_newebpay_shipping_store_name'; // 門市名稱.
	public const NEWEBPAY_SHIPPING_STORE_ADDR        = '_moksafowo_newebpay_shipping_store_addr'; // 門市地址.
	public const NEWEBPAY_SHIPPING_STATUS            = '_moksafowo_newebpay_shipping_status';     // 物流狀態 (新建單 / 已取貨 / 退貨等).
	public const NEWEBPAY_SHIPPING_MERCHANT_ORDER_NO = '_moksafowo_newebpay_shipping_merchant_order_no';
	public const NEWEBPAY_SHIPPING_SHIP_TYPE         = '_moksafowo_newebpay_shipping_ship_type';
	public const NEWEBPAY_SHIPPING_TRADE_TYPE        = '_moksafowo_newebpay_shipping_trade_type';

	public const SMILEPAY_SMSEID         = '_moksafowo_smilepay_smseid';
	public const SMILEPAY_PAYMENT_TYPE   = '_moksafowo_smilepay_payment_type';
	public const SMILEPAY_PAY_DATE       = '_moksafowo_smilepay_pay_date';

	public const SMILEPAY_PAY_ZG          = '_moksafowo_smilepay_pay_zg';           // Pay_zg 代碼: 1/2/3/4/6/11.
	public const SMILEPAY_PAY_GATEWAY     = '_moksafowo_smilepay_pay_gateway';      // 送單的 mowp gateway id.
	public const SMILEPAY_PAY_SMILEPAY_NO = '_moksafowo_smilepay_pay_smilepay_no';  // SmilePay 金流追蹤碼 (SmilePayNO / Smseid).
	public const SMILEPAY_PAY_ATM_BANK_NO = '_moksafowo_smilepay_pay_atm_bank_no';  // 虛擬帳號銀行代碼.
	public const SMILEPAY_PAY_ATM_NO      = '_moksafowo_smilepay_pay_atm_no';       // 虛擬帳號.
	public const SMILEPAY_PAY_IBON_NO     = '_moksafowo_smilepay_pay_ibon_no';      // ibon 繳費代碼.
	public const SMILEPAY_PAY_FAMI_NO     = '_moksafowo_smilepay_pay_fami_no';      // FamiPort 繳費代碼.
	public const SMILEPAY_PAY_BARCODE_1   = '_moksafowo_smilepay_pay_barcode_1';
	public const SMILEPAY_PAY_BARCODE_2   = '_moksafowo_smilepay_pay_barcode_2';
	public const SMILEPAY_PAY_BARCODE_3   = '_moksafowo_smilepay_pay_barcode_3';
	public const SMILEPAY_PAY_AMOUNT      = '_moksafowo_smilepay_pay_amount';       // SmilePay 回報金額.
	public const SMILEPAY_PAY_END_DATE    = '_moksafowo_smilepay_pay_end_date';     // 繳費期限.
	public const SMILEPAY_PAY_INSTALLMENT = '_moksafowo_smilepay_pay_installment';  // 信用卡分期期數.
	public const SMILEPAY_PAY_PAID_AT     = '_moksafowo_smilepay_pay_paid_at';      // 入帳時間 (roturl 回報).
	public const SMILEPAY_PAY_INFO_HTML   = '_moksafowo_smilepay_pay_info_html';    // thankyou / email 顯示用付款資訊 HTML.
	public const SMILEPAY_PAY_SUCCESS_TEXT = '_moksafowo_smilepay_pay_success_text'; // 商家自訂下單成功訊息.

	public const SMILEPAY_SHIPPING_NO         = '_moksafowo_smilepay_shipping_no';          // SmilePay 物流訂單號 (建單回傳的 SmilePayNo / 訂單編號).
	public const SMILEPAY_SHIPPING_TYPE       = '_moksafowo_smilepay_shipping_type';        // CVS 子類: 711C2C / FAMIC2C / 711B2C / FAMIB2C / TCAT.
	public const SMILEPAY_SHIPPING_LGS_TYPE   = '_moksafowo_smilepay_shipping_lgs_type';    // C2C / B2C.
	public const SMILEPAY_SHIPPING_STORE_ID   = '_moksafowo_smilepay_shipping_store_id';    // 顧客選擇的門市代號.
	public const SMILEPAY_SHIPPING_STORE_NAME = '_moksafowo_smilepay_shipping_store_name';  // 門市名稱.
	public const SMILEPAY_SHIPPING_STORE_ADDR = '_moksafowo_smilepay_shipping_store_addr';  // 門市地址.
	public const SMILEPAY_SHIPPING_TRACK_NO   = '_moksafowo_smilepay_shipping_track_no';    // 黑貓追蹤號 (TCAT TrackNo).
	public const SMILEPAY_SHIPPING_STATUS     = '_moksafowo_smilepay_shipping_status';      // 物流狀態 (送貨中 / 已抵店 / 已取件 / 退回等).
	public const SMILEPAY_SHIPPING_PAY_NO     = '_moksafowo_smilepay_shipping_pay_no';      // CVS 取貨付款編號 (B2C / 貨到付款用).
	public const SMILEPAY_SHIPPING_RECORDS    = '_moksafowo_smilepay_shipping_records';

	public const LINEPAY_TRANSACTION_ID        = '_moksafowo_linepay_transaction_id';
	public const LINEPAY_ORDER_ID              = '_moksafowo_linepay_order_id';            // Merchant order id we sent to LINE Pay.
	public const LINEPAY_CONFIRMED             = '_moksafowo_linepay_confirmed';           // Mutex flag set BEFORE confirm API call.
	public const LINEPAY_PAYMENT_STATUS        = '_moksafowo_linepay_payment_status';      // authorized|captured|failed|cancelled|expired.
	public const LINEPAY_PAYMENT_URL_WEB       = '_moksafowo_linepay_payment_url_web';     // Browser redirect URL from /v3/payments/request.
	public const LINEPAY_PAYMENT_URL_APP       = '_moksafowo_linepay_payment_url_app';     // App link URL from /v3/payments/request.
	public const LINEPAY_PAY_TYPE              = '_moksafowo_linepay_pay_type';            // NORMAL|PREAPPROVED.
	public const LINEPAY_AUTHORIZATION_AMOUNT  = '_moksafowo_linepay_authorization_amount';
	public const LINEPAY_AUTHORIZED_AT         = '_moksafowo_linepay_authorized_at';       // ISO-8601.
	public const LINEPAY_REGKEY                = '_moksafowo_linepay_regkey';              // PREAPPROVED token for subscriptions.
	public const LINEPAY_REFUND_TRANSACTION_ID = '_moksafowo_linepay_refund_transaction_id';
	public const LINEPAY_LAST_ERROR_CODE       = '_moksafowo_linepay_last_error_code';

	// PAYUNi: values match PaymentResponse::save_payuni_order_data() — changing
	// any string here orphans existing orders.
	public const PAYUNI_ORDER_NO         = '_moksafowo_payuni_order_no';        // MerTradeNo we send to PAYUNi
	public const PAYUNI_TRADE_NO         = '_moksafowo_payuni_trade_no';        // UNi internal trade no
	public const PAYUNI_STATUS           = '_moksafowo_payuni_status';          // SUCESS|OK
	public const PAYUNI_MESSAGE          = '_moksafowo_payuni_message';
	public const PAYUNI_TRADE_STATUS     = '_moksafowo_payuni_trade_status';    // 0..3
	public const PAYUNI_TRADE_AMOUNT     = '_moksafowo_payuni_trade_amount';
	public const PAYUNI_PAYMENT_TYPE     = '_moksafowo_payuni_payment_type';    // 1..9
	public const PAYUNI_PAID_AT          = '_moksafowo_payuni_paid_at';         // TradeFinishTime
	public const PAYUNI_PLUGIN_VERSION   = '_moksafowo_payuni_plugin_version';
	public const PAYUNI_ORDER_SERIAL_NO  = '_moksafowo_payuni_order_serial_no'; // 重發 MerTradeNo 的尾碼計數

	public const PAYUNI_CREDIT_RESCODE     = '_moksafowo_payuni_credit_rescode';     // ResCode (授權碼)
	public const PAYUNI_CREDIT_RESCODE_MSG = '_moksafowo_payuni_credit_rescode_msg';
	public const PAYUNI_CREDIT_AUTH_TYPE   = '_moksafowo_payuni_credit_authtype';    // 1=一次 2=分期 3=記憶卡
	public const PAYUNI_CREDIT_CARD4NO     = '_moksafowo_payuni_credit_card4no';     // 卡末四碼
	public const PAYUNI_CREDIT_AUTH_DAY    = '_moksafowo_payuni_credit_authday';
	public const PAYUNI_CREDIT_AUTH_TIME   = '_moksafowo_payuni_credit_authtime';
	public const PAYUNI_CREDIT_AUTH_CODE   = '_moksafowo_payuni_credit_authcode';    // AuthCode (銀行授權碼，跟 ResCode 不同)
	public const PAYUNI_CREDIT_INST        = '_moksafowo_payuni_credit_cardinst';    // 分期期數
	public const PAYUNI_CREDIT_FIRST_AMT   = '_moksafowo_payuni_credit_firstamt';
	public const PAYUNI_CREDIT_EACH_AMT    = '_moksafowo_payuni_credit_eachamt';
	public const PAYUNI_CREDIT_BANK        = '_moksafowo_payuni_credit_bank';        // 發卡行
	public const PAYUNI_CREDIT_LOCATION    = '_moksafowo_payuni_credit_location';    // 海外卡 Y/N
	public const PAYUNI_CREDIT_ECI         = '_moksafowo_payuni_credit_eci';         // 3D secure ECI
	public const PAYUNI_CREDIT_RED_AMT     = '_moksafowo_payuni_credit_red_amt';     // 紅利折抵金額
	public const PAYUNI_CREDIT_RED_NO      = '_moksafowo_payuni_credit_red_no';      // 紅利序號
	public const PAYUNI_CREDIT_TOKEN_ID    = '_moksafowo_payuni_credit_token_id';    // 信用卡記憶 token
	public const PAYUNI_CREDIT_TOKEN_LIFE  = '_moksafowo_payuni_credit_token_life';

	public const PAYUNI_ATM_PAYNO       = '_moksafowo_payuni_atm_payno';
	public const PAYUNI_ATM_BANKTYPE    = '_moksafowo_payuni_atm_banktype';
	public const PAYUNI_ATM_PAYTIME     = '_moksafowo_payuni_atm_paytime';
	public const PAYUNI_ATM_ACCOUNT5NO  = '_moksafowo_payuni_atm_account5no';
	public const PAYUNI_ATM_PAYSET      = '_moksafowo_payuni_atm_payset';   // 1=一次 2=重覆
	public const PAYUNI_ATM_EXPIREDATE  = '_moksafowo_payuni_atm_expiredate';

	public const PAYUNI_CVS_PAYNO       = '_moksafowo_payuni_cvs_payno';
	public const PAYUNI_CVS_STORE       = '_moksafowo_payuni_cvs_store';    // 7-11/FAMI/HILIFE/OK
	public const PAYUNI_CVS_EXPIREDATE  = '_moksafowo_payuni_cvs_expiredate';

	public const PAYUNI_AFTEE_PAYNO     = '_moksafowo_payuni_aftee_payno';
	public const PAYUNI_AFTEE_PAYTIME   = '_moksafowo_payuni_aftee_paytime';

	public const PAYUNI_LINEPAY_PAYNO   = '_moksafowo_payuni_linepay_payno';

	public const PAYUNI_REFUND_NO       = '_moksafowo_payuni_refund_no';
	public const PAYUNI_REFUND_AMT      = '_moksafowo_payuni_refund_amt';
	public const PAYUNI_REFUND_TIME     = '_moksafowo_payuni_refund_time';

	public const PAYUNI_CLOSE_STATUS    = '_moksafowo_payuni_close_status';
	public const PAYUNI_CLOSE_TIME      = '_moksafowo_payuni_close_time';
	public const PAYUNI_CLOSE_AUTH      = '_moksafowo_payuni_close_auth';   // BankSettleAuth

	public const PAYUNI_SHIPPING_RECORDS  = '_moksafowo_payuni_shipping_records';
	public const PAYUNI_SHIPPING_SNO      = '_moksafowo_payuni_shipping_sno';      // PayuniShipping fork OrderMeta::ShipNo 對應
	public const PAYUNI_SHIPPING_TRADE_NO = '_moksafowo_payuni_shipping_trade_no'; // PayuniShipping fork OrderMeta::ShipTradeNo 對應

	public const PAYUNI_EINVOICE_NO     = '_moksafowo_payuni_einvoice_no';
	public const PAYUNI_EINVOICE_AMT    = '_moksafowo_payuni_einvoice_amt';
	public const PAYUNI_EINVOICE_TIME   = '_moksafowo_payuni_einvoice_time';
	public const PAYUNI_EINVOICE_TYPE   = '_moksafowo_payuni_einvoice_type';   // C0401|C0501
	public const PAYUNI_EINVOICE_INFO   = '_moksafowo_payuni_einvoice_info';
	public const PAYUNI_EINVOICE_STATUS = '_moksafowo_payuni_einvoice_status'; // 1|2|5

	public const PAYNOW_ORDER_NO        = '_moksafowo_paynow_order_no';     // 商家送出的 OrderNo.
	public const PAYNOW_BUYSAFE_NO      = '_moksafowo_paynow_buysafe_no';
	public const PAYNOW_PAY_TYPE        = '_moksafowo_paynow_pay_type';     // 01..11.
	public const PAYNOW_CODE_TYPE       = '_moksafowo_paynow_code_type';    // 0..2 when PayType=05.
	public const PAYNOW_TRAN_STATUS     = '_moksafowo_paynow_tran_status';  // S|F.
	public const PAYNOW_CARD_LAST4      = '_moksafowo_paynow_card_last4';
	public const PAYNOW_CARD_FOREIGN    = '_moksafowo_paynow_card_foreign';
	public const PAYNOW_INSTALLMENT     = '_moksafowo_paynow_installment';
	public const PAYNOW_ATM_NO          = '_moksafowo_paynow_atm_no';
	public const PAYNOW_ATM_BANK_CODE   = '_moksafowo_paynow_atm_bank_code';
	public const PAYNOW_ATM_BRANCH_CODE = '_moksafowo_paynow_atm_branch_code';
	public const PAYNOW_ATM_DUE_DATE    = '_moksafowo_paynow_atm_due_date';
	public const PAYNOW_BARCODE_1       = '_moksafowo_paynow_barcode_1';
	public const PAYNOW_BARCODE_2       = '_moksafowo_paynow_barcode_2';
	public const PAYNOW_BARCODE_3       = '_moksafowo_paynow_barcode_3';
	public const PAYNOW_BARCODE_DUE_DATE = '_moksafowo_paynow_barcode_due_date';
	public const PAYNOW_IBON_NO         = '_moksafowo_paynow_ibon_no';
	public const PAYNOW_FAMIPORT_NO     = '_moksafowo_paynow_famiport_no';
	public const PAYNOW_ICASH_NO        = '_moksafowo_paynow_icash_no';
	public const PAYNOW_ICASH_PAY_URL   = '_moksafowo_paynow_icash_pay_url';
	public const PAYNOW_CODE_DUE_DATE   = '_moksafowo_paynow_code_due_date'; // 代碼繳費 DueDate.
	public const PAYNOW_NEW_DATE        = '_moksafowo_paynow_new_date';
	public const PAYNOW_ERR_DESC        = '_moksafowo_paynow_err_desc';
	public const PAYNOW_NOTE            = '_moksafowo_paynow_note';

	public const AMEGO_INVOICE_NUMBER         = '_moksafowo_amego_invoice_number';
	public const AMEGO_INVOICE_ORDER_ID       = '_moksafowo_amego_invoice_order_id';
	public const AMEGO_INVOICE_ISSUED_AT      = '_moksafowo_amego_invoice_issued_at';
	public const AMEGO_INVOICE_RANDOM_NUM     = '_moksafowo_amego_invoice_random_num';
	public const AMEGO_INVOICE_BARCODE        = '_moksafowo_amego_invoice_barcode';
	public const AMEGO_INVOICE_QRCODE_L       = '_moksafowo_amego_invoice_qrcode_l';
	public const AMEGO_INVOICE_QRCODE_R       = '_moksafowo_amego_invoice_qrcode_r';
	public const AMEGO_INVOICE_INVALID_AT     = '_moksafowo_amego_invoice_invalid_at';
	public const AMEGO_INVOICE_INVALID_REASON = '_moksafowo_amego_invoice_invalid_reason';
	public const AMEGO_INVOICE_SCHEDULED_AT   = '_moksafowo_amego_invoice_scheduled_at';
	public const AMEGO_INVOICE_STATUS         = '_moksafowo_amego_invoice_status';
	public const AMEGO_INVOICE_ALLOWANCE_NO   = '_moksafowo_amego_invoice_allowance_no';
	public const AMEGO_INVOICE_ALLOWANCE_AMT  = '_moksafowo_amego_invoice_allowance_amt';

	public const EZPAY_INVOICE_ALLOWANCE_NO  = '_moksafowo_ezpay_invoice_allowance_no';
	public const EZPAY_INVOICE_ALLOWANCE_AMT = '_moksafowo_ezpay_invoice_allowance_amt';

	public const PAYNOW_INVOICE_NUMBER         = '_moksafowo_paynow_invoice_number';
	public const PAYNOW_INVOICE_ORDER_NO       = '_moksafowo_paynow_invoice_order_no';      // 送 API 的 orderno
	public const PAYNOW_INVOICE_ISSUED_AT      = '_moksafowo_paynow_invoice_issued_at';
	public const PAYNOW_INVOICE_INVALID_AT     = '_moksafowo_paynow_invoice_invalid_at';
	public const PAYNOW_INVOICE_INVALID_REASON = '_moksafowo_paynow_invoice_invalid_reason';
	public const PAYNOW_INVOICE_SCHEDULED_AT   = '_moksafowo_paynow_invoice_scheduled_at';
	public const PAYNOW_INVOICE_STATUS         = '_moksafowo_paynow_invoice_status';        // S | F | other API return code

	public const PCHOMEPAY_ORDER_ID         = '_moksafowo_pchomepay_order_id';
	public const PCHOMEPAY_PAY_TYPE         = '_moksafowo_pchomepay_pay_type';
	public const PCHOMEPAY_PAYMENT_URL      = '_moksafowo_pchomepay_payment_url';
	public const PCHOMEPAY_STATUS           = '_moksafowo_pchomepay_status';        // S|W|F.
	public const PCHOMEPAY_STATUS_CODE      = '_moksafowo_pchomepay_status_code';
	public const PCHOMEPAY_TRADE_AMOUNT     = '_moksafowo_pchomepay_trade_amount';
	public const PCHOMEPAY_PLATFORM_AMOUNT  = '_moksafowo_pchomepay_platform_amount';
	public const PCHOMEPAY_PP_FEE           = '_moksafowo_pchomepay_pp_fee';
	public const PCHOMEPAY_PAY_DATE         = '_moksafowo_pchomepay_pay_date';
	public const PCHOMEPAY_CARD_LAST4       = '_moksafowo_pchomepay_card_last4';
	public const PCHOMEPAY_CARD_NO_TOKEN    = '_moksafowo_pchomepay_card_no_token';
	public const PCHOMEPAY_VIRTUAL_ACCOUNT  = '_moksafowo_pchomepay_virtual_account';
	public const PCHOMEPAY_BANK_CODE        = '_moksafowo_pchomepay_bank_code';
	public const PCHOMEPAY_BARCODE_1        = '_moksafowo_pchomepay_barcode_1';
	public const PCHOMEPAY_BARCODE_2        = '_moksafowo_pchomepay_barcode_2';
	public const PCHOMEPAY_BARCODE_3        = '_moksafowo_pchomepay_barcode_3';
	public const PCHOMEPAY_PINCODE          = '_moksafowo_pchomepay_pincode';
	public const PCHOMEPAY_EXPIRE_DATE      = '_moksafowo_pchomepay_expire_date';
	public const PCHOMEPAY_LOGISTIC_ID      = '_moksafowo_pchomepay_logistic_id';
	public const PCHOMEPAY_LOGISTIC_STATUS  = '_moksafowo_pchomepay_logistic_status';
	public const PCHOMEPAY_PRINT_NO         = '_moksafowo_pchomepay_print_no';
	public const PCHOMEPAY_PRINT_URL        = '_moksafowo_pchomepay_print_url';

	public const SLP_SESSION_ID          = '_moksafowo_slp_session_id';          // 父層 hosted-checkout session id.
	public const SLP_TRADE_ORDER_ID      = '_moksafowo_slp_trade_order_id';      // 子層交易 id（退款操作對象）.
	public const SLP_SESSION_URL         = '_moksafowo_slp_session_url';         // session/create 回傳的跳轉 URL.
	public const SLP_REFERENCE_ID        = '_moksafowo_slp_reference_id';        // 我方送出的 referenceId（冪等對應）.
	public const SLP_STATUS              = '_moksafowo_slp_status';              // CREATED|PROCESSING|SUCCEEDED|FAILED|EXPIRED|CANCELLED|PENDING_REFUND|REFUNDED.
	public const SLP_SUB_STATUS          = '_moksafowo_slp_sub_status';          // 細部原因（3DS pending / issuer decline 等）.
	public const SLP_PAYMENT_METHOD      = '_moksafowo_slp_payment_method';      // 實際成交付款方式.
	public const SLP_REFUND_ORDER_ID     = '_moksafowo_slp_refund_order_id';     // refund/create 回傳的 refundOrderId.
	public const SLP_PROCESSED_EVENT_IDS = '_moksafowo_slp_processed_event_ids'; // webhook event.id 去重清單（array）.

	public const EZPAY_INVOICE_TRANS_NO   = '_moksafowo_ezpay_invoice_trans_no';
	public const EZPAY_INVOICE_NUMBER     = '_moksafowo_ezpay_invoice_number';
	public const EZPAY_RANDOM_NUM         = '_moksafowo_ezpay_random_num';
	public const EZPAY_CREATE_TIME        = '_moksafowo_ezpay_create_time';
	public const EZPAY_BARCODE            = '_moksafowo_ezpay_barcode';
	public const EZPAY_QRCODE_L           = '_moksafowo_ezpay_qrcode_l';
	public const EZPAY_QRCODE_R           = '_moksafowo_ezpay_qrcode_r';
	public const EZPAY_CATEGORY           = '_moksafowo_ezpay_category';            // B2B|B2C.
	public const EZPAY_TAX_TYPE           = '_moksafowo_ezpay_tax_type';
	public const EZPAY_STATUS             = '_moksafowo_ezpay_status';              // 1|0|3 issue mode.
	public const EZPAY_UPLOAD_STATUS      = '_moksafowo_ezpay_upload_status';       // synced from cron.
	public const EZPAY_INVALID_REASON     = '_moksafowo_ezpay_invalid_reason';
	public const EZPAY_INVALID_AT         = '_moksafowo_ezpay_invalid_at';
	public const EZPAY_ALLOWANCE_NO       = '_moksafowo_ezpay_allowance_no';
	public const EZPAY_ALLOWANCE_AMT      = '_moksafowo_ezpay_allowance_amt';
	public const EZPAY_MERCHANT_ORDER_NO  = '_moksafowo_ezpay_merchant_order_no'; // 送 API 的 MerchantOrderNo
	public const EZPAY_SCHEDULED_AT       = '_moksafowo_ezpay_scheduled_at';      // 延後開立排程時間（buffer dedupe）

	public const SMILEPAY_INVOICE_NUMBER     = '_moksafowo_smilepay_invoice_number';
	public const SMILEPAY_INVOICE_RANDOM     = '_moksafowo_smilepay_invoice_random';
	public const SMILEPAY_INVOICE_DATE       = '_moksafowo_smilepay_invoice_date';
	public const SMILEPAY_INVOICE_ORDER_ID   = '_moksafowo_smilepay_invoice_order_id';   // 送 API 的 orderid
	public const SMILEPAY_INVOICE_INVALID_AT = '_moksafowo_smilepay_invoice_invalid_at';
	public const SMILEPAY_INVOICE_INVALID_REASON = '_moksafowo_smilepay_invoice_invalid_reason';
	public const SMILEPAY_INVOICE_SCHEDULED_AT = '_moksafowo_smilepay_invoice_scheduled_at';

	public const TAPPAY_ORDER_NUMBER       = '_moksafowo_tappay_order_number';       // 送 pay-by-prime 的 order_number（冪等鍵）.
	public const TAPPAY_REC_TRADE_ID        = '_moksafowo_tappay_rec_trade_id';        // TapPay 交易主鍵（refund / query 用）.
	public const TAPPAY_BANK_TRANSACTION_ID = '_moksafowo_tappay_bank_transaction_id'; // 收單行交易序號.
	public const TAPPAY_AUTH_CODE           = '_moksafowo_tappay_auth_code';           // 授權碼.
	public const TAPPAY_CARD_LAST4          = '_moksafowo_tappay_card_last4';          // 卡號末四碼.
	public const TAPPAY_CARD_BIN            = '_moksafowo_tappay_card_bin';            // 卡號前六碼（BIN）.
	public const TAPPAY_CARD_ISSUER         = '_moksafowo_tappay_card_issuer';         // 發卡行.
	public const TAPPAY_TRANSACTION_STATUS  = '_moksafowo_tappay_transaction_status';  // pay-by-prime / notify 回傳 status.
	public const TAPPAY_PAYMENT_URL         = '_moksafowo_tappay_payment_url';         // 3DS 驗證跳轉 URL.
	public const TAPPAY_THREE_DOMAIN_SECURE = '_moksafowo_tappay_three_domain_secure'; // 是否走 3DS（yes|no）.
	public const TAPPAY_PAID_AT             = '_moksafowo_tappay_paid_at';             // 入帳時間（毫秒 epoch）.

	private function __construct() {}
}
