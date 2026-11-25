<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\PayuniShipping\Returns;

defined( 'ABSPATH' ) || exit;

/**
 * 顧客的訂單詳情頁顯示退貨資訊：7-11 退貨便代碼與期限、黑貓取件日與退貨編號。
 * 訂單詳情頁本來就只給訂單本人（或帶訂單金鑰）看。過期的退貨便不顯示。
 */
final class CustomerView {

	public static function init(): void {
		add_action( 'woocommerce_order_details_after_order_table', [ __CLASS__, 'render' ], 15, 1 );
	}

	/**
	 * @param \WC_Order|mixed $order 訂單。
	 */
	public static function render( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$now  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Taipei' ) );
		$rows = [];
		foreach ( Store::all( $order ) as $record ) {
			$status = (string) ( $record['status_desc'] ?? '' );
			$status = ( '' === $status || '-' === $status ) ? '' : $status;
			if ( Store::TYPE_C2B === ( $record['type'] ?? '' ) ) {
				$deadline = (string) ( $record['deadline'] ?? '' );
				$expired  = '' !== $deadline && strtotime( $deadline . ' +08:00' ) < $now->getTimestamp();
				if ( $expired && '' === $status ) {
					continue;
				}
				$ref    = (string) ( $record['ref'] ?? '' );
				$rows[] = [
					__( '7-ELEVEN return code', 'moksa-for-woocommerce' ),
					[
						__( 'Return code', 'moksa-for-woocommerce' ) => strlen( $ref ) === 12 ? substr( $ref, 0, 8 ) . ' ' . substr( $ref, 8 ) : $ref,
						__( 'Use before', 'moksa-for-woocommerce' )  => $deadline,
						__( 'Status', 'moksa-for-woocommerce' )      => $status,
					],
					__( 'Enter the code at the ibon kiosk in any 7-ELEVEN store to print the return slip, then hand the parcel and the slip to the counter.', 'moksa-for-woocommerce' ),
				];
			} else {
				$rows[] = [
					__( 'T-Cat return pickup', 'moksa-for-woocommerce' ),
					[
						__( 'Pickup date', 'moksa-for-woocommerce' )   => (string) ( $record['ship_date'] ?? '' ),
						__( 'Return number', 'moksa-for-woocommerce' ) => (string) ( $record['obt_number'] ?? '' ),
						__( 'Status', 'moksa-for-woocommerce' )        => $status,
					],
					__( 'Please have the parcel packed and ready for the driver.', 'moksa-for-woocommerce' ),
				];
			}
		}
		if ( empty( $rows ) ) {
			return;
		}

		echo '<section class="woocommerce-order-returns moksafowo-payuni-returns">';
		echo '<h2>' . esc_html__( 'Returns', 'moksa-for-woocommerce' ) . '</h2>';
		foreach ( $rows as [ $title, $fields, $hint ] ) {
			echo '<h3>' . esc_html( $title ) . '</h3>';
			echo '<table class="woocommerce-table shop_table"><tbody>';
			foreach ( $fields as $label => $value ) {
				if ( '' === (string) $value ) {
					continue;
				}
				echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p>' . esc_html( $hint ) . '</p>';
		}
		echo '</section>';
	}
}
