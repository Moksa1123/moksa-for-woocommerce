<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Sms;

use MoksaWeb\Mowc\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'sms';
	}

	public function label(): string {
		return __( 'SMS 簡訊通知 — SmilePay / 三竹 / 自訂 provider', 'mo-ectools' );
	}

	public function category(): string {
		return 'notification';
	}

	public function name(): string {
		return __( 'SMS 簡訊', 'mo-ectools' );
	}

	public function tagline(): string {
		return __( '尚未實作 — 通用簡訊（SmilePay / PAYUNi / ECPay 共用）', 'mo-ectools' );
	}

	public function methods(): array {
		return [
			__( 'SmilePay SMS', 'mo-ectools' ),
			__( '三竹簡訊', 'mo-ectools' ),
			__( '訂單狀態通知', 'mo-ectools' ),
			__( '物流到店通知', 'mo-ectools' ),
		];
	}

	public function boot(): void {
	}
}
