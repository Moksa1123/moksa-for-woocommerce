<?php

namespace Moksafowo\Modules\PayuniShipping\Api;

use Moksafowo\Modules\PayuniShipping\PayuniShipping;
use Moksafowo\Modules\PayuniShipping\Utils\LgsType;
use Moksafowo\Modules\PayuniShipping\Utils\ShipType;
use Moksafowo\Modules\PayuniShipping\Utils\OrderMeta;
use Moksafowo\Modules\PayuniShipping\Utils\SingletonTrait;
use DOMDocument;



use Moksafowo\Modules\Shared\Frontend\Interstitial;

defined( 'ABSPATH' ) || exit;

class ShippingRequest {

	use SingletonTrait;

	public const ASYNC_CREATE_HOOK = 'moksafowo_payuni_shipping_create_async';

	public static function init() {

		self::get_instance();

		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'schedule_create' ), 10, 1 );
		add_action( self::ASYNC_CREATE_HOOK, array( self::get_instance(), 'moksafowo_payuni_create_shipping' ), 10, 1 );

		add_action( 'wp_ajax_moksafowo_payuni_shipping_delivery_status', array( self::get_instance(), 'moksafowo_payuni_ajax_query_delivery_status' ), 10, 1 );
		add_action( 'wp_ajax_moksafowo_payuni_shipping_update_package_spec', array( self::get_instance(), 'moksafowo_payuni_ajax_update_package_spec' ), 10, 1 );

		add_action( 'wp_ajax_moksafowo_payuni_shipping_print_label', array( self::get_instance(), 'moksafowo_payuni_print_label' ) );
		add_action( 'wp_ajax_moksafowo_payuni_shipping_download_label', array( self::get_instance(), 'moksafowo_payuni_download_label' ) );
	}

	public static function schedule_create( $order_id ) {
		$id = is_numeric( $order_id ) ? (int) $order_id : ( is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ? (int) $order_id->get_id() : 0 );
		if ( $id <= 0 ) {
			return;
		}
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
			as_schedule_single_action( time(), self::ASYNC_CREATE_HOOK, [ $id ], 'moksa-for-woocommerce' );
		} else {
			wp_schedule_single_event( time(), self::ASYNC_CREATE_HOOK, [ $id ] );
		}
	}

	public static function moksafowo_payuni_create_shipping( $order = 0 ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return;
		}

		$shipping_methods    = $order->get_shipping_methods();
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

		$unified_ids = [
			\Moksafowo\Modules\PayuniShipping\Providers\TCat\HDUnified::ID,
			\Moksafowo\Modules\PayuniShipping\Providers\SevenEleven\C2CUnified::ID,
			\Moksafowo\Modules\PayuniShipping\Providers\SevenEleven\B2CUnified::ID,
		];
		foreach ( $shipping_methods as $shipping_method ) {
			if ( in_array( $shipping_method->get_method_id(), $unified_ids, true ) ) {
				\Moksafowo\Modules\PayuniShipping\Operations\CreateOrderUnified::run( $order );
				return;
			}
		}

		if ( empty( $order->get_meta( OrderMeta::ShipType ) ) ) {
			$order->add_order_note( __( 'PAYUNi 物流類型未設定', 'moksa-for-woocommerce' ) );
			return;
		}

		// 狀態自動觸發時不重複下單；手動仍可執行
		$action = current_action();
		if ( $action === 'woocommerce_order_status_processing' && ! empty( $order->get_meta( OrderMeta::ShipTradeNo ) ) ) {
			PayuniShipping::log( 'PAYUNi shipping order ' . $order->get_id() . ' action:' . $action . ' shipping order already exists, not create again.', 'info' );
			return;
		}

		$has_previous_shipping_order = ! empty( $order->get_meta( OrderMeta::ShipTradeNo ) );

		try {

			$response = self::create_order( $order );
			$resp_obj = json_decode( wp_remote_retrieve_body( $response ) );
			PayuniShipping::log( 'PAYUNi shipping order ' . $order->get_id() . ' response:' . wc_print_r( $resp_obj, true ) );

			$resp_info = PayuniShipping::decrypt( $resp_obj->EncryptInfo );
			PayuniShipping::log( 'PAYUNi shipping order ' . $order->get_id() . ' response decrypted:' . wc_print_r( $resp_info, true ) );

			if ( 'SUCCESS' !== $resp_info['Status'] ) {
				$order->add_order_note(
					sprintf(
					/* translators: 1: PAYUNi status code, 2: error message */
						__( 'PAYUNi 建立物流單失敗：%1$s（%2$s）', 'moksa-for-woocommerce' ),
						(string) ( $resp_info['Status'] ?? '' ),
						(string) ( $resp_info['Message'] ?? '' )
					)
				);
				throw new \Exception( $resp_info['Message'] );
			}

			$order->update_meta_data( OrderMeta::ShipTradeNo, $resp_info['ShipTradeNo'] );
			$order->update_meta_data( OrderMeta::TradeAmt, $resp_info['TradeAmt'] );
			$order->update_meta_data( OrderMeta::ServiceType, $resp_info['ServiceType'] );

			if ( $has_previous_shipping_order ) {
				$order->update_meta_data( OrderMeta::ShipNo, '' );
				$order->update_meta_data( OrderMeta::ShipStatus, '' );
				$order->update_meta_data( OrderMeta::ShipStatusDesc, '' );
				$order->update_meta_data( OrderMeta::ShipStatusTime, '' );
				$order->update_meta_data( OrderMeta::PartnerId, '' );
				$order->update_meta_data( OrderMeta::Odno, '' );
				$order->update_meta_data( OrderMeta::ValidationNo, '' );
			}
			$order->save();

			/* translators: %s: PAYUNi ship trade number */
			$order->add_order_note( sprintf( __( 'PAYUNi 物流單建立成功（物流單號 %s）', 'moksa-for-woocommerce' ), $resp_info['ShipTradeNo'] ) );

			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} catch ( \Exception $e ) {
			PayuniShipping::log( __( 'PAYUNi 建立物流單失敗：', 'moksa-for-woocommerce' ) . ' ' . $e->getMessage(), 'error' );
		}
	}

	public static function moksafowo_payuni_ajax_query_delivery_status() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gateway callback; values pass through hash verification before use.

		// SECURITY: capability check before nonce — wpbr-payuni-shipping had nonce
		// only, allowing any logged-in user to query other people's orders.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'moksa-for-woocommerce' ), 403 );
		}

		if ( ! check_ajax_referer( 'moksafowo-payuni-shipping-order', 'security', false ) ) {
			$return = array(
				'success' => false,
				'result'  => __( 'Invalid security token sent.', 'moksa-for-woocommerce' ),
			);
			wp_send_json( $return );
			wp_die();
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( __( 'Missing Ajax Parameter.', 'moksa-for-woocommerce' ) );
			wp_die();
		}

		$post_id = absint( wp_unslash( $_POST['post_id'] ) );
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

		$order->add_order_note(
			sprintf(
			/* translators: 1: status, 2: lgs status code, 3: description */
				__( 'PAYUNi 物流貨態查詢結果：%1$s — %2$s %3$s', 'moksa-for-woocommerce' ),
				(string) ( $resp_info['Status'] ?? '' ),
				(string) ( $resp_info['LgsStatus'] ?? $resp_info['LgsType'] ?? '' ),
				(string) ( $resp_info['Message'] ?? '' )
			)
		);

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

	public static function moksafowo_payuni_ajax_update_package_spec() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gateway callback; values pass through hash verification before use.

		// SECURITY: capability check before nonce — wpbr-payuni-shipping had nonce only.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'moksa-for-woocommerce' ), 403 );
		}

		if ( ! check_ajax_referer( 'moksafowo-payuni-shipping-order', 'security', false ) ) {
			$return = array(
				'success' => false,
				'result'  => __( 'Invalid security token sent.', 'moksa-for-woocommerce' ),
			);
			wp_send_json( $return );
			wp_die();
		}

		if ( ! isset( $_POST['order_id'] ) || ! isset( $_POST['package_spec'] ) ) {
			wp_send_json_error( __( 'Missing Ajax Parameter.', 'moksa-for-woocommerce' ) );
			wp_die();
		}

		$order_id     = absint( wp_unslash( $_POST['order_id'] ) );
		$package_spec = wc_clean( wp_unslash( $_POST['package_spec'] ) );
		$order        = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( __( 'Order not found.', 'moksa-for-woocommerce' ) );
			wp_die();
		}

		$goods_type  = $order->get_meta( OrderMeta::GoodsType );
		$valid_specs = array( '1', '2', '3' );
		if ( $goods_type === \Moksafowo\Modules\PayuniShipping\Utils\GoodsType::NORMAL ) {
			$valid_specs[] = '4'; // 150cm 僅常溫支援
		}

		if ( ! in_array( $package_spec, $valid_specs, true ) ) {
			$return = array(
				'success' => false,
				'result'  => __( 'Invalid package spec for this goods type.', 'moksa-for-woocommerce' ),
			);
			wp_send_json( $return );
			wp_die();
		}

		$order->update_meta_data( OrderMeta::PackageSpec, $package_spec );
		$order->save();

		$spec_labels = array(
			'1' => '60cm',
			'2' => '90cm',
			'3' => '120cm',
			'4' => '150cm',
		);
		$spec_label  = isset( $spec_labels[ $package_spec ] ) ? $spec_labels[ $package_spec ] : $package_spec;
		/* translators: %s: package spec label (e.g. 60cm / 90cm) */
		$order->add_order_note( sprintf( __( '包裹規格已更新為：%s', 'moksa-for-woocommerce' ), $spec_label ) );

		$return = array(
			'success' => true,
			'result'  => __( 'Package spec updated successfully.', 'moksa-for-woocommerce' ),
		);
		wp_send_json( $return );
	}

	public static function moksafowo_payuni_print_label() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gateway callback; values pass through hash verification before use.
		// SECURITY: capability + nonce ALWAYS required. wpbr-payuni-shipping
		// only checked the nonce when `security` was present in the URL, which
		// meant an attacker could omit it and bypass verification entirely.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'moksa-for-woocommerce' ), 403 );
		}
		if ( ! isset( $_GET['security'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['security'] ) ), 'moksafowo-payuni-shipping-order' ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'moksa-for-woocommerce' ), 403 );
		}
		if ( ! isset( $_GET['orderids'] ) || ! isset( $_GET['service'] ) ) {
			esc_html_e( 'Missing Ajax Parameter.', 'moksa-for-woocommerce' );
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
			esc_html_e( 'Unsupported shipping service.', 'moksa-for-woocommerce' );
			wp_die();
		}

		$order_ids = array_map( 'absint', array_filter( explode( ',', $order_ids ) ) );

		// HPOS 不支援 post__in，改用 include
		$orders = wc_get_orders(
			array(
				'include' => $order_ids,
				'limit'   => -1,
			)
		);

		if ( empty( $orders ) ) {
			esc_html_e( 'No such Orders', 'moksa-for-woocommerce' );
			wp_die();
		}

		$ship_trade_nos = array();
		foreach ( $orders as $order ) {
			$ship_trade_no = $order->get_meta( OrderMeta::ShipTradeNo );
			if ( $ship_trade_no ) {
				$ship_trade_nos[] = $ship_trade_no;
			}
		}

		$reference_order    = $orders[0];
		$goods_type         = $reference_order->get_meta( OrderMeta::GoodsType );
		$ship_trade_nos_str = implode( ',', $ship_trade_nos );

		// PAYUNi API 要 Asia/Taipei 日期；避免更動 PHP process-wide default tz
		$tw_today    = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Taipei' ) ) )->format( 'Ymd' );
		$tw_tomorrow = ( new \DateTimeImmutable( 'tomorrow', new \DateTimeZone( 'Asia/Taipei' ) ) )->format( 'Ymd' );

		if ( $service === ShipType::SEVEN ) {
			$label_mode = get_option( 'moksafowo_payuni_shipping_cvs_label_mode', '1' );

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

			// admin-ajax.php 沒有 jQuery；純 JS + noscript fallback
			?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8">
		<title><?php esc_html_e( '正在送出列印請求…', 'moksa-for-woocommerce' ); ?></title>
	</head>
	<body>
		<form id="moksafowo-payuni-print-label-form" action="<?php echo esc_url( $api_url ); ?>" method="post">
			<?php foreach ( $request_arggs as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
			<?php endforeach; ?>
			<noscript>
				<button type="submit"><?php esc_html_e( '送出', 'moksa-for-woocommerce' ); ?></button>
			</noscript>
		</form>
			<?php wp_print_inline_script_tag( 'document.getElementById("moksafowo-payuni-print-label-form").submit();' ); ?>
	</body>
</html>
			<?php
			wp_die();

		} elseif ( $service === ShipType::TCAT ) {
			$shipping_date          = get_option( 'moksafowo_payuni_shipping_tcat_estimate_shipping_date', '1' );
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
		<title><?php esc_html_e( '正在送出列印請求…', 'moksa-for-woocommerce' ); ?></title>
	</head>
	<body>
		<form id="moksafowo-payuni-print-label-form" action="<?php echo esc_url( $api_url ); ?>" method="post">
			<?php foreach ( $request_arggs as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
			<?php endforeach; ?>
			<noscript>
				<button type="submit"><?php esc_html_e( '送出', 'moksa-for-woocommerce' ); ?></button>
			</noscript>
		</form>
			<?php wp_print_inline_script_tag( 'document.getElementById("moksafowo-payuni-print-label-form").submit();' ); ?>
	</body>
</html>
			<?php
			wp_die();

		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public static function moksafowo_payuni_download_label() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Gateway callback; values pass through hash verification before use.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'moksa-for-woocommerce' ), 403 );
		}
		if ( ! isset( $_GET['security'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['security'] ) ), 'moksafowo-payuni-shipping-order' ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'moksa-for-woocommerce' ), 403 );
		}

		if ( ! isset( $_GET['orderids'] ) || ! isset( $_GET['service'] ) ) {
			esc_html_e( 'Missing Ajax Parameter.', 'moksa-for-woocommerce' );
			wp_die();
		}

		$order_ids_param = wc_clean( wp_unslash( $_GET['orderids'] ) );
		$service         = wc_clean( wp_unslash( $_GET['service'] ) );

		if ( ShipType::TCAT !== $service ) {
			esc_html_e( 'Unsupported Ship Type', 'moksa-for-woocommerce' );
			wp_die();
		}

		$api_url = PayuniShipping::$api_url . '/home_delivery/download_pdf';

		$order = wc_get_order( $order_ids_param );

		if ( ! $order ) {
			esc_html_e( 'Cant find Order.', 'moksa-for-woocommerce' );
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

		$forms_html = '<form id="moksafowo-payuni-print-label-form" action="' . esc_url( $api_url ) . '" method="post">';
		foreach ( $request_arggs as $k => $v ) {
			$forms_html .= '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		$forms_html .= '</form>';

		Interstitial::render(
			__( '下載物流標籤', 'moksa-for-woocommerce' ),
			__( '正在下載 PAYUNi 物流標籤…', 'moksa-for-woocommerce' ),
			[],
			$forms_html,
			'document.getElementById("moksafowo-payuni-print-label-form").submit();'
		);
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
			$order->add_order_note( __( 'PAYUNi 建立物流單失敗：', 'moksa-for-woocommerce' ) . ' ' . $response->get_error_message() );
			PayuniShipping::log( 'Create PAYUNi shipping order:' . wc_print_r( $response, true ), 'error' );
			throw new \Exception( esc_html( $response->get_error_message() ), (int) $response->get_error_code() );
		}

		return $response;
	}

	public static function query_order( \WC_Order $order ) {

		$request_args = self::build_request_args( $order, 'query' );
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
					'MerID'       => PayuniShipping::get_mer_id(),
					'Version'     => self::get_api_version( $order, 'query' ),
					'EncryptInfo' => $encrypted_args,
					'HashInfo'    => PayuniShipping::hash_info( $encrypted_args ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'PAYUNi 物流單查詢失敗：', 'moksa-for-woocommerce' ) . ' ' . $response->get_error_message() );
			PayuniShipping::log( 'Query PAYUNi shipping order:' . wc_print_r( $response, true ), 'error' );
			throw new \Exception( esc_html( $response->get_error_message() ), (int) $response->get_error_code() );
		}

		return $response;
	}

	private static function build_request_args( \WC_Order $order, $action = 'trade' ) {

		$args = array();

		$args['MerID']     = PayuniShipping::get_mer_id();
		$args['Timestamp'] = time();

		if ( $action === 'trade' ) {

			$args['MerTradeNo']  = self::get_prefixed_order_no( $order );
			$args['GoodsType']   = $order->get_meta( OrderMeta::GoodsType );
			$args['LgsType']     = $order->get_meta( OrderMeta::LgsType );
			$args['ShipType']    = $order->get_meta( OrderMeta::ShipType );
			$args['TradeAmt']    = self::get_trade_amt( $order );
			$args['ServiceType'] = ( 'cod' === $order->get_payment_method() ) ? '1' : '3';
			$args['StoreID']     = $order->get_meta( OrderMeta::StoreId );

			$args['Consignee']       = $order->get_shipping_last_name() . $order->get_shipping_first_name();
			$args['ConsigneeMail']   = $order->get_billing_email();
			$args['ConsigneeMobile'] = PayuniShipping::moksafowo_payuni_get_shipping_phone( $order );

			$args['RefundStoreID'] = '';
			$args['SenderName']    = get_option( 'moksafowo_payuni_shipping_sender_name' );
			$args['SenderMobile']  = get_option( 'moksafowo_payuni_shipping_sender_phone' );

			if ( $order->get_meta( OrderMeta::ShipType ) === ShipType::TCAT ) {
				$args['StoreID']          = '';
				$args['DeliveryTimeTag']  = PayuniShipping::get_tcat_delivery_time();
				$args['ConsigneeAddress'] = self::moksafowo_payuni_get_api_order_address( $order );
				$args['ProdDesc']         = self::get_items_infos( $order );
				$args['NotifyURL']        = wc()->api_request_url( 'moksafowo_payuni_shipping_tcat_notify' );
			} else {
				$args['NotifyURL'] = wc()->api_request_url( 'moksafowo_payuni_shipping_711_notify' );
			}
		} elseif ( $action === 'query' ) {

			$args['LgsType']     = $order->get_meta( OrderMeta::LgsType );
			$args['ShipTradeNo'] = $order->get_meta( OrderMeta::ShipTradeNo );
		}

		return apply_filters( 'moksafowo_payuni_shipping_order_request_args', $args, $order );
	}

	public static function update_order_logistic_meta( $order, $resp_obj ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$order->update_meta_data( OrderMeta::ShipTradeNo, $resp_obj['ShipTradeNo'] );

		if ( array_key_exists( 'Odno', $resp_obj ) && '-' !== $resp_obj['Odno'] ) {
			$order->update_meta_data( OrderMeta::Odno, $resp_obj['Odno'] );
		}

		if ( array_key_exists( 'PartnerId', $resp_obj ) && '-' !== $resp_obj['PartnerId'] ) {
			$order->update_meta_data( OrderMeta::PartnerId, $resp_obj['PartnerId'] );
		}

		if ( $resp_obj['LgsType'] === LgsType::C2C ) {
			if ( array_key_exists( 'ValidationNo', $resp_obj ) && '-' !== $resp_obj['ValidationNo'] ) {
				$order->update_meta_data( OrderMeta::ValidationNo, $resp_obj['ValidationNo'] );
				$order->update_meta_data( OrderMeta::ShipNo, $resp_obj['Odno'] . $resp_obj['ValidationNo'] );
			}
		} elseif ( $resp_obj['LgsType'] === LgsType::B2C ) {
			$order->update_meta_data( OrderMeta::ShipNo, $resp_obj['PartnerId'] . $resp_obj['Odno'] );
		}

		if ( array_key_exists( 'ShipType', $resp_obj ) && ShipType::TCAT === $resp_obj['ShipType'] ) {
			if ( array_key_exists( 'Odno', $resp_obj ) && '-' !== $resp_obj['Odno'] ) {
				$order->update_meta_data( OrderMeta::ShipNo, $resp_obj['Odno'] );
			}
			if ( array_key_exists( 'FileNo', $resp_obj ) && '-' !== $resp_obj['FileNo'] ) {
				$order->update_meta_data( OrderMeta::FileNo, $resp_obj['FileNo'] );
			}
		}

		if ( array_key_exists( 'ShipStatus', $resp_obj ) && '-' !== $resp_obj['ShipStatus'] ) {
			$order->update_meta_data( OrderMeta::ShipStatus, $resp_obj['ShipStatus'] );
		}

		if ( array_key_exists( 'ShipStatusDesc', $resp_obj ) && '-' !== $resp_obj['ShipStatusDesc'] ) {
			$order->update_meta_data( OrderMeta::ShipStatusDesc, $resp_obj['ShipStatusDesc'] );
		}

		if ( array_key_exists( 'ShipStatusTime', $resp_obj ) && '-' !== $resp_obj['ShipStatusTime'] ) {
			$order->update_meta_data( OrderMeta::ShipStatusTime, $resp_obj['ShipStatusTime'] );
		}

		$order->save();
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public static function moksafowo_payuni_get_api_order_address( $order ) {
		// 黑貓 API 需完整中文地址；鄉鎮市區落地於 shipping_city，city 空才退用 district 附加欄位
		$district = (string) $order->get_shipping_city();
		if ( '' === $district ) {
			$district = (string) $order->get_meta( '_wc_shipping/moksafowo/district' );
		}
		$address  = '';
		$address .= \Moksafowo\Modules\Address\TwAddress::state_label( (string) $order->get_shipping_state() );
		$address .= $district;
		$address .= $order->get_shipping_address_1();
		$address .= $order->get_shipping_address_2();
		return $address;
	}

	private static function get_prefixed_order_no( $order ) {
		$prefix = apply_filters( 'moksafowo_payuni_shipping_order_prefix', '' );
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
		if ( $order->get_payment_method() === 'cod' ) {
			$total = $order->get_total() - $order->get_total_refunded();
		} else {
			if ( (float) $order->get_total() === 0.0 ) {
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

		$day_of_week = (int) $date->format( 'w' );
		// PAYUNi 對週六 ship date 會拒收
		if ( $day_of_week === 0 ) {
			$date->modify( '+1 day' );
		} elseif ( $day_of_week === 6 ) {
			$date->modify( '+2 days' );
		}

		return $date;
	}
}
