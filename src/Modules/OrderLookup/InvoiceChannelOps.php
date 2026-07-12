<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\OrderLookup;

defined( 'ABSPATH' ) || exit;

/**
 * 電子發票「開立方式」的查詢與切換 —— 會員載具 / 手機條碼 / 自然人憑證 / 紙本 / 捐贈 / 統編。
 *
 * 每種開立方式是個別 yes/no option(moksafowo_<p>_invoice_channel_* 或 _invoice_allow_*),
 * 影響前台結帳的發票欄位選項,故切換走人工確認關卡。查詢唯讀。
 */
final class InvoiceChannelOps {

	const CAP = 'manage_woocommerce';

	private static function providers(): array {
		return array(
			'ecpay'    => __( '綠界電子發票', 'mo-ectools' ),
			'ezpay'    => __( 'ezPay 電子發票', 'mo-ectools' ),
			'smilepay' => __( '速買配電子發票', 'mo-ectools' ),
			'paynow'   => __( 'PayNow 電子發票', 'mo-ectools' ),
			'amego'    => __( 'Amego 電子發票', 'mo-ectools' ),
		);
	}

	private static function channels(): array {
		return array(
			'member' => array( 'channel_member', __( '會員載具', 'mo-ectools' ) ),
			'mobile' => array( 'channel_mobile', __( '手機條碼', 'mo-ectools' ) ),
			'cert'   => array( 'channel_cert', __( '自然人憑證', 'mo-ectools' ) ),
			'paper'  => array( 'channel_paper', __( '紙本發票', 'mo-ectools' ) ),
			'donate' => array( 'allow_donate', __( '捐贈', 'mo-ectools' ) ),
			'b2b'    => array( 'allow_b2b', __( '統一編號（公司戶）', 'mo-ectools' ) ),
		);
	}

	/** PayNow 無會員載具,其餘五家均支援。 */
	private static function supported( string $provider ): array {
		$keys = array( 'mobile', 'cert', 'paper', 'donate', 'b2b' );
		if ( 'paynow' !== $provider ) {
			array_unshift( $keys, 'member' );
		}
		return $keys;
	}

	private static function option_key( string $provider, string $channel ): string {
		$ch = self::channels();
		return 'moksafowo_' . $provider . '_invoice_' . $ch[ $channel ][0];
	}

	private static function is_on( string $provider, string $channel ): bool {
		return 'yes' === get_option( self::option_key( $provider, $channel ), 'yes' );
	}

	private static function norm( string $s ): string {
		$s = mb_strtolower( trim( $s ) );
		return str_replace( array( ' ', '　', '（', '）', '(', ')', '-', '_' ), '', $s );
	}

	private static function resolve_provider( string $input ): string {
		$input = trim( $input );
		$map   = self::providers();
		$key   = sanitize_key( $input );
		if ( isset( $map[ $key ] ) ) {
			return $key;
		}
		foreach ( $map as $slug => $label ) {
			if ( $label === $input || ( '' !== $input && ( false !== mb_strpos( $label, $input ) || false !== mb_strpos( $input, $label ) ) ) ) {
				return $slug;
			}
		}
		foreach ( $map as $slug => $label ) {
			$head = mb_substr( $label, 0, 2 );
			if ( '' !== $head && false !== mb_strpos( $input, $head ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * 把使用者給的開立方式名稱解析成 channel key。
	 *
	 * @param string[] $names    名稱。
	 * @param string[] $supported 該 provider 支援的 key。
	 * @return array{0:string[], 1:string[]} [ 命中 key[], 未命中名稱[] ]
	 */
	private static function resolve_channels( array $names, array $supported ): array {
		$ch        = self::channels();
		$matched   = array();
		$unmatched = array();
		foreach ( $names as $name ) {
			$n = self::norm( (string) $name );
			if ( '' === $n ) {
				continue;
			}
			$hit = '';
			foreach ( $supported as $key ) {
				$label = self::norm( $ch[ $key ][1] );
				if ( $label === $n || false !== mb_strpos( $label, $n ) || false !== mb_strpos( $n, $label ) || $key === $n ) {
					$hit = $key;
					break;
				}
			}
			if ( '' !== $hit && ! in_array( $hit, $matched, true ) ) {
				$matched[] = $hit;
			} elseif ( '' === $hit ) {
				$unmatched[] = (string) $name;
			}
		}
		return array( $matched, $unmatched );
	}

	private static function names_arg( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[,，、和與]+/u', $raw ) ?: array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	private static function truthy( $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		$s = mb_strtolower( trim( (string) $v ) );
		return ! in_array( $s, array( '0', 'false', 'no', 'off', 'disable', 'disabled', '停用', '關', '關閉' ), true );
	}

	/**
	 * @param mixed $input { provider: string }。
	 * @return array<string,mixed>
	 */
	public static function list_channels( $input ): array {
		if ( ! current_user_can( self::CAP ) ) {
			return array( 'channels' => array() );
		}
		$provider = self::resolve_provider( is_array( $input ) && isset( $input['provider'] ) ? (string) $input['provider'] : '' );
		if ( '' === $provider ) {
			return array(
				'channels' => array(),
				'message'  => __( '找不到此發票模組。', 'mo-ectools' ),
			);
		}
		$ch   = self::channels();
		$rows = array();
		foreach ( self::supported( $provider ) as $key ) {
			$rows[] = array(
				'key'     => $key,
				'name'    => $ch[ $key ][1],
				'enabled' => self::is_on( $provider, $key ),
			);
		}
		return array(
			'provider' => $provider,
			'channels' => $rows,
		);
	}

	/**
	 * @param mixed $args { provider: string, channels: array|string, enable: bool }。
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function toggle_prepare( $args ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$provider = self::resolve_provider( is_array( $args ) && isset( $args['provider'] ) ? (string) $args['provider'] : '' );
		if ( '' === $provider ) {
			return new \WP_Error( 'moksafowo_ai_bad_provider', __( '找不到此發票模組(綠界/ezPay/速買配/PayNow/Amego)。', 'mo-ectools' ) );
		}
		[ $matched, $unmatched ] = self::resolve_channels(
			self::names_arg( is_array( $args ) ? ( $args['channels'] ?? array() ) : array() ),
			self::supported( $provider )
		);
		if ( empty( $matched ) ) {
			return new \WP_Error( 'moksafowo_ai_no_match', __( '找不到對應的開立方式(會員載具/手機條碼/自然人憑證/紙本/捐贈/統編)。', 'mo-ectools' ) );
		}
		$enable = self::truthy( is_array( $args ) ? ( $args['enable'] ?? true ) : true );

		$ch      = self::channels();
		$labels  = array_map( static fn( $k ) => $ch[ $k ][1], $matched );
		$summary = sprintf(
			/* translators: 1: enable/disable, 2: provider, 3: channel labels */
			__( '%1$s %2$s 的開立方式:%3$s。', 'mo-ectools' ),
			$enable ? __( '啟用', 'mo-ectools' ) : __( '停用', 'mo-ectools' ),
			self::providers()[ $provider ],
			implode( '、', $labels )
		);
		if ( ! empty( $unmatched ) ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: unmatched names */
				__( '(無法對應並略過:%s)', 'mo-ectools' ),
				implode( '、', $unmatched )
			);
		}

		return array(
			'provider' => $provider,
			'channels' => $matched,
			'enable'   => $enable,
			'summary'  => $summary,
		);
	}

	/**
	 * @param array<string,mixed> $params toggle_prepare() 的回傳。
	 * @return string|\WP_Error
	 */
	public static function toggle_apply( array $params ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'moksafowo_ai_cap', __( '此操作需要「管理 WooCommerce」權限。', 'mo-ectools' ) );
		}
		$provider = (string) ( $params['provider'] ?? '' );
		$channels = is_array( $params['channels'] ?? null ) ? $params['channels'] : array();
		$enable   = ! empty( $params['enable'] );
		$ch       = self::channels();
		if ( '' === $provider || empty( $channels ) ) {
			return new \WP_Error( 'moksafowo_ai_bad_input', __( '資料不完整,無法變更。', 'mo-ectools' ) );
		}

		$labels = array();
		foreach ( $channels as $key ) {
			if ( ! isset( $ch[ $key ] ) ) {
				continue;
			}
			update_option( self::option_key( $provider, $key ), $enable ? 'yes' : 'no' );
			$labels[] = $ch[ $key ][1];
		}

		return sprintf(
			/* translators: 1: enable/disable, 2: provider, 3: channel labels */
			__( '✅ 已%1$s %2$s 的開立方式:%3$s。', 'mo-ectools' ),
			$enable ? __( '啟用', 'mo-ectools' ) : __( '停用', 'mo-ectools' ),
			self::providers()[ $provider ] ?? $provider,
			implode( '、', $labels )
		);
	}
}
