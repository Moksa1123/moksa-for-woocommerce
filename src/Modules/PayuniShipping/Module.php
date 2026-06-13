<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\PayuniShipping;

use MoksaWeb\Mowc\Crypto\Vault;
use MoksaWeb\Mowc\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'payuni_shipping';
	}

	public function label(): string {
		return __( 'PAYUNi 物流 — 7-11 B2C/C2C 常溫/冷凍 + 黑貓常溫/冷凍/冷藏（共 7 種）', 'mo-ectools' );
	}

	public function category(): string {
		return 'shipping';
	}

	public function name(): string {
		return __( 'PAYUNi 物流', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '7-11 + 黑貓 — C2C/B2C × 常溫/冷藏/冷凍 7 種', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( '7-11 大宗常溫', 'mo-ectools' ),
			__( '7-11 大宗冷凍', 'mo-ectools' ),
			__( '7-11 店到店常溫', 'mo-ectools' ),
			__( '7-11 店到店冷凍', 'mo-ectools' ),
			__( '黑貓常溫', 'mo-ectools' ),
			__( '黑貓冷藏', 'mo-ectools' ),
			__( '黑貓冷凍', 'mo-ectools' ),
		];
	}

	public function settings_section(): string {
		return 'moksafowo-payuni-shipping';
	}

	public function boot(): void {
		// PayUni payment 模組可能未啟用，但 PayuniShipping 仍會讀同一組
		// hashkey/iv option（fork 共用 credentials 設計）。
		// Vault::wrap_option 是 idempotent，PayUni Payment 已啟用時雙重呼叫無害；
		// PayUni Payment 未啟用時這邊補上，避免 get_option 拿到 MOWPv1: 密文當金鑰。
		// Phase B（v0.5.63）：同時 wrap 新 mo_* 跟 legacy 兩組。
		foreach ( [
			'moksafowo_payuni_payment_hashkey',
			'moksafowo_payuni_payment_hashkey_test',
			'moksafowo_payuni_payment_hashiv',
			'moksafowo_payuni_payment_hashiv_test',
			'moksafowo_payuni_payment_hashkey',
			'moksafowo_payuni_payment_hashkey_test',
			'moksafowo_payuni_payment_hashiv',
			'moksafowo_payuni_payment_hashiv_test',
		] as $opt ) {
			Vault::wrap_option( $opt );
		}

		PayuniShipping::init();
	}
}
