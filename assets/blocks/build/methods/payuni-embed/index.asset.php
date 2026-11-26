<?php
/**
 * 手寫的統一金流免跳轉信用卡區塊元件相依清單（同 tappay：raw JS，不經 build）。
 * moksafowo-payuni-sdk 是統一金流官方 SDK，由 Embed::register_sdk() 註冊。
 */
return array(
	'dependencies' => array(
		'wc-blocks-registry',
		'wc-settings',
		'wp-element',
		'wp-html-entities',
		'moksafowo-payuni-sdk',
	),
	'version'      => MOKSAFOWO_VERSION,
);
