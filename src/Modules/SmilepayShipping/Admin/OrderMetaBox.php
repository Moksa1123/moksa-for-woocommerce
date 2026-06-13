<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\SmilepayShipping\Admin;

use MoksaWeb\Mowc\Modules\Shared\Admin\OrderInfoLayout;
use MoksaWeb\Mowc\Modules\SmilepayShipping\Module;
use MoksaWeb\Mowc\Modules\SmilepayShipping\Operations\CreateOrder;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox {

	private const NONCE_ACTION = 'moksafowo_smilepay_shipping_create';
	private const CAPABILITY   = 'edit_shop_orders';

	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		OrderInfoLayout::boot();
		add_filter( 'moksafowo_order_info_cards', [ __CLASS__, 'add_card' ], 20, 2 );
		add_action( 'wp_ajax_moksafowo_smilepay_shipping_create', [ __CLASS__, 'ajax_create_shipment' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'print_inline_js' ] );
	}

	public static function ajax_create_shipment(): void {
		check_ajax_referer( self::NONCE_ACTION );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'mo-ectools' ) ] );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( '訂單不存在。', 'mo-ectools' ) ] );
		}

		$existing = CreateOrder::get_records( $order );
		if ( ! empty( $existing ) ) {
			$force = isset( $_POST['force'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force'] ) );
			if ( ! $force ) {
				wp_send_json_error( [
					'code'    => 'existing',
					'message' => __( '此訂單已有物流單記錄。系統只會為「尚未建立的溫層」補建，已建立的不會重複下單。若要整批重建，請先刪除既有記錄。是否繼續？', 'mo-ectools' ),
				] );
			}
		}

		$result = CreateOrder::run( $order );
		if ( $result['ok'] ) {
			wp_send_json_success( [
				'message' => sprintf(
					/* translators: 1: smseid 2: paymentno or tracknum */
					__( '速買配物流單建立成功（smseid=%1$s 編號=%2$s）', 'mo-ectools' ),
					$result['smseid'] ?? '-',
					$result['payment_no'] ?? $result['track_num'] ?? '-'
				),
			] );
		}
		wp_send_json_error( [ 'message' => $result['message'] ?? __( '未知錯誤', 'mo-ectools' ) ] );
	}

	public static function add_card( array $cards, \WC_Order $order ): array {
		$is_smilepay = false;
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( isset( Module::method_map()[ $method->get_method_id() ] ) ) {
				$is_smilepay = true;
				break;
			}
		}
		if ( ! $is_smilepay ) {
			return $cards;
		}

		$records = CreateOrder::get_records( $order );
		$nonce   = wp_create_nonce( self::NONCE_ACTION );

		ob_start();
		?>
		<div class="moksafowo-smilepay-shipping-meta"
			data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>">

			<?php if ( ! empty( $records ) ) : ?>
				<div class="moksafowo-smilepay-records" style="display:flex;flex-direction:column;gap:8px;margin:0 0 8px;">
					<?php foreach ( $records as $r ) :
						$smseid     = (string) ( $r['smseid'] ?? '' );
						$pay_no     = (string) ( $r['payment_no'] ?? '' );
						$track_num  = (string) ( $r['track_num'] ?? '' );
						$method_id  = (string) ( $r['method_id'] ?? '' );
						$temp       = isset( $r['temp'] ) ? (int) $r['temp'] : 0;
						$created_at = (string) ( $r['created_at'] ?? '' );
						$status_msg = (string) ( $r['status_msg'] ?? '' );
						$temp_label = $temp ? \MoksaWeb\Mowc\Modules\Shipping\Temp\ProductTemp::label( $temp ) : '';
						$temp_pill_color = match ( $temp ) {
							2       => [ '#dbeafe', '#1e40af' ],
							3       => [ '#ede9fe', '#6d28d9' ],
							default => [ '#e5e7eb', '#374151' ],
						};
					?>
					<div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px 12px;font-size:12px;">
						<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
							<?php if ( '' !== $smseid ) : ?>
								<span style="font-family:monospace;font-weight:600;color:#0f172a;"><?php echo esc_html( $smseid ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $temp_label ) : ?>
								<span style="background:<?php echo esc_attr( $temp_pill_color[0] ); ?>;color:<?php echo esc_attr( $temp_pill_color[1] ); ?>;padding:1px 8px;border-radius:3px;font-size:11px;"><?php echo esc_html( $temp_label ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $status_msg ) : ?>
								<span style="margin-left:auto;color:#64748b;font-size:11px;"><?php echo esc_html( $status_msg ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( '' !== $pay_no ) : ?>
							<p style="margin:.2em 0;"><strong><?php esc_html_e( '寄貨編號：', 'mo-ectools' ); ?></strong><span style="font-family:monospace;word-break:break-all;"><?php echo esc_html( $pay_no ); ?></span></p>
						<?php endif; ?>
						<?php if ( '' !== $track_num ) : ?>
							<p style="margin:.2em 0;"><strong><?php esc_html_e( '託運單號：', 'mo-ectools' ); ?></strong><span style="font-family:monospace;word-break:break-all;"><?php echo esc_html( $track_num ); ?></span></p>
						<?php endif; ?>
						<?php if ( '' !== $created_at ) : ?>
							<p style="margin:.2em 0;color:#64748b;font-size:11px;"><?php echo esc_html( $created_at ); ?></p>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="moksafowo-smilepay-shipping-actions">
				<button type="button"
					class="button button-primary moksafowo-smilepay-shipping-create">
					<?php echo esc_html( empty( $records ) ? __( '建立物流單', 'mo-ectools' ) : __( '重新建立物流單', 'mo-ectools' ) ); ?>
				</button>
			</div>
		</div>
		<?php
		$html = (string) ob_get_clean();

		$cards[] = [
			'slot'  => 'shipping',
			'title' => __( '物流資訊', 'mo-ectools' ),
			'html'  => $html,
		];
		return $cards;
	}

	public static function print_inline_js(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		wp_register_script( 'moksafowo-smilepay-shipping-admin', false, [ 'jquery' ], MOKSAFOWO_VERSION, true );
		wp_enqueue_script( 'moksafowo-smilepay-shipping-admin' );
		wp_add_inline_script( 'moksafowo-smilepay-shipping-admin', self::inline_js() );
	}

	private static function inline_js(): string {
		return <<<'JS'
(function($){
  $(document).on('click', '.moksafowo-smilepay-shipping-create', function(e){
    e.preventDefault();
    var $btn = $(this);
    var $wrap = $btn.closest('.moksafowo-smilepay-shipping-meta');
    var orderId = $wrap.data('order-id');
    var nonce   = $wrap.data('nonce');
    if (!orderId || !nonce) { window.alert('缺少訂單 ID 或 nonce'); return; }
    function send(force){
      $btn.prop('disabled', true);
      $.post(ajaxurl, {
        action: 'moksafowo_smilepay_shipping_create',
        order_id: orderId,
        _wpnonce: nonce,
        force: force ? '1' : '0'
      }).done(function(resp){
        if (resp && resp.success) {
          window.alert((resp.data && resp.data.message) || '建立成功');
          window.location.reload();
        } else {
          var d = resp && resp.data || {};
          if (d.code === 'existing') {
            if (window.confirm(d.message || '此訂單已有物流單記錄，是否繼續？')) {
              send(true);
              return;
            }
          } else {
            window.alert('建立失敗：' + ((d.message) || '未知錯誤'));
          }
          $btn.prop('disabled', false);
        }
      }).fail(function(){
        window.alert('連線錯誤');
        $btn.prop('disabled', false);
      });
    }
    send(false);
  });
})(jQuery);
JS;
	}
}
