<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Paynow\Api;

defined( 'ABSPATH' ) || exit;

final class PaymentRequest {

	
	public static function build_params( array $args ): array {
		$web_no   = Helper::web_no();
		$order_no = (string) $args['order_no'];
		// TotalPrice 必須與 PassCode 計算用的字串完全一致。
		$total    = (string) (int) $args['total_price'];

		$pass_code = Signature::make(
			$web_no,
			$order_no,
			$total,
			Helper::trade_password()
		);

		$params = [
			'WebNo'         => $web_no,
			'PassCode'      => $pass_code,
			'ReceiverName'  => self::sanitize_name( (string) $args['receiver_name'] ),
			'ReceiverID'    => (string) $args['receiver_id'],
			'ReceiverTel'   => (string) $args['receiver_tel'],
			'ReceiverEmail' => (string) $args['receiver_email'],
			'OrderNo'       => $order_no,
			'ECPlatform'    => Helper::ec_platform(),
			'TotalPrice'    => $total,
			'OrderInfo'     => self::clamp_order_info( (string) $args['order_info'] ),
			'PayType'       => (string) $args['pay_type'],
			'EPT'           => '1',
		];

		if ( isset( $args['code_type'] ) && '' !== (string) $args['code_type'] ) {
			$params['CodeType'] = (string) $args['code_type'];
		}
		if ( isset( $args['deadline'] ) && (int) $args['deadline'] > 0 ) {
			$params['DeadLine'] = (string) (int) $args['deadline'];
		}
		if ( isset( $args['atm_respost'] ) ) {
			$params['AtmRespost'] = (string) $args['atm_respost'];
		}
		if ( isset( $args['extra'] ) && is_array( $args['extra'] ) ) {
			foreach ( $args['extra'] as $k => $v ) {
				$params[ (string) $k ] = (string) $v;
			}
		}

		return $params;
	}

	public static function render_form( array $params ): string {
		$action = Helper::endpoint();
		ob_start();
		?>
		<form method="post" id="mo-paynow-form" accept-charset="UTF-8" action="<?php echo esc_url( $action ); ?>">
			<?php foreach ( $params as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
			<?php endforeach; ?>
			<button type="submit" id="mo-paynow-submit" class="button alt"><?php esc_html_e( '前往 PayNow 付款頁', 'mo-ectools' ); ?></button>
		</form>
		<script>document.getElementById('mo-paynow-form').submit();</script>
		<?php
		return (string) ob_get_clean();
	}

	private static function sanitize_name( string $name ): string {
		$clean = preg_replace( '/[0-9]/', '', $name ) ?? $name;
		$clean = trim( $clean );
		if ( '' === $clean ) {
			$clean = __( '顧客', 'mo-ectools' );
		}
		return mb_substr( $clean, 0, 30 );
	}

	private static function clamp_order_info( string $info ): string {
		$info = trim( $info );
		if ( mb_strlen( $info ) < 5 ) {
			/* translators: %s: site name */
			$info = sprintf( __( '%s 線上訂單', 'mo-ectools' ), get_bloginfo( 'name' ) );
		}
		return mb_substr( $info, 0, 200 );
	}
}
