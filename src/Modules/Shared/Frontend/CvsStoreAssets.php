<?php

declare( strict_types=1 );

namespace Moksafowo\Modules\Shared\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * 共用超商選店卡片資產(CSS + JS helper)。各物流商選店 enqueue 時呼叫 enqueue()
 * 載入共用卡片樣式 + moksafowoCvsStore helper,並把 SCRIPT handle 當自家 store-selector
 * script 的相依,確保 helper 先載入。
 */
final class CvsStoreAssets {

	const STYLE  = 'moksafowo-cvs-store';
	const SCRIPT = 'moksafowo-cvs-store-card';
	const FIELDS = 'moksafowo-cvs-checkout-fields';

	public static function enqueue(): void {
		$base = 'src/Modules/Shared/Frontend/assets/';

		$css = MOKSAFOWO_PLUGIN_DIR . $base . 'css/cvs-store.css';
		wp_enqueue_style(
			self::STYLE,
			MOKSAFOWO_PLUGIN_URL . $base . 'css/cvs-store.css',
			[],
			file_exists( $css ) ? (string) filemtime( $css ) : MOKSAFOWO_VERSION
		);

		// 欄位保存要先於卡片 helper 載入：submitForm() 會呼叫它。jQuery 只用來聽
		// updated_checkout（WC 的自訂事件，原生監聽器收不到），沒有也能運作。
		$fields = MOKSAFOWO_PLUGIN_DIR . $base . 'js/cvs-checkout-fields.js';
		if ( ! wp_script_is( self::FIELDS, 'registered' ) ) {
			wp_register_script(
				self::FIELDS,
				MOKSAFOWO_PLUGIN_URL . $base . 'js/cvs-checkout-fields.js',
				[ 'jquery' ],
				file_exists( $fields ) ? (string) filemtime( $fields ) : MOKSAFOWO_VERSION,
				true
			);
		}
		wp_enqueue_script( self::FIELDS );

		$js = MOKSAFOWO_PLUGIN_DIR . $base . 'js/cvs-store-card.js';
		if ( ! wp_script_is( self::SCRIPT, 'registered' ) ) {
			wp_register_script(
				self::SCRIPT,
				MOKSAFOWO_PLUGIN_URL . $base . 'js/cvs-store-card.js',
				[ self::FIELDS ],
				file_exists( $js ) ? (string) filemtime( $js ) : MOKSAFOWO_VERSION,
				true
			);
		}
		wp_enqueue_script( self::SCRIPT );
	}
}
