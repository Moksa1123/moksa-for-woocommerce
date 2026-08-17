<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\EcpayShipping\Settings;

use Moksafowo\Modules\EcpayShipping\Api\Helper;

defined( 'ABSPATH' ) || exit;

final class SettingsTab {

	public function get_settings(): array {
		return [
			[
				'title' => __( 'General settings', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Once the shipping credentials are in place, enable the shipping zones under WooCommerce → Settings → Shipping.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_section',
			],
			[
				'title'   => __( 'Enable test mode', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_shipping_sandbox_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'For use before going live. While this is ticked every shipment runs against the test environment and nothing is really dispatched. Untick it once you go live.', 'moksa-for-woocommerce' ),
			],
			// 這個選項決定 7-11 / 全家 / 萊爾富 / OK 走哪一組物流代碼，四個 Method 都讀它。
			// 早期版本有這個欄位，後來被移掉卻留下讀取端 —— 結果商家填了 B2C 憑證永遠用不到，
			// 而且沒有任何 UI 可以切回來。補回設定頁。
			[
				'title'    => __( 'Convenience store account type', 'moksa-for-woocommerce' ),
				'id'       => 'moksafowo_ecpay_shipping_cvs_type',
				'type'     => 'select',
				'default'  => 'C2C',
				'options'  => [
					'C2C' => __( 'C2C — store-to-store, no monthly fee (most common)', 'moksa-for-woocommerce' ),
					'B2C' => __( 'B2C — bulk warehouse, needs a separate contract and a monthly fee', 'moksa-for-woocommerce' ),
				],
				'desc'     => __( 'Which ECPay account your 7-ELEVEN, FamilyMart, Hi-Life and OK Mart shipments run through. Fill in the credentials for whichever one you pick below. Changing this only affects shipments created from now on; those already booked keep the account they were created with.', 'moksa-for-woocommerce' ),
				'desc_tip' => false,
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_shipping_section',
			],

			[
				'title' => __( 'Standard shipping account (C2C)', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'For individuals and small stores sending a few parcels store-to-store through 7-ELEVEN, FamilyMart, Hi-Life or OK Mart. <strong>No monthly fee</strong>, and by far the most common choice. Skip it if you have not applied.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_c2c_section',
			],
			[
				'title'   => __( 'Test merchant ID', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_shipping_c2c_sandbox_merchant_id',
				'type'    => 'text',
				'default' => Helper::SANDBOX_C2C_MERCHANT_ID,
			],
			[
				'title'   => __( 'Test hash key', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_shipping_c2c_sandbox_hash_key',
				'type'    => 'text',
				'default' => Helper::SANDBOX_C2C_HASH_KEY,
			],
			[
				'title'   => __( 'Test hash IV', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_shipping_c2c_sandbox_hash_iv',
				'type'    => 'text',
				'default' => Helper::SANDBOX_C2C_HASH_IV,
			],
			[
				'title' => __( 'Live merchant ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_c2c_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_c2c_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash IV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_c2c_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_shipping_c2c_section',
			],

			[
				'title' => __( 'Bulk shipping account (B2C)', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'For businesses sending in volume through 7-ELEVEN, FamilyMart and Hi-Life bulk pickup, as well as T-Cat, Chunghwa Post and Kerry TJ. <strong>Requires a monthly contract</strong>. Skip it if you have not applied.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_b2c_section',
			],
			[
				'title'   => __( 'Test merchant ID', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_shipping_b2c_sandbox_merchant_id',
				'type'    => 'text',
				'default' => Helper::SANDBOX_B2C_MERCHANT_ID,
			],
			[
				'title'   => __( 'Test hash key', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_shipping_b2c_sandbox_hash_key',
				'type'    => 'text',
				'default' => Helper::SANDBOX_B2C_HASH_KEY,
			],
			[
				'title'   => __( 'Test hash IV', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_shipping_b2c_sandbox_hash_iv',
				'type'    => 'text',
				'default' => Helper::SANDBOX_B2C_HASH_IV,
			],
			[
				'title' => __( 'Live merchant ID', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_b2c_merchant_id',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash key', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_b2c_hash_key',
				'type'  => 'text',
			],
			[
				'title' => __( 'Live hash IV', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_b2c_hash_iv',
				'type'  => 'text',
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_shipping_b2c_section',
			],

			[
				'title' => __( 'Sender details', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'The sender details printed on convenience store labels, and used by the carrier to reach you on home deliveries.', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_section',
			],
			[
				'title' => __( 'Name', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_name',
				'type'  => 'text',
				'desc'  => __( '1 to 10 Chinese characters, or 1 to 20 Latin characters.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Phone', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_phone',
				'type'  => 'text',
				'desc'  => __( 'Landline number.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Mobile', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_cellphone',
				'type'  => 'text',
				'desc'  => __( 'Required for home delivery and T-Cat.', 'moksa-for-woocommerce' ),
			],
			[
				'title' => __( 'Email', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_email',
				'type'  => 'email',
			],
			[
				'title' => __( 'Sender address', 'moksa-for-woocommerce' ),
				'id'    => 'moksafowo_ecpay_shipping_sender_address',
				'type'  => 'textarea',
				'desc'  => __( 'Used for home delivery. Convenience store shipping does not need it.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_shipping_sender_section',
			],

			[
				'title' => __( 'Other', 'moksa-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'moksafowo_ecpay_shipping_misc_section',
			],
			[
				'title'   => __( 'Debug log', 'moksa-for-woocommerce' ),
				'id'      => 'moksafowo_ecpay_shipping_debug_log_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Turn this on when investigating a problem shipment.', 'moksa-for-woocommerce' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'moksafowo_ecpay_shipping_misc_section',
			],
		];
	}
}
