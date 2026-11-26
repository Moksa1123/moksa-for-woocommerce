<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Returns;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Utils\GoodsType;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;

defined( 'ABSPATH' ) || exit;

/**
 * 訂單頁的「退貨」卡片：建立 7-11 退貨便、預約黑貓收退件、重新查詢退貨貨態。
 *
 * 只在統一金流出貨的訂單上顯示；兩種退貨各有開關（預設關閉，要先向統一金流申請開通）。
 * 退貨不改訂單狀態，也不自動退款。
 */
final class Admin {

	public const OPTION_C2B  = 'moksafowo_payuni_return_c2b_enabled';
	public const OPTION_HOME = 'moksafowo_payuni_return_home_enabled';

	private const NONCE = 'moksafowo-payuni-return';
	private const LOCK  = 'moksafowo_payuni_return_lock_';

	/** 鎖的時效要比呼叫統一金流的逾時（45 秒）長。 */
	private const LOCK_TTL = 120;
	private const SCRIPT   = 'moksafowo-payuni-returns-admin';

	public static function init(): void {
		add_filter( 'moksafowo_order_info_cards', [ __CLASS__, 'add_card' ], 25, 2 );
		add_action( 'wp_ajax_moksafowo_payuni_return_create', [ __CLASS__, 'ajax_create' ] );
		add_action( 'wp_ajax_moksafowo_payuni_return_query', [ __CLASS__, 'ajax_query' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function c2b_enabled(): bool {
		return 'yes' === get_option( self::OPTION_C2B, 'no' );
	}

	public static function home_enabled(): bool {
		return 'yes' === get_option( self::OPTION_HOME, 'no' );
	}

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		// HPOS 的訂單列表與編輯頁是同一個畫面 id，只在編輯頁載入。
		if ( 'woocommerce_page_wc-orders' === $screen->id && 'edit' !== sanitize_key( \Moksafowo\Http\Input::get( 'action' ) ) ) {
			return;
		}
		$path = 'src/Modules/PayuniShipping/assets/js/returns-admin.js';
		wp_enqueue_script( self::SCRIPT, MOKSAFOWO_PLUGIN_URL . $path, [ 'jquery' ], (string) filemtime( MOKSAFOWO_PLUGIN_DIR . $path ), true );
		wp_localize_script(
			self::SCRIPT,
			'moksafowo_payuni_returns',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'i18n'     => [
					'working' => __( 'Sending to PAYUNi…', 'moksa-for-woocommerce' ),
					'failed'  => __( 'The request failed. Please try again.', 'moksa-for-woocommerce' ),
					'confirm' => __( 'PAYUNi has no way to cancel a return once it is created. Create it now?', 'moksa-for-woocommerce' ),
				],
			]
		);
	}

	public static function is_payuni_order( \WC_Order $order ): bool {
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Shipping && PayuniShipping::is_payuni_shipping( $item->get_method_id() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $cards 訂單頁的資訊卡片。
	 * @param \WC_Order                        $order 訂單。
	 * @return array<int, array<string, mixed>>
	 */
	public static function add_card( array $cards, \WC_Order $order ): array {
		if ( ! self::is_payuni_order( $order ) ) {
			return $cards;
		}
		$records = Store::all( $order );
		if ( empty( $records ) && ! self::c2b_enabled() && ! self::home_enabled() ) {
			return $cards;
		}
		$html = self::card_html( $order, $records );
		// 同一個位置只顯示最後一張卡片，所以接在現有的物流卡片後面；沒有物流卡片時自己開一張。
		foreach ( array_reverse( array_keys( $cards ) ) as $i ) {
			if ( 'shipping' === ( $cards[ $i ]['slot'] ?? '' ) && '' !== (string) ( $cards[ $i ]['html'] ?? '' ) ) {
				$heading             = '<p style="margin:12px 0 6px;padding-top:10px;border-top:1px solid #dcdcde;font-weight:600;">' . esc_html__( 'Returns', 'moksa-for-woocommerce' ) . '</p>';
				$cards[ $i ]['html'] = (string) $cards[ $i ]['html'] . $heading . $html;
				return $cards;
			}
		}
		$cards[] = [
			'slot'  => 'shipping',
			'title' => __( 'Returns', 'moksa-for-woocommerce' ),
			'html'  => $html,
		];
		return $cards;
	}

	/**
	 * @param \WC_Order                        $order   訂單。
	 * @param array<int, array<string, mixed>> $records 退貨紀錄。
	 */
	private static function card_html( \WC_Order $order, array $records ): string {
		$oid = (int) $order->get_id();
		ob_start();
		echo '<div class="moksafowo-payuni-returns" data-order-id="' . esc_attr( (string) $oid ) . '" style="font-size:12px;">';

		if ( empty( $records ) ) {
			echo '<p style="margin:0 0 8px;color:#646970;">' . esc_html__( 'No returns yet.', 'moksa-for-woocommerce' ) . '</p>';
		}
		foreach ( $records as $record ) {
			self::record_html( $record );
		}

		echo '<div class="moksafowo-payuni-return-message" style="margin:6px 0;"></div>';

		if ( self::c2b_enabled() ) {
			self::c2b_form_html( $order );
		}
		if ( self::home_enabled() ) {
			self::home_form_html( $order );
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $record 一筆退貨紀錄。
	 */
	private static function record_html( array $record ): void {
		$is_c2b = Store::TYPE_C2B === ( $record['type'] ?? '' );
		$rows   = [];
		if ( $is_c2b ) {
			$rows[ __( 'Return code', 'moksa-for-woocommerce' ) ] = self::format_code( (string) ( $record['ref'] ?? '' ) );
			$rows[ __( 'Use before', 'moksa-for-woocommerce' ) ]  = (string) ( $record['deadline'] ?? '' );
			$rows[ __( 'Payment', 'moksa-for-woocommerce' ) ]     = '4' === (string) ( $record['service_type'] ?? '' )
				/* translators: %s: amount collected at the store */
				? sprintf( __( 'Customer pays NT$%s at the store', 'moksa-for-woocommerce' ), (string) ( $record['ship_amt'] ?? '' ) )
				: __( 'Free for the customer', 'moksa-for-woocommerce' );
		} else {
			$rows[ __( 'Return number', 'moksa-for-woocommerce' ) ]   = (string) ( $record['obt_number'] ?? '' );
			$rows[ __( 'Pickup date', 'moksa-for-woocommerce' ) ]     = (string) ( $record['ship_date'] ?? '' );
			$rows[ __( 'Shipment number', 'moksa-for-woocommerce' ) ] = (string) ( $record['ref'] ?? '' );
		}
		$status = (string) ( $record['status_desc'] ?? '' );
		if ( '' === $status || '-' === $status ) {
			$status = __( 'Not sent yet', 'moksa-for-woocommerce' );
		}
		$rows[ __( 'Status', 'moksa-for-woocommerce' ) ]         = $status;
		$rows[ __( 'Status updated', 'moksa-for-woocommerce' ) ] = '-' === (string) ( $record['status_time'] ?? '' ) ? '' : (string) ( $record['status_time'] ?? '' );

		echo '<div style="border:1px solid #dcdcde;border-radius:4px;padding:6px 8px;margin-bottom:8px;">';
		echo '<div style="font-weight:600;margin-bottom:4px;">' . esc_html( $is_c2b ? __( '7-ELEVEN return code', 'moksa-for-woocommerce' ) : __( 'T-Cat return pickup', 'moksa-for-woocommerce' ) ) . '</div>';
		echo '<table style="width:100%;table-layout:fixed;">';
		foreach ( $rows as $label => $value ) {
			if ( '' === trim( $value ) ) {
				continue;
			}
			echo '<tr><th style="text-align:left;width:40%;">' . esc_html( $label ) . '</th><td style="word-break:break-all;">' . esc_html( $value ) . '</td></tr>';
		}
		echo '</table>';
		echo '<button type="button" class="button button-small moksafowo-payuni-return-query" data-ref="' . esc_attr( (string) ( $record['ref'] ?? '' ) ) . '" style="margin-top:4px;">' . esc_html__( 'Check status', 'moksa-for-woocommerce' ) . '</button>';
		echo '</div>';
	}

	private static function c2b_form_html( \WC_Order $order ): void {
		$amount   = self::clamp( (int) round( (float) $order->get_total() - (float) $order->get_total_refunded() ), 1, 20000 );
		$name     = self::shopper_name( trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() ) );
		$original = self::original_c2b_shipment( $order );

		echo '<details style="margin-top:6px;"><summary style="cursor:pointer;font-weight:600;">' . esc_html__( 'Create a 7-ELEVEN return code', 'moksa-for-woocommerce' ) . '</summary>';
		echo '<div class="moksafowo-payuni-return-form" data-type="' . esc_attr( Store::TYPE_C2B ) . '" style="margin-top:6px;display:grid;gap:6px;">';
		echo '<label>' . esc_html__( 'Return shipping fee', 'moksa-for-woocommerce' ) . '<br/><select data-field="service_type">'
			. '<option value="5">' . esc_html__( 'Free for the customer', 'moksa-for-woocommerce' ) . '</option>'
			. '<option value="4">' . esc_html__( 'Customer pays at the store', 'moksa-for-woocommerce' ) . '</option>'
			. '</select></label>';
		echo '<label>' . esc_html__( 'Amount the customer pays (NT$1–999)', 'moksa-for-woocommerce' ) . '<br/><input type="number" data-field="ship_amt" min="1" max="999" value="" style="width:100%;" /></label>';
		echo '<label>' . esc_html__( 'Value of the returned goods (NT$1–20000)', 'moksa-for-woocommerce' ) . '<br/><input type="number" data-field="trade_amt" min="1" max="20000" value="' . esc_attr( (string) $amount ) . '" style="width:100%;" /></label>';
		if ( '' !== $original ) {
			echo '<label><input type="checkbox" data-field="link_original" value="1" checked /> ' . esc_html__( 'Link to the original shipment (PAYUNi uses its recipient as the sender)', 'moksa-for-woocommerce' ) . '</label>';
		}
		echo '<label>' . esc_html__( 'Customer name (up to 5 Chinese or 10 English characters)', 'moksa-for-woocommerce' ) . '<br/><input type="text" data-field="shopper_name" value="' . esc_attr( $name ) . '" style="width:100%;" /></label>';
		echo '<label><input type="checkbox" data-field="notify" value="1" checked /> ' . esc_html__( 'Email the return code to the customer', 'moksa-for-woocommerce' ) . '</label>';
		echo '<button type="button" class="button button-primary moksafowo-payuni-return-submit">' . esc_html__( 'Create return code', 'moksa-for-woocommerce' ) . '</button>';
		echo '</div></details>';
	}

	private static function home_form_html( \WC_Order $order ): void {
		$goods = (string) $order->get_meta( OrderMeta::GoodsType );
		if ( ! in_array( $goods, [ GoodsType::NORMAL, GoodsType::FROZEN, GoodsType::REFRIGERATED ], true ) ) {
			$goods = GoodsType::NORMAL;
		}
		$spec = (string) $order->get_meta( OrderMeta::PackageSpec );
		if ( ! in_array( $spec, [ '1', '2', '3', '4' ], true ) ) {
			$spec = '1';
		}
		$first_item = '';
		foreach ( $order->get_items() as $item ) {
			$first_item = $item->get_name();
			break;
		}

		echo '<details style="margin-top:6px;"><summary style="cursor:pointer;font-weight:600;">' . esc_html__( 'Book a T-Cat return pickup', 'moksa-for-woocommerce' ) . '</summary>';
		echo '<div class="moksafowo-payuni-return-form" data-type="' . esc_attr( Store::TYPE_HOME ) . '" style="margin-top:6px;display:grid;gap:6px;">';
		self::input( 'consignor', __( 'Customer name', 'moksa-for-woocommerce' ), trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() ) );
		self::input( 'consignor_mobile', __( 'Customer mobile (09…)', 'moksa-for-woocommerce' ), self::mobile( $order ) );
		self::input( 'consignor_address', __( 'Pickup address', 'moksa-for-woocommerce' ), self::address( $order ) );

		echo '<label>' . esc_html__( 'Pickup date', 'moksa-for-woocommerce' ) . '<br/><select data-field="ship_date">';
		foreach ( self::pickup_dates() as $date ) {
			echo '<option value="' . esc_attr( $date ) . '">' . esc_html( substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 ) ) . '</option>';
		}
		echo '</select></label>';

		echo '<label>' . esc_html__( 'Delivery window', 'moksa-for-woocommerce' ) . '<br/><select data-field="time_tag">';
		foreach ( self::time_tags() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( '04', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';

		echo '<label>' . esc_html__( 'Temperature', 'moksa-for-woocommerce' ) . '<br/><select data-field="goods_type">';
		foreach ( [ GoodsType::NORMAL, GoodsType::FROZEN, GoodsType::REFRIGERATED ] as $g ) {
			echo '<option value="' . esc_attr( $g ) . '"' . selected( $goods, $g, false ) . '>' . esc_html( GoodsType::get_name( $g ) ) . '</option>';
		}
		echo '</select></label>';

		echo '<label>' . esc_html__( 'Parcel size', 'moksa-for-woocommerce' ) . '<br/><select data-field="spec">';
		foreach ( [
			'1' => '60cm',
			'2' => '90cm',
			'3' => '120cm',
			'4' => '150cm',
		] as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $spec, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';

		self::input( 'prod_desc', __( 'Item description (up to 10 Chinese characters)', 'moksa-for-woocommerce' ), mb_strimwidth( $first_item, 0, 20, '', 'UTF-8' ) );
		self::input( 'memo', __( 'Note for the driver (optional)', 'moksa-for-woocommerce' ), '' );
		echo '<label><input type="checkbox" data-field="notify" value="1" checked /> ' . esc_html__( 'Email the pickup details to the customer', 'moksa-for-woocommerce' ) . '</label>';
		echo '<button type="button" class="button button-primary moksafowo-payuni-return-submit">' . esc_html__( 'Book pickup', 'moksa-for-woocommerce' ) . '</button>';
		echo '</div></details>';
	}

	private static function input( string $name, string $label, string $value ): void {
		echo '<label>' . esc_html( $label ) . '<br/><input type="text" data-field="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" style="width:100%;" /></label>';
	}

	public static function ajax_create(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'moksa-for-woocommerce' ) ], 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'security', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid security token sent.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$type     = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$input    = [];
		foreach ( [ 'service_type', 'ship_amt', 'trade_amt', 'link_original', 'shopper_name', 'notify', 'consignor', 'consignor_mobile', 'consignor_address', 'ship_date', 'time_tag', 'goods_type', 'spec', 'prod_desc', 'memo' ] as $key ) {
			$input[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		$order = self::order_or_fail( $order_id );

		if ( ! ( Store::TYPE_C2B === $type && self::c2b_enabled() ) && ! ( Store::TYPE_HOME === $type && self::home_enabled() ) ) {
			wp_send_json_error( [ 'message' => __( 'This return type is not enabled.', 'moksa-for-woocommerce' ) ], 400 );
		}
		if ( ! self::acquire_lock( $order_id ) ) {
			wp_send_json_error( [ 'message' => __( 'A return was just requested for this order. Wait two minutes, reload the page and check whether it was created before trying again.', 'moksa-for-woocommerce' ) ], 429 );
		}

		$result = Store::TYPE_C2B === $type ? self::create_c2b( $order, $input ) : self::create_home( $order, $input );

		if ( is_wp_error( $result ) ) {
			// 請求可能已經送到統一金流（逾時、回應驗不過）時保留鎖，避免重按又建一張；鎖會自己過期。
			if ( Api::UNCERTAIN === $result->get_error_code() ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() . ' ' . __( 'PAYUNi may have received the request. Check in the PAYUNi dashboard whether the return was created before trying again in two minutes.', 'moksa-for-woocommerce' ) ] );
			}
			self::release_lock( $order_id );
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		self::release_lock( $order_id );
		wp_send_json_success();
	}

	/**
	 * 每張訂單同時只能有一個建立退貨的請求（統一金流沒有取消 API）。
	 * add_option 在資料庫層是原子的：同名已存在時回 false；超過時效的舊鎖清掉再搶一次。
	 * 名稱以 .lock 結尾，不會被記進解除安裝要清的設定清單。
	 */
	private static function acquire_lock( int $order_id ): bool {
		$name = self::LOCK . $order_id . '.lock';
		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}
		if ( (int) get_option( $name, 0 ) < time() - self::LOCK_TTL ) {
			delete_option( $name );
			return add_option( $name, time(), '', false );
		}
		return false;
	}

	private static function release_lock( int $order_id ): void {
		delete_option( self::LOCK . $order_id . '.lock' );
	}

	public static function ajax_query(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'moksa-for-woocommerce' ) ], 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'security', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid security token sent.', 'moksa-for-woocommerce' ) ], 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$ref      = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
		$order    = self::order_or_fail( $order_id );
		$record   = Store::find( $order, $ref );
		if ( null === $record ) {
			wp_send_json_error( [ 'message' => __( 'The return could not be found on this order.', 'moksa-for-woocommerce' ) ], 404 );
		}
		$resp = Api::query( $record );
		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( [ 'message' => $resp->get_error_message() ] );
		}
		Notice::apply( $order, $record, $resp );
		wp_send_json_success();
	}

	/**
	 * 訂單要存在且是統一金流出貨。
	 */
	private static function order_or_fail( int $order_id ): \WC_Order {
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || ! self::is_payuni_order( $order ) ) {
			wp_send_json_error( [ 'message' => __( 'The order could not be found.', 'moksa-for-woocommerce' ) ], 404 );
		}
		return $order;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function create_c2b( \WC_Order $order, array $input ) {
		$service_type = '4' === $input['service_type'] ? 4 : 5;
		$ship_amt     = absint( $input['ship_amt'] );
		$trade_amt    = absint( $input['trade_amt'] );
		$link         = '1' === $input['link_original'];
		$name         = $input['shopper_name'];
		$notify       = '1' === $input['notify'];

		if ( 4 === $service_type && ( $ship_amt < 1 || $ship_amt > 999 ) ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'Enter an amount between NT$1 and NT$999 for the customer to pay.', 'moksa-for-woocommerce' ) );
		}
		if ( $trade_amt < 1 || $trade_amt > 20000 ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'The value of the returned goods must be between NT$1 and NT$20000.', 'moksa-for-woocommerce' ) );
		}
		$original = $link ? self::original_c2b_shipment( $order ) : '';
		if ( mb_strwidth( $name, 'UTF-8' ) > 10 ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'The customer name is too long: up to 5 Chinese or 10 English characters.', 'moksa-for-woocommerce' ) );
		}
		if ( '' === $original && '' === $name ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'Enter the customer name.', 'moksa-for-woocommerce' ) );
		}

		$resp = Api::create_c2b(
			[
				'service_type'  => $service_type,
				'trade_amt'     => $trade_amt,
				'ship_amt'      => 4 === $service_type ? $ship_amt : 0,
				'shopper_name'  => '' === $original ? $name : '',
				'ship_trade_no' => $original,
			]
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$record = [
			'type'          => Store::TYPE_C2B,
			'ref'           => $resp['RefundODNO'] . $resp['ValidationNo'],
			'trade_no'      => $resp['TradeNo'] ?? '',
			'ship_trade_no' => $resp['ShipTradeNo'] ?? '',
			'service_type'  => (string) $service_type,
			'trade_amt'     => $resp['TradeAmt'] ?? (string) $trade_amt,
			'ship_amt'      => $resp['ShipAmt'] ?? (string) ( 4 === $service_type ? $ship_amt : 0 ),
			'deadline'      => $resp['DeadlineDate'] ?? '',
			'status'        => '',
			'status_desc'   => '',
			'status_time'   => '',
			'created_at'    => current_time( 'mysql' ),
			'created_by'    => get_current_user_id(),
		];
		// 等統一金流回應期間可能有通知寫進訂單，重新讀一次再寫，避免蓋掉。
		$fresh = wc_get_order( $order->get_id() );
		$order = $fresh instanceof \WC_Order ? $fresh : $order;
		Store::add( $order, $record );
		$order->save();

		$code = self::format_code( $record['ref'] );
		$order->add_order_note(
			\Moksafowo\Order\Note::build(
				__( 'Created a 7-ELEVEN return code with PAYUNi.', 'moksa-for-woocommerce' ),
				[
					__( 'Return code', 'moksa-for-woocommerce' ) => $code,
					__( 'Use before', 'moksa-for-woocommerce' )  => $record['deadline'],
					__( 'Payment', 'moksa-for-woocommerce' )     => 4 === $service_type
						/* translators: %s: amount collected at the store */
						? sprintf( __( 'Customer pays NT$%s at the store', 'moksa-for-woocommerce' ), $record['ship_amt'] )
						: __( 'Free for the customer', 'moksa-for-woocommerce' ),
				]
			),
			0,
			true
		);
		if ( $notify ) {
			$text = sprintf(
				/* translators: 1: 12-digit return code, 2: deadline */
				__( 'Your 7-ELEVEN return code is %1$s. Before %2$s, enter this code at the ibon kiosk in any 7-ELEVEN store to print the return slip, then hand the parcel and the slip to the counter.', 'moksa-for-woocommerce' ),
				$code,
				$record['deadline']
			);
			if ( 4 === $service_type ) {
				/* translators: %s: amount */
				$text .= ' ' . sprintf( __( 'You will pay NT$%s at the counter.', 'moksa-for-woocommerce' ), $record['ship_amt'] );
			}
			$order->add_order_note( $text, 1, true );
		}
		PayuniShipping::log( 'PAYUNi return code created for order ' . $order->get_id() );
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function create_home( \WC_Order $order, array $input ) {
		$get = static fn( string $k ): string => (string) ( $input[ $k ] ?? '' );

		$consignor = $get( 'consignor' );
		$mobile    = preg_replace( '/[^0-9]/', '', $get( 'consignor_mobile' ) ) ?? '';
		$address   = $get( 'consignor_address' );
		$ship_date = $get( 'ship_date' );
		$time_tag  = $get( 'time_tag' );
		$goods     = $get( 'goods_type' );
		$spec      = $get( 'spec' );
		$prod_desc = $get( 'prod_desc' );
		$memo      = $get( 'memo' );
		if ( mb_strwidth( $prod_desc, 'UTF-8' ) > 20 ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'The item description is too long: up to 10 Chinese or 20 English characters.', 'moksa-for-woocommerce' ) );
		}
		if ( mb_strwidth( $memo, 'UTF-8' ) > 100 ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'The note for the driver is too long: up to 50 Chinese or 100 English characters.', 'moksa-for-woocommerce' ) );
		}
		$notify = '1' === $get( 'notify' );

		if ( '' === $consignor || '' === $address || '' === $prod_desc ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'Fill in the customer name, pickup address and item description.', 'moksa-for-woocommerce' ) );
		}
		if ( 1 !== preg_match( '/^09\d{8}$/', $mobile ) ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'The customer mobile must be 10 digits starting with 09.', 'moksa-for-woocommerce' ) );
		}
		if ( mb_strwidth( $address, 'UTF-8' ) > 120 ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'The pickup address is too long.', 'moksa-for-woocommerce' ) );
		}
		if ( ! in_array( $ship_date, self::pickup_dates(), true ) ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'Choose a pickup date from the list.', 'moksa-for-woocommerce' ) );
		}
		if ( ! array_key_exists( $time_tag, self::time_tags() ) ) {
			$time_tag = '04';
		}
		if ( ! in_array( $goods, [ GoodsType::NORMAL, GoodsType::FROZEN, GoodsType::REFRIGERATED ], true ) ) {
			$goods = GoodsType::NORMAL;
		}
		if ( ! in_array( $spec, [ '1', '2', '3', '4' ], true ) || ( '4' === $spec && GoodsType::NORMAL !== $goods ) ) {
			return new \WP_Error( 'moksafowo_payuni_return', __( 'Choose a parcel size. Chilled and frozen parcels cannot be 150cm.', 'moksa-for-woocommerce' ) );
		}

		$fields = [
			'GoodsType'        => (int) $goods,
			'ServiceType'      => 3,
			'DeliveryTimeTag'  => $time_tag,
			'Consignee'        => self::store_name(),
			'ConsigneeMobile'  => self::store_mobile(),
			'ConsigneeAddress' => self::store_address(),
			'Consignor'        => $consignor,
			'ConsignorMobile'  => $mobile,
			'ConsignorAddress' => $address,
			'ProdDesc'         => $prod_desc,
			'Spec'             => (int) $spec,
			'ShipDate'         => $ship_date,
		];
		if ( '' !== $memo ) {
			$fields['Memo'] = $memo;
		}
		$original = self::original_home_shipment( $order );
		if ( '' !== $original ) {
			$fields['ShipTradeNo'] = $original;
		} else {
			$fields['MerTradeNo'] = 'R' . $order->get_id() . wp_rand( 1000, 9999 );
		}

		$resp = Api::create_home( $fields );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$ship_date_label = substr( $ship_date, 0, 4 ) . '-' . substr( $ship_date, 4, 2 ) . '-' . substr( $ship_date, 6, 2 );
		$record          = [
			'type'         => Store::TYPE_HOME,
			'ref'          => $resp['ShipTradeNo'],
			'obt_number'   => $resp['OBTNumber'] ?? '',
			'mer_trade_no' => $resp['MerTradeNo'] ?? '',
			'ship_date'    => $ship_date_label,
			'time_tag'     => $time_tag,
			'status'       => '',
			'status_desc'  => '',
			'status_time'  => '',
			'created_at'   => current_time( 'mysql' ),
			'created_by'   => get_current_user_id(),
		];
		// 等統一金流回應期間可能有通知寫進訂單，重新讀一次再寫，避免蓋掉。
		$fresh = wc_get_order( $order->get_id() );
		$order = $fresh instanceof \WC_Order ? $fresh : $order;
		Store::add( $order, $record );
		$order->save();

		$window = self::time_tags()[ $time_tag ];
		$order->add_order_note(
			\Moksafowo\Order\Note::build(
				__( 'Booked a T-Cat return pickup with PAYUNi.', 'moksa-for-woocommerce' ),
				[
					__( 'Return number', 'moksa-for-woocommerce' )   => $record['obt_number'],
					__( 'Pickup date', 'moksa-for-woocommerce' )     => $ship_date_label,
					__( 'Delivery window', 'moksa-for-woocommerce' ) => $window,
				]
			),
			0,
			true
		);
		if ( $notify ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: pickup date, 2: time window, 3: return number */
					__( 'T-Cat will collect your return on %1$s (%2$s). Return number: %3$s. Please have the parcel packed and ready.', 'moksa-for-woocommerce' ),
					$ship_date_label,
					$window,
					$record['obt_number']
				),
				1,
				true
			);
		}
		PayuniShipping::log( 'PAYUNi T-Cat return booked for order ' . $order->get_id() );
		return true;
	}

	/**
	 * 可選的取件日：D+1～D+7（台灣時間）；16:25 以後申請最早 D+2。
	 *
	 * @return string[] YYYYMMDD
	 */
	public static function pickup_dates(): array {
		$now   = new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Taipei' ) );
		$first = $now->format( 'Hi' ) >= '1625' ? 2 : 1;
		$dates = [];
		for ( $d = $first; $d <= 7; $d++ ) {
			$dates[] = $now->modify( '+' . $d . ' day' )->format( 'Ymd' );
		}
		return $dates;
	}

	/**
	 * @return array<string, string>
	 */
	private static function time_tags(): array {
		return [
			'01' => __( 'Before 1 pm', 'moksa-for-woocommerce' ),
			'02' => __( '14:00 - 18:00', 'moksa-for-woocommerce' ),
			'04' => __( 'No preference', 'moksa-for-woocommerce' ),
		];
	}

	/**
	 * 7-11 退貨便可以對應的原物流單：統一金流的 7-11 大宗寄倉單。
	 */
	private static function original_c2b_shipment( \WC_Order $order ): string {
		if ( ShipType::SEVEN !== (string) $order->get_meta( OrderMeta::ShipType ) || LgsType::B2C !== (string) $order->get_meta( OrderMeta::LgsType ) ) {
			return '';
		}
		return (string) $order->get_meta( OrderMeta::ShipTradeNo );
	}

	private static function original_home_shipment( \WC_Order $order ): string {
		if ( ShipType::TCAT !== (string) $order->get_meta( OrderMeta::ShipType ) ) {
			return '';
		}
		return (string) $order->get_meta( OrderMeta::ShipTradeNo );
	}

	/** 退貨人姓名：最多 5 個中文字或 10 個英文字。 */
	private static function shopper_name( string $name ): string {
		return mb_strimwidth( $name, 0, 10, '', 'UTF-8' );
	}

	private static function format_code( string $ref ): string {
		return strlen( $ref ) === 12 ? substr( $ref, 0, 8 ) . ' ' . substr( $ref, 8 ) : $ref;
	}

	private static function clamp( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}

	private static function mobile( \WC_Order $order ): string {
		foreach ( [ $order->get_shipping_phone(), $order->get_billing_phone() ] as $phone ) {
			$digits = preg_replace( '/[^0-9]/', '', (string) $phone ) ?? '';
			if ( 1 === preg_match( '/^09\d{8}$/', $digits ) ) {
				return $digits;
			}
		}
		return '';
	}

	private static function address( \WC_Order $order ): string {
		$country = $order->get_shipping_country() ?: 'TW';
		$states  = WC()->countries->get_states( $country );
		$state   = (string) $order->get_shipping_state();
		$state   = is_array( $states ) && isset( $states[ $state ] ) ? $states[ $state ] : $state;
		return trim( $state . $order->get_shipping_city() . $order->get_shipping_address_1() . $order->get_shipping_address_2() );
	}

	/** 收件人（商店）：統一金流會改用商店開通黑貓時填的聯絡資料，這裡帶設定值當預設。 */
	private static function store_name(): string {
		$name = (string) get_option( 'moksafowo_payuni_shipping_sender_name', '' );
		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}

	private static function store_mobile(): string {
		return preg_replace( '/[^0-9]/', '', (string) get_option( 'moksafowo_payuni_shipping_sender_phone', '' ) ) ?? '';
	}

	private static function store_address(): string {
		$states = WC()->countries->get_states( WC()->countries->get_base_country() );
		$state  = WC()->countries->get_base_state();
		$state  = is_array( $states ) && isset( $states[ $state ] ) ? $states[ $state ] : $state;
		return trim( $state . WC()->countries->get_base_city() . WC()->countries->get_base_address() . WC()->countries->get_base_address_2() );
	}
}
