<?php
/**
 * Hand-rolled asset manifest for the TapPay block-checkout JS bundle.
 *
 * 同 ECPay / PChomePay 模式：raw JS（無 JSX）+ 手動 dependency manifest，
 * 免 build step。多一個 'moksafowo-tappay-sdk' dependency — tpdirect SDK（hosted-only，
 * 由 TappayBlocksMethod::get_payment_method_script_handles() wp_register_script）。
 */
return array(
	'dependencies' => array(
		'wc-blocks-registry',
		'wc-settings',
		'wp-element',
		'wp-html-entities',
		'wp-i18n',
		'moksafowo-tappay-sdk',
	),
	'version'      => MOKSAFOWO_VERSION,
);
