<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Address;

defined( 'ABSPATH' ) || exit;

/**
 * 把 PhoneRule 接到三條真的會有人輸入電話的路徑上：
 *
 *   古典結帳     woocommerce_checkout_posted_data（正規化）
 *                woocommerce_after_checkout_validation（檢查）
 *   區塊結帳     woocommerce_blocks_validate_location_address_fields（檢查）
 *                woocommerce_store_api_checkout_update_order_from_request（正規化寫進訂單）
 *   我的帳號     woocommerce_after_save_address_validation
 *
 * 區塊那條為什麼要分兩個 hook：驗證那個 action 只給 WP_Error 讓你加錯誤，
 * 不能改值；要讓訂單裡存的是洗過的號碼，得在建立訂單時另外寫一次。
 */
final class PhoneValidator {

	public static function init(): void {
		// 古典
		add_filter( 'woocommerce_checkout_posted_data', [ __CLASS__, 'normalize_posted_data' ] );
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate_classic' ], 10, 2 );

		// 區塊
		add_action( 'woocommerce_blocks_validate_location_address_fields', [ __CLASS__, 'validate_block_address' ], 10, 3 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'normalize_order' ], 10, 1 );

		// 我的帳號 → 地址
		add_action( 'woocommerce_after_save_address_validation', [ __CLASS__, 'validate_account_address' ], 10, 4 );
	}

	/**
	 * @param array<string,mixed> $data 結帳送出的欄位。
	 * @return array<string,mixed>
	 */
	public static function normalize_posted_data( array $data ): array {
		foreach ( [ 'billing_phone', 'shipping_phone' ] as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$data[ $key ] = PhoneRule::normalize( $data[ $key ] );
			}
		}
		return $data;
	}

	/**
	 * @param array<string,mixed> $data   已正規化的結帳資料。
	 * @param \WP_Error           $errors 錯誤集合。
	 */
	public static function validate_classic( $data, $errors ): void {
		if ( ! $errors instanceof \WP_Error || ! is_array( $data ) ) {
			return;
		}
		// 空值要不要擋由「必填」設定決定，這裡只管有填的格式對不對。
		foreach ( [ 'billing_phone', 'shipping_phone' ] as $key ) {
			$value = isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : '';
			if ( '' === $value ) {
				continue;
			}
			if ( ! PhoneRule::is_valid( PhoneRule::normalize( $value ) ) ) {
				$errors->add( 'moksafowo_phone', PhoneRule::message() );
				return;
			}
		}
	}

	/**
	 * @param \WP_Error           $errors 錯誤集合。
	 * @param array<string,mixed> $fields 該區塊的欄位值。
	 * @param string              $group  shipping / billing / other。
	 */
	public static function validate_block_address( $errors, $fields, $group ): void {
		if ( ! $errors instanceof \WP_Error || ! is_array( $fields ) ) {
			return;
		}
		$value = isset( $fields['phone'] ) && is_string( $fields['phone'] ) ? $fields['phone'] : '';
		if ( '' === $value ) {
			return;
		}
		if ( ! PhoneRule::is_valid( PhoneRule::normalize( $value ) ) ) {
			$errors->add( 'moksafowo_phone_' . sanitize_key( (string) $group ), PhoneRule::message() );
		}
	}

	/** @param \WC_Order $order 剛由 Store API 建好的訂單。 */
	public static function normalize_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$billing = (string) $order->get_billing_phone();
		if ( '' !== $billing ) {
			$order->set_billing_phone( PhoneRule::normalize( $billing ) );
		}
		$shipping = (string) $order->get_shipping_phone();
		if ( '' !== $shipping ) {
			$order->set_shipping_phone( PhoneRule::normalize( $shipping ) );
		}
	}

	/**
	 * @param int          $user_id      使用者 ID。
	 * @param string       $address_type billing / shipping。
	 * @param array<mixed> $address      送出的地址。
	 * @param \WC_Customer $customer     顧客物件。
	 */
	public static function validate_account_address( $user_id, $address_type, $address, $customer ): void {
		$key   = $address_type . '_phone';
		$value = isset( $address[ $key ] ) && is_string( $address[ $key ] ) ? $address[ $key ] : '';
		if ( '' === $value ) {
			return;
		}
		$clean = PhoneRule::normalize( $value );
		if ( ! PhoneRule::is_valid( $clean ) ) {
			wc_add_notice( PhoneRule::message(), 'error' );
			return;
		}
		if ( $clean !== $value && $customer instanceof \WC_Customer ) {
			$setter = 'set_' . $key;
			if ( method_exists( $customer, $setter ) ) {
				$customer->{$setter}( $clean );
			}
		}
	}
}
