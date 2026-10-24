<?php
/**
 * Hand-rolled asset manifest for the Shopline Payments on-page card block bundle.
 *
 * 同 TapPay：raw JS（無 JSX）免 build，多一個 'moksafowo-slp-sdk' dependency
 * （由 ShoplinePaymentsBlocksMethod::extra_script_handles() 註冊的官方 CDN SDK）。
 */
return array(
	'dependencies' => array(
		'wc-blocks-registry',
		'wc-settings',
		'wp-element',
		'moksafowo-slp-sdk',
	),
	'version'      => MOKSAFOWO_VERSION,
);
