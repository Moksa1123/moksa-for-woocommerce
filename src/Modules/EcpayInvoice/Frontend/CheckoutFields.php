<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\EcpayInvoice\Frontend;

use MoksaWeb\Mowc\Modules\EcpayInvoice\Api\Helper;
use MoksaWeb\Mowc\Modules\Shared\Invoice\InvoiceCheckoutFields;
use MoksaWeb\Mowc\Modules\Shared\Invoice\InvoiceFieldConfig;

defined( 'ABSPATH' ) || exit;

final class CheckoutFields {

	public static function init(): void {
		InvoiceCheckoutFields::boot(
			new InvoiceFieldConfig(
				provider_slug: 'ecpay',
				option_prefix: 'moksafowo_ecpay_invoice',
				member_label: __( '會員載具', 'mo-ectools' ),
				carrier_api_validator: [ self::class, 'validate_carrier_via_api' ],
			)
		);
	}

	/**
	 * 載具 / 愛心碼真驗 — 走 ECPay CheckBarcode / CheckLoveCode API 查財政部資料庫，
	 * 避免顧客輸入合法格式但偽造的載具導致後續開立發票失敗、訂單卡死。
	 * 經 InvoiceFieldConfig 注入 registrar（解耦：Helper 不進 Shared）。
	 */
	public static function validate_carrier_via_api( $data, $errors ): void {
		if ( ! ( $errors instanceof \WP_Error ) ) {
			return;
		}
		// 商家 opt-out。
		if ( 'yes' !== get_option( 'moksafowo_ecpay_invoice_carrier_api_check', 'yes' ) ) {
			return;
		}

		$type        = InvoiceCheckoutFields::field_value( $data, [ 'moksafowo_invoice_type', '_mowp/invoice-type', 'mowp/invoice-type' ] );
		$carrier     = InvoiceCheckoutFields::field_value( $data, [ 'moksafowo_invoice_carrier_type', '_mowp/invoice-carrier-type', 'mowp/invoice-carrier-type' ] );
		$carrier_num = InvoiceCheckoutFields::field_value(
			$data,
			[
				'moksafowo_invoice_carrier_num', // Classic 單一欄位
				'_mowp/invoice-mobile-barcode',
				'mowp/invoice-mobile-barcode',
				'_mowp/invoice-cert-code',
				'mowp/invoice-cert-code',
			]
		);
		$love_code   = InvoiceCheckoutFields::field_value( $data, [ 'moksafowo_invoice_love_code', '_mowp/invoice-love-code', 'mowp/invoice-love-code' ] );

		// 個人 + 手機條碼 → CheckBarcode。真驗失敗（exists=N）才擋；HTTP / 解密錯不擋（避免服務問題擋單）。
		if ( 'b2c_carrier' === $type && 'mobile' === $carrier && '' !== $carrier_num ) {
			$result = Helper::check_barcode( $carrier_num );
			if ( $result['ok'] && ! $result['exists'] ) {
				$errors->add( 'moksafowo_ecpay_invoice_barcode_invalid', $result['message'] );
			}
			// 格式錯誤（1040/1041）已由 validate_format + Block additional field 擋下，此處不重複報錯。
		}

		// 捐贈 → CheckLoveCode。
		if ( 'b2c_donate' === $type && '' !== $love_code ) {
			$result = Helper::check_love_code( $love_code );
			if ( $result['ok'] && ! $result['exists'] ) {
				$errors->add( 'moksafowo_ecpay_invoice_love_code_invalid', $result['message'] );
			}
			if ( ! $result['ok'] && in_array( $result['code'], [ '1020', '1021' ], true ) ) {
				$errors->add( 'moksafowo_ecpay_invoice_love_code_format', $result['message'] );
			}
		}
	}
}
