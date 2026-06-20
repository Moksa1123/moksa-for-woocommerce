<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc;

defined( 'ABSPATH' ) || exit;

final class ModuleRegistry {

	private array $modules = [
		'ecpay'             => Modules\Ecpay\Module::class,
		'newebpay'          => Modules\Newebpay\Module::class,
		'smilepay'          => Modules\Smilepay\Module::class,
		'linepay'           => Modules\Linepay\Module::class,
		'payuni'            => Modules\Payuni\Module::class,
		'paynow'            => Modules\Paynow\Module::class,
		'pchomepay'         => Modules\Pchomepay\Module::class,
		'tappay'            => Modules\Tappay\Module::class,
		'shopline_payments' => Modules\ShoplinePayments\Module::class,
		'ecpay_shipping'    => Modules\EcpayShipping\Module::class,
		'newebpay_shipping' => Modules\NewebpayShipping\Module::class,
		'payuni_shipping'   => Modules\PayuniShipping\Module::class,
		'smilepay_shipping' => Modules\SmilepayShipping\Module::class,
		'ezpay_invoice'     => Modules\EzpayInvoice\Module::class,
		'ecpay_invoice'     => Modules\EcpayInvoice\Module::class,
		'paynow_invoice'    => Modules\PaynowInvoice\Module::class,
		'amego_invoice'     => Modules\AmegoInvoice\Module::class,
		'smilepay_invoice'  => Modules\SmilepayInvoice\Module::class,
		'order_lookup'      => Modules\OrderLookup\Module::class,
		'ai_assistant'      => Modules\AiAssistant\Module::class,
		'customer_service'  => Modules\CustomerService\Module::class,
	];

	private array $booted = [];

	public function boot(): void {
		foreach ( $this->modules as $key => $class ) {
			if ( ! $this->is_enabled( $key ) ) {
				continue;
			}
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$module = new $class();
			$module->boot();
			$this->booted[ $key ] = $module;
		}

		do_action( 'moksafowo_modules_booted', $this->booted );
	}

	public function is_enabled( string $key ): bool {
		$option = sprintf( 'moksafowo_%s_enabled', $key );
		return get_option( $option, 'no' ) === 'yes';
	}

	public function all(): array {
		return $this->modules;
	}

	public function booted( string $key ): ?Modules\AbstractModule {
		return $this->booted[ $key ] ?? null;
	}
}
