<?php
/**
 * 設定頁的金鑰欄位：不把金鑰印進 HTML，改顯示「已設定」＋「更換」；留空存檔＝不變。
 *
 * @package Moksafowo
 */

declare( strict_types=1 );

namespace Moksafowo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * 金鑰欄位原本是 WooCommerce 的 text 欄位，值會原樣印在頁面原始碼裡。這裡把 SecretMap 列的欄位
 * 換成自己的欄位型別（輸出時呼叫 mask()），存檔時：
 * - 留空 → 不變（WooCommerce 的 sanitize 篩選回 null 就不存）。
 * - 勾「清除」（同名勾選框送出 CLEAR）→ 存空字串。
 * - 有填 → 存新值。
 */
final class SecretFields {

	public const TYPE = 'moksafowo_secret';

	/** 「清除」勾選框送出的值（跟金鑰欄位同名）。 */
	public const CLEAR = '__moksafowo_clear__';

	public static function boot(): void {
		add_action( 'woocommerce_admin_field_' . self::TYPE, array( self::class, 'render' ) );
		foreach ( SecretMap::OPTIONS as $option ) {
			add_filter( 'woocommerce_admin_settings_sanitize_option_' . $option, array( self::class, 'sanitize' ), 10, 3 );
		}
	}

	/**
	 * 設定陣列裡的金鑰欄位換成本欄位型別（輸出前呼叫）。
	 *
	 * @param array<int,array<string,mixed>> $settings WooCommerce 設定陣列。
	 * @return array<int,array<string,mixed>>
	 */
	public static function mask( array $settings ): array {
		foreach ( $settings as $i => $field ) {
			if ( is_array( $field ) && isset( $field['id'], $field['type'] ) && in_array( $field['type'], array( 'text', 'password' ), true ) && SecretMap::has( (string) $field['id'] ) ) {
				$settings[ $i ]['type'] = self::TYPE;
			}
		}
		return $settings;
	}

	/**
	 * 輸出欄位：有值只顯示「已設定」，不印值。
	 *
	 * @param array<string,mixed> $field 欄位設定。
	 */
	public static function render( array $field ): void {
		$id    = (string) ( $field['id'] ?? '' );
		$title = (string) ( $field['title'] ?? '' );
		$desc  = (string) ( $field['desc'] ?? '' );
		$has   = '' !== (string) get_option( $id, '' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></label></th>
			<td class="forminp forminp-text">
				<?php if ( $has ) : ?>
					<span class="moksafowo-secret-set" style="display:inline-block;margin-right:12px;color:#007017;font-weight:600;">&#10003; <?php esc_html_e( 'Saved', 'moksa-for-woocommerce' ); ?></span>
					<details style="display:inline-block;vertical-align:top;margin-right:12px;">
						<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'Replace', 'moksa-for-woocommerce' ); ?></summary>
						<input type="password" name="<?php echo esc_attr( $id ); ?>" id="<?php echo esc_attr( $id ); ?>" value="" autocomplete="new-password" class="regular-text" style="margin-top:6px;" placeholder="<?php esc_attr_e( 'Enter the new value', 'moksa-for-woocommerce' ); ?>" />
					</details>
					<?php // 同名欄位、放在輸入框後面：勾了就由它的值勝出（PHP 取最後一個同名值）。 ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( self::CLEAR ); ?>" /> <?php esc_html_e( 'Remove', 'moksa-for-woocommerce' ); ?></label>
					<p class="description"><?php esc_html_e( 'For security the saved value is not shown. Leave it as is to keep it.', 'moksa-for-woocommerce' ); ?></p>
				<?php else : ?>
					<input type="password" name="<?php echo esc_attr( $id ); ?>" id="<?php echo esc_attr( $id ); ?>" value="" autocomplete="new-password" class="regular-text" />
				<?php endif; ?>
				<?php if ( '' !== $desc ) : ?>
					<p class="description"><?php echo wp_kses_post( $desc ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * 存檔：留空不變、勾清除存空字串、有填存新值。WooCommerce 已在呼叫前驗過設定頁 nonce。
	 *
	 * @param mixed               $value     WooCommerce 清過的值。
	 * @param array<string,mixed> $option    欄位設定。
	 * @param mixed               $raw_value 原始輸入。
	 * @return string|null null＝不存（維持原值）。
	 */
	public static function sanitize( $value, $option = array(), $raw_value = null ) {
		if ( self::CLEAR === ( is_string( $raw_value ) ? $raw_value : '' ) ) {
			return '';
		}
		$value = is_string( $value ) ? trim( $value ) : '';
		return '' === $value ? null : $value;
	}
}
