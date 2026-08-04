<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Shipping\Admin;

use Moksafowo\Modules\Shared\Frontend\Interstitial;

defined( 'ABSPATH' ) || exit;

/**
 * 後台訂單編輯頁改／選超商門市。
 *
 * 各物流模組透過 moksafowo_cvs_store_editor_providers 註冊描述子：
 *   'ecpay' => [
 *       'label'    => 'ECPay',
 *       'methods'  => [ method_id => title ],   // 只列 CVS 方式
 *       'meta'     => [ 'id' => ..., 'name' => ..., 'address' => ... ],
 *       'open_map' => callable( \WC_Order, string $method_id ): array|\WP_Error,
 *       'sync'     => callable( \WC_Order, array $store ): void,   // optional
 *   ]
 *
 * open_map 回傳 [ 'api_url' => ..., 'form_data' => [...] ]（POST）
 * 或 [ 'url' => ... ]（GET）。地圖開在新視窗，選完由 render_return()
 * 用 postMessage 把門市推回訂單編輯頁，原本沒存的編輯不會被沖掉。
 */
final class CvsStoreEditor {

	public const PROVIDERS_FILTER = 'moksafowo_cvs_store_editor_providers';
	public const MESSAGE_TYPE     = 'moksafowo_cvs_store';

	private const NONCE_ACTION = 'moksafowo_cvs_store_editor';
	private const CAPABILITY   = 'edit_shop_orders';
	private const SCRIPT       = 'moksafowo-admin-cvs-store';

	public static function init(): void {
		add_filter( 'woocommerce_admin_shipping_fields', [ __CLASS__, 'add_fields' ], 20, 3 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ __CLASS__, 'render_picker' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_ajax_moksafowo_cvs_admin_open_map', [ __CLASS__, 'ajax_open_map' ] );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function providers(): array {
		/**
		 * 各物流模組在此註冊「後台可改門市」的描述子。
		 *
		 * @param array<string,array<string,mixed>> $providers Provider descriptors.
		 */
		$providers = (array) apply_filters( 'moksafowo_cvs_store_editor_providers', [] );
		$clean     = [];
		foreach ( $providers as $key => $p ) {
			if ( ! is_array( $p ) || empty( $p['methods'] ) || empty( $p['meta']['id'] ) ) {
				continue;
			}
			$clean[ (string) $key ] = $p;
		}
		return $clean;
	}

	/**
	 * 找出這張訂單屬於哪一家的超商取貨；找不到回 null。
	 *
	 * @return array{key:string,provider:array<string,mixed>,method_id:string}|null
	 */
	public static function match( \WC_Order $order ): ?array {
		$providers = self::providers();
		if ( empty( $providers ) ) {
			return null;
		}
		foreach ( $order->get_shipping_methods() as $item ) {
			$mid = (string) $item->get_method_id();
			foreach ( $providers as $key => $provider ) {
				if ( isset( $provider['methods'][ $mid ] ) ) {
					return [
						'key'       => (string) $key,
						'provider'  => $provider,
						'method_id' => $mid,
					];
				}
			}
		}
		return null;
	}

	/**
	 * 把門市三欄塞進運送地址的「編輯」面板。
	 *
	 * WC 只在 $_POST[ $field['id'] ] 存在時才寫入，而 id 就是 input name，
	 * 所以這裡直接把 meta key 當 id 用，存檔由 WC 自己完成。
	 *
	 * @param array<string,array<string,mixed>> $fields  Shipping fields.
	 * @param \WC_Order|false                   $order   Order object.
	 * @param string                            $context view|edit.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_fields( array $fields, $order = false, string $context = 'edit' ): array {
		if ( ! $order instanceof \WC_Order ) {
			return $fields;
		}
		$match = self::match( $order );
		if ( null === $match ) {
			return $fields;
		}
		$meta = $match['provider']['meta'];

		// value 一定要自己給 —— WC 的預設值查的是 _shipping_{key}，跟我們的 meta key 不同
		$fields['moksafowo_cvs_store_id'] = [
			'id'              => $meta['id'],
			'label'           => __( 'Pickup store number', 'moksa-for-woocommerce' ),
			'value'           => (string) $order->get_meta( $meta['id'] ),
			'show'            => false,
			'class'           => 'moksafowo-cvs-store-field',
			'update_callback' => [ __CLASS__, 'save_store_id' ],
		];
		if ( ! empty( $meta['name'] ) ) {
			$fields['moksafowo_cvs_store_name'] = [
				'id'    => $meta['name'],
				'label' => __( 'Pickup store name', 'moksa-for-woocommerce' ),
				'value' => (string) $order->get_meta( $meta['name'] ),
				'show'  => false,
				'class' => 'moksafowo-cvs-store-field',
			];
		}
		if ( ! empty( $meta['address'] ) ) {
			$fields['moksafowo_cvs_store_address'] = [
				'id'              => $meta['address'],
				'label'           => __( 'Pickup store address', 'moksa-for-woocommerce' ),
				'value'           => (string) $order->get_meta( $meta['address'] ),
				'show'            => false,
				'class'           => 'moksafowo-cvs-store-field',
				'update_callback' => [ __CLASS__, 'save_address' ],
			];
		}

		return $fields;
	}

	/**
	 * 門市編號欄。update_callback 取代 WC 的預設寫入，所以進來時 meta 還是舊值 —— 換店的判斷靠這個時間差。
	 *
	 * @param string    $field_id Meta key.
	 * @param string    $value    Sanitized value.
	 * @param \WC_Order $order    Order.
	 */
	public static function save_store_id( string $field_id, string $value, \WC_Order $order ): void {
		$before = (string) $order->get_meta( $field_id );
		$order->update_meta_data( $field_id, $value );

		if ( $before !== $value && '' !== $value ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: previous store number, 2: new store number */
					__( 'Pickup store changed from %1$s to %2$s. Any shipment already created still points at the old store — delete it and create a new one.', 'moksa-for-woocommerce' ),
					'' === $before ? __( '(none)', 'moksa-for-woocommerce' ) : $before,
					$value
				)
			);
		}

		$match = self::match( $order );
		if ( null !== $match && empty( $match['provider']['meta']['address'] ) ) {
			self::run_sync( $order, $match['provider'] );
		}
	}

	/**
	 * 地址欄是三欄裡最後被處理的，借它收尾把各家自己的 meta 同步過去。
	 *
	 * @param string    $field_id Meta key.
	 * @param string    $value    Sanitized value.
	 * @param \WC_Order $order    Order.
	 */
	public static function save_address( string $field_id, string $value, \WC_Order $order ): void {
		$order->update_meta_data( $field_id, $value );

		$match = self::match( $order );
		if ( null !== $match ) {
			self::run_sync( $order, $match['provider'] );
		}
	}

	/**
	 * @param \WC_Order           $order    Order.
	 * @param array<string,mixed> $provider Provider descriptor.
	 */
	private static function run_sync( \WC_Order $order, array $provider ): void {
		if ( ! isset( $provider['sync'] ) || ! is_callable( $provider['sync'] ) ) {
			return;
		}
		$meta = $provider['meta'];
		call_user_func(
			$provider['sync'],
			$order,
			[
				'id'      => (string) $order->get_meta( $meta['id'] ),
				'name'    => empty( $meta['name'] ) ? '' : (string) $order->get_meta( $meta['name'] ),
				'address' => empty( $meta['address'] ) ? '' : (string) $order->get_meta( $meta['address'] ),
			]
		);
	}

	public static function render_picker( \WC_Order $order ): void {
		$match = self::match( $order );
		if ( null === $match ) {
			return;
		}
		$has_map = isset( $match['provider']['open_map'] ) && is_callable( $match['provider']['open_map'] );
		?>
		<div class="edit_address moksafowo-cvs-store-picker"
			data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"
			data-store-id-field="<?php echo esc_attr( $match['provider']['meta']['id'] ); ?>"
			data-store-name-field="<?php echo esc_attr( (string) ( $match['provider']['meta']['name'] ?? '' ) ); ?>"
			data-store-address-field="<?php echo esc_attr( (string) ( $match['provider']['meta']['address'] ?? '' ) ); ?>">
			<?php if ( $has_map ) : ?>
				<p class="form-field form-field-wide">
					<button type="button" class="button moksafowo-cvs-store-open-map">
						<?php esc_html_e( 'Choose store on map', 'moksa-for-woocommerce' ); ?>
					</button>
					<span class="moksafowo-cvs-store-status" style="margin-left:8px;color:#646970;"></span>
				</p>
			<?php endif; ?>
			<p class="form-field form-field-wide" style="color:#646970;">
				<?php esc_html_e( 'The store is only saved when you update the order. Changing the store here does not reissue the shipment — delete the existing shipment and create it again.', 'moksa-for-woocommerce' ); ?>
			</p>
			<?php wp_nonce_field( self::NONCE_ACTION, 'moksafowo_cvs_store_nonce' ); ?>
		</div>
		<?php
	}

	public static function enqueue( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen instanceof \WP_Screen ? $screen->id : '';
		if ( 'shop_order' !== $id && 'woocommerce_page_wc-orders' !== $id ) {
			return;
		}
		$path = MOKSAFOWO_PLUGIN_DIR . 'src/Modules/Shipping/assets/js/admin-cvs-store.js';
		wp_register_script(
			self::SCRIPT,
			MOKSAFOWO_PLUGIN_URL . 'src/Modules/Shipping/assets/js/admin-cvs-store.js',
			[ 'jquery' ],
			file_exists( $path ) ? (string) filemtime( $path ) : MOKSAFOWO_VERSION,
			true
		);
		wp_localize_script(
			self::SCRIPT,
			'moksafowoCvsStoreEditor',
			[
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'messageType' => self::MESSAGE_TYPE,
				'origin'      => self::origin(),
				'i18n'        => [
					'opening' => __( 'Opening the store map…', 'moksa-for-woocommerce' ),
					'chosen'  => __( 'Store filled in. Remember to update the order.', 'moksa-for-woocommerce' ),
					'blocked' => __( 'The store map window was blocked. Allow pop-ups for this site and try again.', 'moksa-for-woocommerce' ),
					'failed'  => __( 'The store map could not be opened.', 'moksa-for-woocommerce' ),
				],
			]
		);
		wp_enqueue_script( self::SCRIPT );
	}

	public static function ajax_open_map(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to edit orders.', 'moksa-for-woocommerce' ) ], 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found.', 'moksa-for-woocommerce' ) ], 404 );
		}

		$match = self::match( $order );
		if ( null === $match || ! isset( $match['provider']['open_map'] ) || ! is_callable( $match['provider']['open_map'] ) ) {
			wp_send_json_error( [ 'message' => __( 'This order does not use a convenience store pickup method.', 'moksa-for-woocommerce' ) ], 400 );
		}

		$result = call_user_func( $match['provider']['open_map'], $order, $match['method_id'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		if ( ! is_array( $result ) || ( empty( $result['url'] ) && empty( $result['api_url'] ) ) ) {
			wp_send_json_error( [ 'message' => __( 'The store map could not be opened.', 'moksa-for-woocommerce' ) ], 500 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * 選完店的回程頁：把門市 postMessage 回開啟它的訂單編輯頁，然後關掉自己。
	 *
	 * @param array{id:string,name?:string,address?:string} $store Store data.
	 */
	public static function render_return( array $store ): void {
		$payload = wp_json_encode(
			[
				'type'    => self::MESSAGE_TYPE,
				'id'      => (string) ( $store['id'] ?? '' ),
				'name'    => (string) ( $store['name'] ?? '' ),
				'address' => (string) ( $store['address'] ?? '' ),
			]
		);

		$script = 'try{if(window.opener&&!window.opener.closed){window.opener.postMessage('
			. $payload . ',' . wp_json_encode( self::origin() ) . ');window.close();}}catch(e){}';

		Interstitial::render(
			__( 'Store selected', 'moksa-for-woocommerce' ),
			__( 'Store selected', 'moksa-for-woocommerce' ),
			[
				/* translators: 1: store number, 2: store name */
				sprintf( __( 'Store: %1$s %2$s', 'moksa-for-woocommerce' ), '<strong>' . esc_html( (string) ( $store['id'] ?? '' ) ) . '</strong>', esc_html( (string) ( $store['name'] ?? '' ) ) ),
				esc_html( (string) ( $store['address'] ?? '' ) ),
				__( 'You can close this window and go back to the order — remember to update the order to keep the change.', 'moksa-for-woocommerce' ),
			],
			'',
			$script
		);
		exit;
	}

	private static function origin(): string {
		$parts  = wp_parse_url( admin_url( '/' ) );
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] ?? '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return $scheme . '://' . $host . $port;
	}
}
