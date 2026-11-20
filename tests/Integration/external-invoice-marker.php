<?php
/**
 * 整合測試（測試站 wp eval-file -）：外部系統（ERP／POS）已開票的標記。
 *
 * 三種寫法都要當成「外部已開立」：
 * - A 換外掛前：ERP 在 POS 鏡像單寫 _ecpay_invoice_number=ERP開立、不寫 _ecpay_invoice_status（woomp 的鍵）。
 * - B 換外掛後：Moksa 的正式標記鍵 _moksafowo_invoice_external。
 * - C 兩個都寫（ERP 上線版的實際寫法），不寫 _moksafowo_ecpay_invoice_number。
 * 每種走四條退款路徑：wc_create_refund 全額；REST POST orders/{id}/refunds 全額與部分（ERP 退貨的做法，
 * api_refund=false、restock_items=false、帶品項，全額時 WC 自己轉 refunded）；REST 只把狀態改成 refunded
 * （後台手動改，WC 會自動補一筆全額退款紀錄）。都要只有 1 筆退款、不開折讓。
 * 每組都檢查：訂單頁與訂單列表不出現開立按鈕、自動開立與排程開立不開、不能作廢或折讓、
 * 退款後不打綠界發票 API、發票相關欄位前後一樣。
 *
 * 用一張專用測試訂單（帶 _moksafowo_test 與 _moksafowo_test_external_marker，付款方式銀行轉帳，
 * 第一次跑時建立，之後重用），不碰其他訂單。發票 API 一律攔截，不會連到綠界。
 *
 * @package Moksafowo
 */

defined( 'ABSPATH' ) || exit;

use Moksafowo\Modules\EcpayInvoice\Operations as Op;
use Moksafowo\Modules\Shared\Compat\LegacyOrderData;
use Moksafowo\Order\Meta\Keys;

$fail = 0;
$ok   = static function ( bool $pass, string $label ) use ( &$fail ): void {
	if ( $pass ) {
		WP_CLI::log( 'OK  ' . $label );
	} else {
		++$fail;
		WP_CLI::warning( 'NG  ' . $label );
	}
};

// 攔截所有綠界發票 API，記錄次數。
$calls = [];
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$calls ) {
		if ( false !== strpos( (string) $url, 'einvoice' ) ) {
			$calls[] = (string) $url;
			return new WP_Error( 'blocked', 'blocked by test' );
		}
		return $pre;
	},
	1,
	3
);
add_filter( 'pre_wp_mail', '__return_false', 999 );

$fresh_order = static function (): WC_Order {
	$ids = wc_get_orders(
		[
			'limit'    => 1,
			'meta_key' => '_moksafowo_test_external_marker',
			'return'   => 'ids',
		]
	);
	$o   = $ids ? wc_get_order( $ids[0] ) : null;
	if ( ! $o ) {
		$o = wc_create_order();
		$o->set_billing_first_name( '外部' );
		$o->set_billing_last_name( '測試' );
		$o->set_billing_email( 'invoice-test@example.com' );
		$o->set_payment_method( 'bacs' );
		$o->update_meta_data( '_moksafowo_test', 'yes' );
		$o->update_meta_data( '_moksafowo_test_external_marker', 'yes' );
		$o->calculate_totals( false );
		$o->save();
	}
	foreach ( $o->get_refunds() as $r ) {
		$r->delete( true );
	}
	if ( (float) $o->get_total() <= 0 ) {
		foreach ( array_keys( $o->get_items( 'fee' ) ) as $item_id ) {
			$o->remove_item( $item_id );
		}
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( '測試品項' );
		$fee->set_total( '300' );
		$o->add_item( $fee );
	}
	$o->calculate_totals( false );
	foreach ( [ '_ecpay_invoice_number', '_ecpay_invoice_status', Keys::INVOICE_EXTERNAL, Keys::ECPAY_INVOICE_NUMBER, Keys::ECPAY_INVOICE_SCHEDULED_AT, Keys::REFUND_FLOW, Keys::LEGACY_INVOICE_ALLOWANCES, Keys::INVOICE_LAST_ERROR ] as $k ) {
		$o->delete_meta_data( $k );
	}
	$o->update_meta_data( Keys::INVOICE_TYPE, 'b2c_carrier' );
	$o->update_meta_data( Keys::INVOICE_PROVIDER, 'ecpay' );
	$o->set_status( 'processing' );
	$o->set_date_paid( time() );
	$o->save();
	return wc_get_order( $o->get_id() );
};

$scenarios = [
	'A woomp 鍵 ERP開立（無 status）' => static function ( WC_Order $o ): void {
		$o->update_meta_data( '_ecpay_invoice_number', 'ERP開立' );
	},
	'B Moksa 正式標記鍵'             => static function ( WC_Order $o ): void {
		$o->update_meta_data( Keys::INVOICE_EXTERNAL, 'ERP開立' );
	},
	'C 兩個鍵都寫（ERP 現行寫法）'         => static function ( WC_Order $o ): void {
		$o->update_meta_data( '_ecpay_invoice_number', 'ERP開立' );
		$o->update_meta_data( Keys::INVOICE_EXTERNAL, 'ERP開立' );
	},
];

// 兩條退款路徑：後台／API 建全額退款單；ERP 退貨用 REST 直接把狀態改成 refunded（不建退款單）。
$admins = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);
$rest   = static function ( string $method, string $route, array $body ) use ( $admins ): void {
	$prev = get_current_user_id();
	wp_set_current_user( (int) $admins[0] );
	$req = new WP_REST_Request( $method, $route );
	$req->set_body_params( $body );
	$res = rest_do_request( $req );
	wp_set_current_user( $prev );
	if ( $res->is_error() ) {
		WP_CLI::warning( 'REST 回錯：' . wp_json_encode( $res->get_data(), JSON_UNESCAPED_UNICODE ) );
	}
};
// 路徑 => [ 做退款, 預期狀態 ]。
$paths = [
	'wc_create_refund 全額'  => [
		static function ( int $id ): void {
			wc_create_refund(
				[
					'order_id'       => $id,
					'amount'         => (string) wc_get_order( $id )->get_remaining_refund_amount(),
					'reason'         => 'external marker test',
					'refund_payment' => false,
				]
			);
		},
		'refunded',
	],
	// ERP 退貨的實際做法：POST orders/{id}/refunds，api_refund=false、restock_items=false，帶品項。
	'REST 建退款單 全額（ERP 退貨）' => [
		static function ( int $id ) use ( $rest ): void {
			$o    = wc_get_order( $id );
			$item = array_key_first( $o->get_items( 'fee' ) );
			$rest(
				'POST',
				'/wc/v3/orders/' . $id . '/refunds',
				[
					'amount'        => (string) $o->get_remaining_refund_amount(),
					'api_refund'    => false,
					'restock_items' => false,
					'line_items'    => [
						[
							'id'           => $item,
							'refund_total' => (float) $o->get_remaining_refund_amount(),
						],
					],
				]
			);
		},
		'refunded',
	],
	'REST 建退款單 部分（ERP 退貨）' => [
		static function ( int $id ) use ( $rest ): void {
			$item = array_key_first( wc_get_order( $id )->get_items( 'fee' ) );
			$rest(
				'POST',
				'/wc/v3/orders/' . $id . '/refunds',
				[
					'amount'        => '100',
					'api_refund'    => false,
					'restock_items' => false,
					'line_items'    => [
						[
							'id'           => $item,
							'refund_total' => 100,
						],
					],
				]
			);
		},
		'processing',
	],
	// 後台手動把狀態改成已退款（WC 會自動補一筆全額退款紀錄）。
	'REST 只改狀態'            => [
		static function ( int $id ) use ( $rest ): void {
			$rest( 'PUT', '/wc/v3/orders/' . $id, [ 'status' => 'refunded' ] );
		},
		'refunded',
	],
];

foreach ( $scenarios as $name => $mark ) {
	foreach ( $paths as $path_name => [ $do_refund, $want_status ] ) {
		$o = $fresh_order();
		$mark( $o );
		$o->save();
		$id    = $o->get_id();
		$calls = [];
		WP_CLI::log( "— {$name} × {$path_name}（訂單 #{$id}）" );

		$legacy = LegacyOrderData::invoice( wc_get_order( $id ) );
		$ok( null !== $legacy && 'external' === $legacy['source_key'] && ! LegacyOrderData::ecpay_actionable( $legacy ), '當成外部已開立、不可作廢折讓' );

		// 1. 不顯示開立按鈕（訂單頁、訂單列表）。
		$cards = \Moksafowo\Modules\EcpayInvoice\Admin\OrderMetaBox::add_card( [], wc_get_order( $id ) );
		$html  = implode( '', array_column( $cards, 'html' ) );
		$ok( false === strpos( $html, 'moksafowo-ecpay-invoice-issue' ) && false !== strpos( $html, 'ERP開立' ), '訂單頁沒有開立按鈕，顯示 ERP開立' );
		ob_start();
		\Moksafowo\Modules\Shared\Invoice\OrderListColumn::render_column( 'moksafowo_invoice_number', wc_get_order( $id ) );
		$cell = (string) ob_get_clean();
		$ok( false === strpos( $cell, 'moksafowo-invoice-list-issue' ), '訂單列表沒有開立按鈕（' . wp_strip_all_tags( $cell ) . '）' );

		// 2. 不自動開票（付款後、排程）。
		\Moksafowo\Modules\EcpayInvoice\Module::maybe_issue( $id );
		\Moksafowo\Modules\EcpayInvoice\Module::deferred_issue( $id );
		$ok( 0 === count( $calls ) && '' === (string) wc_get_order( $id )->get_meta( Keys::ECPAY_INVOICE_NUMBER ), '自動開立、排程開立都沒打 API' );

		// 3. 不能作廢、折讓。
		$v = Op\LegacyInvalid::run( wc_get_order( $id ), '測試' );
		$a = Op\LegacyAllowance::run( wc_get_order( $id ), 1 );
		$ok( empty( $v['ok'] ) && empty( $a['ok'] ) && 0 === count( $calls ), '作廢、折讓都拒絕，沒打 API' );

		// 4. 轉已退款：WC 自動建全額退款 → 退款發票同步、狀態自動作廢都不能打綠界。
		$snap   = static function () use ( $id ): array {
			$out = [];
			foreach ( wc_get_order( $id )->get_meta_data() as $m ) {
				if ( false !== strpos( $m->key, 'invoice' ) && false === strpos( $m->key, 'refund' ) ) {
					$out[ $m->key ] = maybe_serialize( $m->value );
				}
			}
			ksort( $out );
			return $out;
		};
		$before = $snap();
		$do_refund( $id );
		$o = wc_get_order( $id );
		$ok( $want_status === $o->get_status(), "訂單狀態 = {$want_status}（" . $o->get_status() . '）' );
		foreach ( $o->get_refunds() as $r ) {
			// 後台才載入 Refund\Guard（woocommerce_order_refunded → after_refund）；WP-CLI 這裡照它的步驟接上。
			if ( '' === (string) $r->get_meta( Keys::REFUND_INVOICE_STATE ) ) {
				\Moksafowo\Refund\Guard::after_refund( $id, $r->get_id() );
			}
			\Moksafowo\Refund\InvoiceSync::run( $r->get_id() );
		}
		Op\AutoInvalid::run( $id );
		Op\AutoInvalid::run( $id, true );
		$refunds = wc_get_order( $id )->get_refunds();
		$states  = array_map( static fn( $r ) => (string) $r->get_meta( Keys::REFUND_INVOICE_STATE ), $refunds );
		$ok( 1 === count( $refunds ) && 'todo_manual' === ( $states[0] ?? '' ), '只有 1 筆退款，發票動作列手動處理（' . count( $refunds ) . ' 筆：' . implode( ',', $states ) . '）' );
		$ok( 0 === count( $calls ), '退款後沒打綠界 API（沒開折讓、沒作廢）' );
		$ok( [] === Op\LegacyAllowance::allowances( wc_get_order( $id ) ), '沒有折讓紀錄' );
		$ok( $before === $snap(), '發票欄位退款前後一樣（' . count( $before ) . ' 個鍵）' );
		$cards = \Moksafowo\Modules\EcpayInvoice\Admin\OrderMetaBox::add_card( [], wc_get_order( $id ) );
		$ok( false === strpos( implode( '', array_column( $cards, 'html' ) ), 'moksafowo-ecpay-invoice-issue' ), '退款後訂單頁仍沒有開立按鈕' );
		if ( $calls ) {
			WP_CLI::log( '    打了：' . implode( ' ', $calls ) );
		}
	}
}

// 收尾：退款紀錄刪掉、標記清掉，訂單留著下次用。
$fresh_order();
0 === $fail ? WP_CLI::success( '全部通過' ) : WP_CLI::error( "{$fail} 項未通過" );
