<?php

namespace MoksaWeb\Mowc\Modules\PayuniShipping\Api;

use MoksaWeb\Mowc\Modules\PayuniShipping\PayuniShipping;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\LgsType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\ShipType;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\OrderMeta;
use MoksaWeb\Mowc\Modules\PayuniShipping\Utils\SingletonTrait;
use \DOMDocument;



defined( 'ABSPATH' ) || exit;

class ShippingRequest {

	use SingletonTrait;

	public const ASYNC_CREATE_HOOK = 'mo_payuni_shipping_create_async';

	public static function init() {

		self::get_instance();

		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'schedule_create' ), 10, 1 );
		add_action( self::ASYNC_CREATE_HOOK, array( self::get_instance(), 'payuni_create_shipping' ), 10, 1 );

		add_action( 'wp_ajax_mo_payuni_shipping_delivery_status', array( self::get_instance(), 'payuni_ajax_query_delivery_status' ), 10, 1 );
		add_action( 'wp_ajax_mo_payuni_shipping_update_package_spec', array( self::get_instance(), 'payuni_ajax_update_package_spec' ), 10, 1 );

		add_action( 'wp_ajax_mo_payuni_shipping_print_label', array( self::get_instance(), 'payuni_print_label' ) );
		add_action( 'wp_ajax_mo_payuni_shipping_download_label', array( self::get_instance(), 'payuni_download_label' ) );

	}

	public static function schedule_create( $order_id ) {
		$id = is_numeric( $order_id ) ? (int) $order_id : ( is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ? (int) $order_id->get_id() : 0 );
		if ( $id <= 0 ) {
			return;
		}
		// Guard：只有當訂單真的有 PAYUNi 物流時才排程，避免每張 ECPay/SmilePay 訂單都排無作用的 AS job。
		$order = wc_get_order( $id );
		if ( ! $order ) {
			return;
		}
		$has_payuni = false;
		foreach ( $order->get_shipping_methods() as $sm ) {
			if ( PayuniShipping::is_payuni_shipping( $sm->get_method_id() ) ) {
				$has_payuni = true;
				break;
			}
		}
		if ( ! $has_payuni ) {
			return;
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), self::ASYNC_CREATE_HOOK, [ $id ], 'mo-ectools' );
		} else {
			wp_schedule_single_event( time(), self::ASYNC_CREATE_HOOK, [ $id ] );
		}
	}

	public static function payuni_create_shipping( $order = 0 ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

        if ( ! $order ) {
            return;
        }

		// 判斷訂單是否包含 PAYUNi 的運送方式
		$shipping_methods = $order->get_shipping_methods();
		$has_payuni_shipping = false;
		foreach ( $shipping_methods as $shipping_method ) {
			if ( PayuniShipping::is_payuni_shipping( $shipping_method->get_method_id() ) ) {
				$has_payuni_shipping = true;
				break;
			}
		}

		PayuniShipping::log( 'has payuni shipping:' . $has_payuni_shipping );
		if ( ! $has_payuni_shipping ) {
			return;
		}

		// 多溫層 unified methods（黑貓 + 7-11 C2C/B2C）→ 走 CreateOrderUnified 拆單流程
		$unified_ids = [
			\MoksaWeb\Mowc\Modules\PayuniShipping\Providers\TCat\HDUnified::ID,
			\MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven\C2CUnified::ID,
			\MoksaWeb\Mowc\Modules\PayuniShipping\Providers\SevenEleven\B2CUnified::ID,
		];
		foreach ( $shipping_methods as $shipping_method ) {
			if ( in_array( $shipping_method->get_method_id(), $unified_ids, true ) ) {
				\MoksaWeb\Mowc\Modules\PayuniShipping\Operations\CreateOrderUnified::run( $order );
				return;
			}
		}

		if ( empty( $order->get_meta( OrderMeta::ShipType ) ) ) {
			$order->add_order_note( __( 'PAYUNi 物流類型未設定', 'mo-ectools' ) );
			return;
		}

		//get current wp action
		$action = current_action();
		// 物流單已建立，若訂單狀態改變為處理中，不重複建立物流單.(仍可手動執行)
		if ( $action === 'woocommerce_order_status_processing' && ! empty( $order->get_meta( OrderMeta::ShipTradeNo ) ) ) {
			PayuniShipping::log( 'PAYUNi shipping order ' . $order->get_id() . ' action:' . $action . ' shipping order already exists, not create again.', 'info' );
			return;
		}

		// 紀錄是否已建立物流單
		$has_previous_shipping_order = false;
		if ( ! empty( $order->get_meta( OrderMeta::ShipTradeNo ) ) ) {
			$has_previous_shipping_order = true;
		}

		try {

			$response = self::create_order( $order );
			$resp_obj = json_decode( wp_remote_retrieve_body( $response ) );
			PayuniShipping::log( 'PAYUNi shipping order ' . $order->get_id() . ' response:' . wc_print_r( $resp_obj, true ) );

            $resp_info = PayuniShipping::decrypt( $resp_obj->EncryptInfo );
            PayuniShipping::log( 'PAYUNi shipping order ' . $order->get_id() . ' response decrypted:' . wc_print_r( $resp_info, true ) );

            if ( 'SUCCESS' !== $resp_info['Status'] ) {
				// 不要把整個 response dump 進 order note（含 PII / 內部欄位），改成 Status + Message 摘要。
				$order->add_order_note( sprintf(
					/* translators: 1: PAYUNi status code, 2: error message */
					__( 'PAYUNi 建立物流單失敗：%1$s（%2$s）', 'mo-ectools' ),
					(string) ( $resp_info['Status'] ?? '' ),
					(string) ( $resp_info['Message'] ?? '' )
				) );
				throw new \Exception( $resp_info['Message'] );
			}

			$order->update_meta_data( OrderMeta::ShipTradeNo, $resp_info['ShipTradeNo'] );// UNi物流序號.	
			$order->update_meta_data( OrderMeta::TradeAmt, $resp_info['TradeAmt'] );
			$order->update_meta_data( OrderMeta::ServiceType, $resp_info['ServiceType'] );

			// 若已建立物流單，則清除舊的物流查詢編號和狀態資料
			if ( $has_previous_shipping_order ) {
				$order->update_meta_data( OrderMeta::ShipNo, '' );
				$order->update_meta_data( OrderMeta::ShipStatus, '' );
				$order->update_meta_data( OrderMeta::ShipStatusDesc, '' );
				$order->update_meta_data( OrderMeta::ShipStatusTime, '' );

				// 7-11
				$order->update_meta_data( OrderMeta::PartnerId, '' );
				$order->update_meta_data( OrderMeta::Odno, '' );
				$order->update_meta_data( OrderMeta::ValidationNo, '' );
			}
            $order->save();

			$order->add_order_note( __( 'PAYUNi 物流單建立成功（單號 ', 'mo-ectools' ) . $resp_info['ShipTradeNo'] );

			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} catch ( \Exception $e ) {
			PayuniShipping::log( __( 'PAYUNi 建立物流單失敗：', 'mo-ectools' ) . ' ' . $e->getMessage(), 'error' );
		}
	}

	public static function payuni_ajax_query_delivery_status() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gateway callback; values pass through hash verification before use.

		// SECURITY: capability check before nonce — wpbr-payuni-shipping had nonce
		// only, allowing any logged-in user to query other people's orders.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'mo-ectools' ), 403 );
		}

		if ( ! check_ajax_referer( 'payuni-shipping-order', 'security', false ) ) {
			$return = array(
				'success' => false,
				'result'  => __( 'Invalid security token sent.', 'mo-ectools' ),
			);
			wp_send_json( $return );
			wp_die();
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( __( 'Missing Ajax Parameter.', 'mo-ectools' ) );
			wp_die();
		}

		$post_id = wc_clean( wp_unslash( $_POST['post_id'] ) );
		$order   = wc_get_order( $post_id );

		$response = self::query_order( $order );

		if ( is_wp_error( $response ) ) {
			$return = array(
				'success' => false,
				'result'  => $response->get_error_message(),
			);
			wp_send_json( $return );
			wp_die();
		}

		$resp_obj = json_decode( wp_remote_retrieve_body( $response ) );

		$resp_info = PayuniShipping::decrypt( $resp_obj->EncryptInfo );
		PayuniShipping::log( 'PAYUNi QUERY shipping order ' . $order->get_id() . ' response decrypted:' . wc_print_r( $resp_info, true ) );

		// 不要把整段 response dump 進 order note，挑關鍵欄位（Status / LgsStatus / 描述）摘要顯示。
		$order->add_order_note( sprintf(
			/* translators: 1: status, 2: lgs status code, 3: description */
			__( 'PAYUNi 物流貨態查詢結果：%1$s — %2$s %3$s', 'mo-ectools' ),
			(string) ( $resp_info['Status'] ?? '' ),
			(string) ( $resp_info['LgsStatus'] ?? $resp_info['LgsType'] ?? '' ),
			(string) ( $resp_info['Message'] ?? '' )
		) );

		self::update_order_logistic_meta( $order, $resp_info );

		if ( 'SUCCESS' !== $resp_info['Status'] ) {
			$return = array(
				'success' => false,
				'result'  => $resp_info['Message'],
			);
			wp_send_json( $return );
			wp_die();
		}

		$return = array(
			'success' => true,
			'result'  => $resp_info,
		);
		wp_send_json( $return );
	}

	public static function payuni_ajax_update_package_spec() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gateway callback; values pass through hash verification before use.

		// SECURITY: capability check before nonce — wpbr-payuni-shipping had nonce only.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'mo-ectools' ), 403 );
		}

		if ( ! check_ajax_referer( 'payuni-shipping-order', 'security', false ) ) {
			$return = array(
				'success' => false,
				'result'  => __( 'Invalid security token sent.', 'mo-ectools' ),
			);
			wp_send_json( $return );
			wp_die();
		}

		if ( ! isset( $_POST['order_id'] ) || ! isset( $_POST['package_spec'] ) ) {
			wp_send_json_error( __( 'Missing Ajax Parameter.', 'mo-ectools' ) );
			wp_die();
		}

		$order_id = wc_clean( wp_unslash( $_POST['order_id'] ) );
		$package_spec = wc_clean( wp_unslash( $_POST['package_spec'] ) );
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( __( 'Order not found.', 'mo-ectools' ) );
			wp_die();
		}

		// Validate package spec based on goods type
		$goods_type = $order->get_meta( OrderMeta::GoodsType );
		$valid_specs = array( '1', '2', '3' ); // 60cm, 90cm, 120cm

		// Only normal temperature supports 150cm
		if ( $goods_type === \MoksaWeb\Mowc\Modules\PayuniShipping\Utils\GoodsType::NORMAL ) {
			$valid_specs[] = '4'; // 150cm
		}

		if ( ! in_array( $package_spec, $valid_specs ) ) {
			$return = array(
				'success' => false,
				'result'  => __( 'Invalid package spec for this goods type.', 'mo-ectools' ),
			);
			wp_send_json( $return );
			wp_die();
		}

		// Update the package spec
		$order->update_meta_data( OrderMeta::PackageSpec, $package_spec );
		$order->save();

		// Add order note
		$spec_labels = array(
			'1' => '60cm',
			'2' => '90cm', 
			'3' => '120cm',
			'4' => '150cm'
		);
		$spec_label = isset( $spec_labels[$package_spec] ) ? $spec_labels[$package_spec] : $package_spec;
		/* translators: %s: package temperature spec label (e.g. 60cm / 90cm) */
		$order->add_order_note( sprintf( __( '包裹溫層已更新為：%s', 'mo-ectools' ), $spec_label ) );

		$return = array(
			'success' => true,
			'result'  => __( 'Package spec updated successfully.', 'mo-ectools' ),
		);
		wp_send_json( $return );
	}

	public static function payuni_print_label() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gateway callback; values pass through hash verification before use.
		// SECURITY: capability + nonce ALWAYS required. wpbr-payuni-shipping
		// only checked the nonce when `security` was present in the URL, which
		// meant an attacker could omit it and bypass verification entirely.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mo-ectools' ), 403 );
		}
		if ( ! isset( $_GET['security'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['security'] ) ), 'payuni-shipping-order' ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'mo-ectools' ), 403 );
		}
		if ( ! isset( $_GET['orderids'] ) || ! isset( $_GET['service'] ) ) {
			esc_html_e( 'Missing Ajax Parameter.', 'mo-ectools' );
			wp_die();
		}

		$order_ids = wc_clean( wp_unslash( $_GET['orderids'] ) );
		$service   = wc_clean( wp_unslash( $_GET['service'] ) );

		$api_url = '';
		if ( ShipType::SEVEN === $service ) {
			$api_url = PayuniShipping::$api_url . '/logistics/print_label';
		} elseif ( ShipType::TCAT === $service ) {
			$api_url = PayuniShipping::$api_url . '/home_delivery/get_obt_number_pdf';
		} else {
			esc_html_e( 'Unsupported shipping service.', 'mo-ectools' );
			wp_die();
		}

		$order_ids = array_map( 'absint', array_filter( explode( ',', $order_ids ) ) );

		// `include` not `post__in` — HPOS silently ignores `post__in`.
		$orders = wc_get_orders( array( 'include' => $order_ids, 'limit' => -1 ) );

		if ( empty( $orders ) ) {
			esc_html_e( 'No such Orders', 'mo-ectools' );
			wp_die();
		}
		
		// Process all orders to collect ShipTradeNo
		$ship_trade_nos = array();
		foreach ( $orders as $order ) {
			$ship_trade_no = $order->get_meta( OrderMeta::ShipTradeNo );
			if ( $ship_trade_no ) {
				$ship_trade_nos[] = $ship_trade_no;
			}
		}
		
		// Use first order as reference
		$reference_order = $orders[0];
		$goods_type = $reference_order->get_meta( OrderMeta::GoodsType );
		$ship_trade_nos_str = implode( ',', $ship_trade_nos );

		// PAYUNi API expects Taipei wallclock date. Use an explicit Asia/Taipei DateTimeZone
		// instead of mutating PHP's process-wide default timezone.
		$tw_today    = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Taipei' ) ) )->format( 'Ymd' );
		$tw_tomorrow = ( new \DateTimeImmutable( 'tomorrow', new \DateTimeZone( 'Asia/Taipei' ) ) )->format( 'Ymd' );

		if ( $service === ShipType::SEVEN ) {
			// SEVEN
			$label_mode = get_option( 'mo_payuni_shipping_cvs_label_mode', '1' ); // Default to A4 format

			$print_request_args = array(
				'MerID'       => PayuniShipping::get_mer_id(),
				'Timestamp'   => time(),
				'ShipTradeNo' => $ship_trade_nos_str,
				'GoodsType'   => $goods_type,
				'LgsType'     => $reference_order->get_meta( OrderMeta::LgsType ),
				'ShipType'    => ShipType::SEVEN,
				'ShipDate'    => $tw_today,
				'LabelMode'   => $label_mode,
			);

			if ( $print_request_args['LgsType'] === LgsType::B2C ) {
				$print_request_args['ShipDate'] = $tw_tomorrow;
			}

			PayuniShipping::log( 'print label request args:' . wc_print_r( $print_request_args, true ) );

			$encrypted_args = PayuniShipping::encrypt( $print_request_args );

			$request_arggs = array(
				'MerID'       => PayuniShipping::get_mer_id(),
				'Version'     => '1.0',
				'EncryptInfo' => $encrypted_args,
				'HashInfo'    => PayuniShipping::hash_info( $encrypted_args ),
			);

			// admin-ajax.php 不會 enqueue jQuery，原本 (function($){})(jQuery) 會
			// 直接拋 ReferenceError 害 form 不 auto-submit、user 看到原始 HTML。
			// 改純 JS + <noscript> fallback 避免再踩雷。
			?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8">
		<title><?php esc_html_e( '正在送出列印請求…', 'mo-ectools' ); ?></title>
	</head>
	<body>
		<form id="payuni-print-label-form" action="<?php echo esc_url( $api_url ); ?>" method="post">
			<?php foreach ( $request_arggs as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
			<?php endforeach; ?>
			<noscript>
				<button type="submit"><?php esc_html_e( '送出', 'mo-ectools' ); ?></button>
			</noscript>
		</form>
		<script>document.getElementById('payuni-print-label-form').submit();</script>
	</body>
</html>
<?php
			wp_die();

		} elseif ( $service === ShipType::TCAT ) {
			// tcat、seven bulk、family bulk、family frozen and seven frozen.

			$shipping_date = get_option( 'mo_payuni_shipping_tcat_estimate_shipping_date','1' );
			
			// Calculate shipping date avoiding weekends
			
			$current_date           = new \DateTime();
			$estimate_shipping_date = self::get_next_business_day( $current_date, intval( $shipping_date ) );
			$estimate_delivery_date = self::get_next_business_day( $estimate_shipping_date, 1 );

			$package_spec = $reference_order->get_meta( OrderMeta::PackageSpec );

			$print_request_args = array(
				'MerID'        => PayuniShipping::get_mer_id(),
				'Timestamp'    => time(),
				'PostType'     => '1',
				'PrintType'    => '1',
				'ShipTradeNo'  => $ship_trade_nos_str,
				'GoodsType'    => $goods_type,
				'LgsType'      => LgsType::HOME,
				'ShipType'     => ShipType::TCAT,
				'ShipDate'     => $estimate_shipping_date->format( 'Y-m-d' ),
				'DeliveryDate' => $estimate_delivery_date->format( 'Y-m-d' ),
				'Spec'         => $package_spec,
			);

			PayuniShipping::log( 'tcat print label request args:' . wc_print_r( $print_request_args, true ) );

			$encrypted_args = PayuniShipping::encrypt( $print_request_args );

			$request_arggs = array(
				'MerID'       => PayuniShipping::get_mer_id(),
				'Version'     => '1.0',
				'EncryptInfo' => $encrypted_args,
				'HashInfo'    => PayuniShipping::hash_info( $encrypted_args ),
			);

			?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8">
		<title><?php esc_html_e( '正在送出列印請求…', 'mo-ectools' ); ?></title>
	</head>
	<body>
		<form id="payuni-print-label-form" action="<?php echo esc_url( $api_url ); ?>" method="post">
			<?php foreach ( $request_arggs as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
			<?php endforeach; ?>
			<noscript>
				<button type="submit"><?php esc_html_e( '送出', 'mo-ectools' ); ?></button>
			</noscript>
		</form>
		<script>document.getElementById('payuni-print-label-form').submit();</script>
	</body>
</html>
<?php
			wp_die();

		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public static function payuni_download_label() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gateway callback; values pass through hash verification before use.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mo-ectools' ), 403 );
		}
		if ( ! isset( $_GET['security'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['security'] ) ), 'payuni-shipping-order' ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'mo-ectools' ), 403 );
		}

		if ( ! isset( $_GET['orderids'] ) || ! isset( $_GET['service'] ) ) {
			esc_html_e( 'Missing Ajax Parameter.', 'mo-ectools' );
			wp_die();
		}
		
		$order_ids_param = wc_clean( wp_unslash( $_GET['orderids'] ) );
		$service   = wc_clean( wp_unslash( $_GET['service'] ) );

		if ( ShipType::TCAT !== $service ) {
			esc_html_e( 'Unsupported Ship Type', 'mo-ectools' );
			wp_die();
		}
		
		$api_url = PayuniShipping::$api_url . '/home_delivery/download_pdf';
		
		//暫時只取第一筆
		$order = wc_get_order( $order_ids_param );

		if ( ! $order ) {
			esc_html_e( 'Cant find Order.', 'mo-ectools' );
			wp_die();
		}

		$ship_trade_no = $order->get_meta( OrderMeta::ShipTradeNo );
		$file_no       = $order->get_meta( OrderMeta::FileNo );

		$download_request_args = array(
			'MerID'       => PayuniShipping::get_mer_id(),
			'Timestamp'   => time(),
			'FileNo'      => $file_no,
			'ShipTradeNo' => $ship_trade_no,
		);

		PayuniShipping::log( 'label download request args:' . wc_print_r( $download_request_args, true ) );

		$encrypted_args = PayuniShipping::encrypt( $download_request_args );

		$request_arggs = array(
			'MerID'       => PayuniShipping::get_mer_id(),
			'Version'     => '1.0',
			'EncryptInfo' => $encrypted_args,
			'HashInfo'    => PayuniShipping::hash_info( $encrypted_args ),
		);

		?>
			<html>
				<head><meta charset="utf-8"></head>
				<body>
					<script>
						(function ($) {
							'use strict';

							$( document ).ready(function() {

								var label_request_args = <?php echo wp_json_encode( $request_arggs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;

								var html = '<form id="payuni-print-label-form" action="<?php echo esc_url( $api_url ); ?>" method="post">';

								for (const [key, value] of Object.entries(label_request_args) ) {
									html += '<input type="hidden" name="' + key + '" value="' + value + '">';
								}
								html += '</form>';

								document.body.innerHTML += html;
								document.getElementById('payuni-print-label-form').submit();

							});

						})(jQuery);
					</script>
		        </body>
		    </html>

<?php

			wp_die();
	}

	public static function create_order( $order ) {

		$request_args = self::build_request_args( $order );
		PayuniShipping::log( 'Create PAYUNi shipping order request args:' . wc_print_r( $request_args, true ), 'info' );
		$encrypted_args = PayuniShipping::encrypt( $request_args );
		$url            = self::get_api_url( $order, 'trade' );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 45,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent'   => 'WordPress',
				),
				'body'        => array(
					'MerID'       => PayuniShipping::get_mer_id(),
					'Version'     => self::get_api_version( $order, 'trade' ),
					'EncryptInfo' => $encrypted_args,
					'HashInfo'    => PayuniShipping::hash_info( $encrypted_args ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'PAYUNi 建立物流單失敗：', 'mo-ectools' ) . ' ' . $response->get_error_message() );
			PayuniShipping::log( 'Create PAYUNi shipping order:' . wc_print_r( $response, true ), 'error' );
			throw new \Exception( esc_html( $response->get_error_message() ), (int) $response->get_error_code() );
		}

		return $response;

	}

	public static function query_order( \WC_Order $order ) {

		$request_args   = self::build_request_args( $order, 'query' );
		PayuniShipping::log( 'PAYUNi query shipping order request args:' . wc_print_r( $request_args, true ), 'info' );
		$encrypted_args = PayuniShipping::encrypt( $request_args );
		$url            = self::get_api_url( $order, 'query' );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 45,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent'   => 'WordPress',
				),
				'body'        => array(
					'MerID' => PayuniShipping::get_mer_id(),
                    'Version' => self::get_api_version( $order, 'query' ),
                    'EncryptInfo' => $encrypted_args,
                    'HashInfo' => PayuniShipping::hash_info( $encrypted_args ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'PAYUNi 物流單查詢失敗：', 'mo-ectools' ) . ' ' . $response->get_error_message() );
			PayuniShipping::log( 'Query PAYUNi shipping order:' . wc_print_r( $response, true ), 'error' );
			throw new \Exception( esc_html( $response->get_error_message() ), (int) $response->get_error_code() );
		}

		return $response;
	}

	private static function build_request_args( \WC_Order $order, $action = 'trade' ) {

		$args = array();

		$args['MerID']            = PayuniShipping::get_mer_id();
		$args['Timestamp']        = time();

		if ( $action === 'trade' ) {

			$args['MerTradeNo']      = self::get_prefixed_order_no( $order );
			$args['GoodsType']       = $order->get_meta( OrderMeta::GoodsType );//溫層
			$args['LgsType']         = $order->get_meta( OrderMeta::LgsType );//運送方式
			$args['ShipType']        = $order->get_meta( OrderMeta::ShipType ); //1=Seven
			$args['TradeAmt']        = self::get_trade_amt( $order );
			$args['ServiceType']     = ( 'cod' === $order->get_payment_method() ) ? '1' : '3';// 1:取貨付款 3:取貨不付款.
			$args['StoreID']         = $order->get_meta( OrderMeta::StoreId );

			$args['Consignee']       = $order->get_shipping_last_name() . $order->get_shipping_first_name();
			$args['ConsigneeMail']   = $order->get_billing_email();
			$args['ConsigneeMobile'] = PayuniShipping::payuni_get_shipping_phone( $order );

			$args['RefundStoreID']    = '';
			$args['SenderName']       = get_option( 'mo_payuni_shipping_sender_name' );
			$args['SenderMobile']     = get_option( 'mo_payuni_shipping_sender_phone' );

			if ( $order->get_meta( OrderMeta::ShipType ) === ShipType::TCAT ) {
				$args['StoreID']          = '';
				$args['DeliveryTimeTag']  = PayuniShipping::get_tcat_delivery_time();
				$args['ConsigneeAddress'] = self::payuni_get_api_order_address( $order );
				$args['ProdDesc']         = self::get_items_infos( $order );
				$args['NotifyURL']        = wc()->api_request_url( 'mo_payuni_shipping_tcat_notify' );
			} else {
				$args['NotifyURL'] = wc()->api_request_url( 'mo_payuni_shipping_711_notify' );
			}

		} elseif ( $action === 'query' ) {

			$args['LgsType']     = $order->get_meta( OrderMeta::LgsType );
			$args['ShipTradeNo'] = $order->get_meta( OrderMeta::ShipTradeNo );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		return apply_filters( 'mo_payuni_shipping_order_request_args', $args, $order );
	}

	public static function update_order_logistic_meta( $order, $resp_obj ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$order->update_meta_data( OrderMeta::ShipTradeNo, $resp_obj['ShipTradeNo'] ); // UNi物流序號 .

		if ( array_key_exists( 'Odno', $resp_obj ) && '-' !== $resp_obj['Odno'] ) {
			$order->update_meta_data( OrderMeta::Odno, $resp_obj['Odno'] ); // 出貨編號/宅配託運單號.
		}

		if ( array_key_exists( 'PartnerId', $resp_obj ) && '-' !== $resp_obj['PartnerId'] ) {
			$order->update_meta_data( OrderMeta::PartnerId, $resp_obj['PartnerId'] );
		}

		// 7-11 C2C
		if ( $resp_obj['LgsType'] === LgsType::C2C ) {
			if ( array_key_exists( 'ValidationNo', $resp_obj ) && '-' !== $resp_obj['ValidationNo'] ) {
				$order->update_meta_data( OrderMeta::ValidationNo, $resp_obj['ValidationNo'] );
				$order->update_meta_data( OrderMeta::ShipNo, $resp_obj['Odno'] . $resp_obj['ValidationNo'] );
			}
		} elseif ( $resp_obj['LgsType'] === LgsType::B2C ) {
			$order->update_meta_data( OrderMeta::ShipNo, $resp_obj['PartnerId'] . $resp_obj['Odno'] );
		}

		// 黑貓.
		if ( array_key_exists( 'ShipType', $resp_obj ) && ShipType::TCAT === $resp_obj['ShipType'] ) {
			if ( array_key_exists( 'Odno', $resp_obj ) && '-' !== $resp_obj['Odno'] ) {
				$order->update_meta_data( OrderMeta::ShipNo, $resp_obj['Odno'] ); // 宅配託運單號.
			}
			if ( array_key_exists( 'FileNo', $resp_obj ) && '-' !== $resp_obj['FileNo'] ) {
				$order->update_meta_data( OrderMeta::FileNo, $resp_obj['FileNo'] ); // 檔名序號.
			}
		}

		if ( array_key_exists( 'ShipStatus', $resp_obj ) && '-' !== $resp_obj['ShipStatus'] ) {
			$order->update_meta_data( OrderMeta::ShipStatus, $resp_obj['ShipStatus'] ); // 流程狀態描述.
		}

		if ( array_key_exists( 'ShipStatusDesc', $resp_obj ) && '-' !== $resp_obj['ShipStatusDesc'] ) {
			$order->update_meta_data( OrderMeta::ShipStatusDesc, $resp_obj['ShipStatusDesc'] ); // 物流代碼詳細資訊.
		}

		if ( array_key_exists( 'ShipStatusTime', $resp_obj ) && '-' !== $resp_obj['ShipStatusTime'] ) {
			$order->update_meta_data( OrderMeta::ShipStatusTime, $resp_obj['ShipStatusTime'] );
		}

		$order->save();
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public static function payuni_get_api_order_address( $order ) {
		// 縣市英文代碼 → 中文（黑貓 API 需中文地址）+ 鄉鎮市區（_shipping_mowp/district）。
		$address  = '';
		$address .= \MoksaWeb\Mowc\Modules\Address\TwAddress::state_label( (string) $order->get_shipping_state() );
		$address .= (string) $order->get_meta( '_shipping_mowp/district' );
		$address .= $order->get_shipping_city();
		$address .= $order->get_shipping_address_1();
		$address .= $order->get_shipping_address_2();
		return $address;
	}

	private static function get_prefixed_order_no( $order ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
		$prefix = apply_filters( 'mo_payuni_shipping_order_prefix', '' );
		return $prefix . $order->get_order_number();
	}

	private static function get_api_url( $order, $action ) {
		$api_url = '';

		if ( $action === 'query' ) {
			$api_url = PayuniShipping::$api_url . '/logistics/' . $action;
			return $api_url;
		}

		if ( ! empty( $order->get_meta( OrderMeta::ShipType ) ) && $order->get_meta( OrderMeta::ShipType ) !== ShipType::TCAT ) {
			$api_url = PayuniShipping::$api_url . '/logistics/' . $action;
		} else {
			$api_url = PayuniShipping::$api_url . '/home_delivery/' . $action;
		}
		return $api_url;
	}

	public static function get_trade_amt( \WC_Order $order ) {
	
		// 取貨付款，等於訂單金額
		if ( $order->get_payment_method() === 'cod' ) {
			$total = $order->get_total() - $order->get_total_refunded();		
		} else {
			// 取貨不付款，等於報值金額(商品價值)
			if ( $order->get_total() == 0 ) {
				$total = $order->get_subtotal() + $order->get_shipping_total() - $order->get_total_refunded();
			} else {
				$total = $order->get_total() - $order->get_total_refunded();
			}

 			if ( $total < 30 ) {
				$total = 30;
			} elseif ( $total > 20000 ) {
				$total = 20000;
			}
		}

		return $total;
	}

	private static function get_api_version( $order, $action ) {
		// 不論 action / ship type，PAYUNi API 統一用 1.1。之前的 if/else 兩個分支都回 1.1。
		return '1.1';
	}

	private static function get_items_infos( $order ) {
		$items     = $order->get_items();
		$item_name = '';
		foreach ( $items as $item ) {
			$item_name .= $item['name'];
			if ( end( $items )['name'] !== $item['name'] ) {
				$item_name .= ',';
			}
		}

		$cleaned_item_name = mb_substr( $item_name, 0, 20 );
		return $cleaned_item_name;
	}

	private static function get_next_business_day( \DateTime $start_date, $days_to_add ) {
		$date = clone $start_date;
		$date->modify( '+' . $days_to_add . ' days' );
		
		// Check if it's a weekend
		$day_of_week = (int) $date->format( 'w' );
		
		// 0 is Sunday, 6 is Saturday
		if ( $day_of_week === 0 ) {
			// Sunday, move to Monday
			$date->modify( '+1 day' );
		} elseif ( $day_of_week === 6 ) {
			// Saturday, move to Monday — 之前被註解，PAYUNi 對週六 ship date 會拒收
			$date->modify( '+2 days' );
		}
		
		return $date;
	}

}
