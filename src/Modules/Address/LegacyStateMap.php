<?php
declare( strict_types=1 );

namespace Moksafowo\Modules\Address;

defined( 'ABSPATH' ) || exit;

/**
 * 從其他外掛換過來的商店：會員地址的縣市存的是中文（例如 woomp 的「臺北市」），
 * Moksa 的縣市下拉用代碼（TAIPEI CITY）。
 * - 結帳頁：中文值換成代碼帶入下拉（不寫回資料庫）。
 * - WooCommerce › 狀態 › 工具：先顯示要改幾位會員，按下才把會員資料改成代碼。訂單一律不改。
 */
final class LegacyStateMap {

	public const TOOL = 'moksafowo_migrate_tw_states';

	private const BATCH = 200;

	/** 中文縣市（含臺／台兩種寫法）→ 代碼。 */
	private const MAP = [
		'臺北市' => 'TAIPEI CITY',
		'台北市' => 'TAIPEI CITY',
		'新北市' => 'NEW TAIPEI CITY',
		'桃園市' => 'TAOYUAN CITY',
		'臺中市' => 'TAICHUNG CITY',
		'台中市' => 'TAICHUNG CITY',
		'臺南市' => 'TAINAN CITY',
		'台南市' => 'TAINAN CITY',
		'高雄市' => 'KAOHSIUNG CITY',
		'基隆市' => 'KEELUNG CITY',
		'新竹市' => 'HSINCHU CITY',
		'嘉義市' => 'CHIAYI CITY',
		'新竹縣' => 'HSINCHU COUNTY',
		'苗栗縣' => 'MIAOLI COUNTY',
		'彰化縣' => 'CHANGHUA COUNTY',
		'南投縣' => 'NANTOU COUNTY',
		'雲林縣' => 'YUNLIN COUNTY',
		'嘉義縣' => 'CHIAYI COUNTY',
		'屏東縣' => 'PINGTUNG COUNTY',
		'宜蘭縣' => 'YILAN COUNTY',
		'花蓮縣' => 'HUALIEN COUNTY',
		'臺東縣' => 'TAITUNG COUNTY',
		'台東縣' => 'TAITUNG COUNTY',
		'澎湖縣' => 'PENGHU COUNTY',
		'金門縣' => 'KINMEN COUNTY',
		'連江縣' => 'LIENCHIANG COUNTY',
	];

	public static function init(): void {
		// woocommerce_checkout_get_value 傳進來固定是 null（讓外掛搶先回值），要掛在 default_checkout_{欄位}。
		add_filter( 'default_checkout_billing_state', [ __CLASS__, 'checkout_value' ], 20, 2 );
		add_filter( 'default_checkout_shipping_state', [ __CLASS__, 'checkout_value' ], 20, 2 );
		add_filter( 'woocommerce_debug_tools', [ __CLASS__, 'register_tool' ] );
	}

	/** 中文縣市換成代碼；不認得的回空字串。 */
	public static function to_code( string $value ): string {
		return self::MAP[ trim( $value ) ] ?? '';
	}

	/** @return array<string,string> */
	public static function map(): array {
		return self::MAP;
	}

	/**
	 * @param mixed  $value 結帳欄位的預設值。
	 * @param string $input 欄位名稱。
	 * @return mixed
	 */
	public static function checkout_value( $value, $input ) {
		if ( ! in_array( $input, [ 'billing_state', 'shipping_state' ], true ) || ! is_string( $value ) ) {
			return $value;
		}
		$code = self::to_code( $value );
		return '' !== $code ? $code : $value;
	}

	/**
	 * 要改的會員數（帳單或運送縣市是中文、國家是台灣或沒填）。逐批讀，不用 meta 查詢。
	 *
	 * @param bool       $apply true 才寫回。
	 * @param int[]|null $only 只看這些會員（測試用）；null 是全部會員。
	 * @return array{users: int, fields: int}
	 */
	public static function scan( bool $apply = false, ?array $only = null ): array {
		$users  = 0;
		$fields = 0;
		$page   = 1;
		do {
			$args = [
				'fields'      => 'ID',
				'number'      => self::BATCH,
				'paged'       => $page,
				'orderby'     => 'ID',
				'count_total' => false,
			];
			if ( null !== $only ) {
				$args['include'] = array_map( 'absint', $only ) ?: [ 0 ];
			}
			$ids = get_users( $args );
			// 一批先把 meta 一次讀進快取，不要每位會員各查一次。
			update_meta_cache( 'user', array_map( 'intval', $ids ) );
			foreach ( $ids as $id ) {
				$changed = 0;
				foreach ( [ 'billing', 'shipping' ] as $group ) {
					$country = (string) get_user_meta( (int) $id, $group . '_country', true );
					$code    = self::to_code( (string) get_user_meta( (int) $id, $group . '_state', true ) );
					if ( '' === $code || ! in_array( $country, [ '', 'TW' ], true ) ) {
						continue;
					}
					++$changed;
					if ( $apply ) {
						update_user_meta( (int) $id, $group . '_state', $code );
					}
				}
				if ( $changed > 0 ) {
					++$users;
					$fields += $changed;
				}
			}
			++$page;
			$more = self::BATCH === count( $ids );
		} while ( $more );
		return [
			'users'  => $users,
			'fields' => $fields,
		];
	}

	/**
	 * @param array<string,array<string,mixed>> $tools WooCommerce 狀態頁的工具。
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_tool( array $tools ): array {
		$desc = __( 'Customers who switched from another plugin may have the county or city saved as Chinese text (for example 臺北市). This changes it to the code the Moksa dropdown uses, in customer profiles only. Orders are not changed.', 'moksa-for-woocommerce' );
		if ( self::on_tools_page() ) {
			// 計數結果快取 10 分鐘，執行轉換後清掉；按下按鈕那次請求不再先掃一遍。
			$count = get_transient( self::TOOL . '_count' );
			if ( ! is_array( $count ) ) {
				$count = self::scan();
				set_transient( self::TOOL . '_count', $count, 10 * MINUTE_IN_SECONDS );
			}
			$desc .= ' ' . sprintf(
				/* translators: 1: number of customers, 2: number of address fields */
				__( 'Customers to update now: %1$d (%2$d address fields).', 'moksa-for-woocommerce' ),
				$count['users'],
				$count['fields']
			);
		}
		$tools[ self::TOOL ] = [
			'name'     => __( 'Convert Taiwan county names in customer addresses', 'moksa-for-woocommerce' ),
			'button'   => __( 'Convert', 'moksa-for-woocommerce' ),
			'desc'     => $desc,
			'callback' => [ __CLASS__, 'run_tool' ],
		];
		return $tools;
	}

	/** 只有在「狀態 › 工具」頁才計數（工具清單在別處也會被讀）。 */
	private static function on_tools_page(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return null !== $screen && 'woocommerce_page_wc-status' === $screen->id && 'tools' === sanitize_key( (string) \Moksafowo\Http\Input::get( 'tab' ) ) && ! \Moksafowo\Http\Input::has_get( 'action' );
	}

	public static function run_tool(): string {
		$done = self::scan( true );
		delete_transient( self::TOOL . '_count' );
		return sprintf(
			/* translators: 1: number of customers, 2: number of address fields */
			__( 'Updated %1$d customers (%2$d address fields).', 'moksa-for-woocommerce' ),
			$done['users'],
			$done['fields']
		);
	}
}
