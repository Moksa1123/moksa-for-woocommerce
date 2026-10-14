// WC 11.1 起設定改放在單一 paymentMethodData（以 gateway id 為 key），舊的 `<id>_data` 已不存在；
// 只讀舊 key 會讓商家設定的標題、說明在區塊結帳失效。兩種都吃：先新後舊。
const linepay_bundle = window.wc.wcSettings.getSetting( 'paymentMethodData', null );
const linepay_settings = ( linepay_bundle && linepay_bundle[ 'moksafowo-linepay' ] )
	|| window.wc.wcSettings.getSetting( 'moksafowo-linepay_data', {} );
const linepay_label = window.wp.htmlEntities.decodeEntities( linepay_settings.title )
	|| window.wp.i18n.__( 'LINE Pay', 'moksa-for-woocommerce' );

const linepay_content = () => {
	return window.wp.htmlEntities.decodeEntities( linepay_settings.description || '' );
};

const LINEPay_Options = {
	name: 'moksafowo-linepay',
	label: linepay_label,
	content: Object( window.wp.element.createElement )( linepay_content, null ),
	edit: Object( window.wp.element.createElement )( linepay_content, null ),
	canMakePayment: () => true,
	// 不覆寫 placeOrderButtonLabel → Block checkout 用預設「下單購買 · NT$XXX」
	ariaLabel: linepay_label,
	supports: {
		features: linepay_settings.supports,
	},
};
window.wc.wcBlocksRegistry.registerPaymentMethod( LINEPay_Options );
