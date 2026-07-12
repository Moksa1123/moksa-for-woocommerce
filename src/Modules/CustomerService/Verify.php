<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\CustomerService;

defined( 'ABSPATH' ) || exit;

/**
 * 顧客身分二次驗證 —— 訂單編號 + 帳單電話末三碼。
 *
 * 敵意環境(未登入訪客)安全核心:
 * - 末三碼僅 1000 組 → 以「IP + 訂單號」雙鍵節流,達上限即鎖定。
 * - 不論「訂單不存在 / 末三碼錯 / 已鎖定」一律回統一失敗(防 enumeration / oracle)。
 * - 驗證通過發短時效 token(transient,綁該訂單),後續查詢帶 token 免重驗。
 */
final class Verify {

	const TOKEN_TTL_MINUTES = 30;

	public static function max_attempts(): int {
		return max( 1, (int) get_option( 'moksafowo_customer_service_max_attempts', 5 ) );
	}

	public static function lockout_seconds(): int {
		return max( 60, (int) get_option( 'moksafowo_customer_service_lockout_minutes', 60 ) * MINUTE_IN_SECONDS );
	}

	/**
	 * 全局每 IP 每小時失敗上限 —— 防攻擊者逐一換訂單號繞過「IP+訂單號」桶。
	 */
	public static function max_ip_attempts(): int {
		return max( self::max_attempts() * 4, 30 );
	}

	/**
	 * 嘗試驗證。回傳成功時 ['ok'=>true,'token'=>..,'order_id'=>..]；
	 * 失敗(任何原因)一律 ['ok'=>false]。
	 *
	 * @param string $order_ref 顧客輸入的訂單編號。
	 * @param string $phone3    電話末三碼。
	 * @param string $ip        來源 IP。
	 * @return array{ok:bool, token?:string, order_id?:int}
	 */
	public static function attempt( string $order_ref, string $phone3, string $ip ): array {
		$fail   = array( 'ok' => false );
		$bucket = self::bucket_key( $ip, $order_ref );
		$count  = (int) get_transient( $bucket );

		$ip_bucket = self::ip_bucket_key( $ip );
		$ip_count  = (int) get_transient( $ip_bucket );
		if ( $ip_count >= self::max_ip_attempts() ) {
			return $fail;
		}

		if ( $count >= self::max_attempts() ) {
			return $fail;
		}

		$ok       = false;
		$order_id = self::resolve_order_id( $order_ref );
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order && 'shop_order' === $order->get_type() ) {
				$phone = preg_replace( '/\D/', '', (string) $order->get_billing_phone() );
				$in3   = preg_replace( '/\D/', '', $phone3 );
				if ( strlen( (string) $phone ) >= 3 && 3 === strlen( (string) $in3 )
					&& hash_equals( substr( (string) $phone, -3 ), (string) $in3 ) ) {
					$ok = true;
				}
			}
		}

		if ( ! $ok ) {
			set_transient( $bucket, $count + 1, self::lockout_seconds() );
			set_transient( $ip_bucket, $ip_count + 1, HOUR_IN_SECONDS );
			return $fail;
		}

		$token = wp_generate_password( 40, false );
		set_transient( self::token_key( $token ), $order_id, self::TOKEN_TTL_MINUTES * MINUTE_IN_SECONDS );

		return array(
			'ok'       => true,
			'token'    => $token,
			'order_id' => $order_id,
		);
	}

	/**
	 * 用 token 取回已驗證的訂單 id（0 = 無效 / 過期）。
	 */
	public static function order_for_token( string $token ): int {
		if ( '' === $token ) {
			return 0;
		}
		$oid = get_transient( self::token_key( $token ) );
		return $oid ? (int) $oid : 0;
	}

	private static function resolve_order_id( string $ref ): int {
		$id = absint( preg_replace( '/\D/', '', $ref ) );
		if ( $id <= 0 ) {
			return 0;
		}
		$order = wc_get_order( $id );
		return ( $order instanceof \WC_Order && 'shop_order' === $order->get_type() ) ? $id : 0;
	}

	private static function bucket_key( string $ip, string $ref ): string {
		return 'moksafowo_cs_try_' . md5( $ip . '|' . preg_replace( '/\D/', '', $ref ) );
	}

	private static function ip_bucket_key( string $ip ): string {
		return 'moksafowo_cs_ipthr_' . md5( $ip );
	}

	private static function token_key( string $token ): string {
		return 'moksafowo_cs_tok_' . hash( 'sha256', $token );
	}
}
