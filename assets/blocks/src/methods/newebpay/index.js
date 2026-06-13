/**
 * Block Checkout client registrations for every NewebPay gateway.
 *
 * Each gateway 走自己的 `{id}_data` setting key（PHP 端 NewebpayBlocksMethod
 * 透過 get_payment_method_data() 注入），所以單支 bundle 自動 cover 所有
 * 開啟的 NewebPay gateway，不論勾了幾個。
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const NEWEBPAY_IDS = [
	'moksafowo_newebpay_unified',
	'moksafowo_newebpay_credit',
	'moksafowo_newebpay_credit_installment',
	'moksafowo_newebpay_atm',
	'moksafowo_newebpay_webatm',
	'moksafowo_newebpay_cvs',
	'moksafowo_newebpay_barcode',
	'moksafowo_newebpay_applepay',
	'moksafowo_newebpay_googlepay',
	'moksafowo_newebpay_samsungpay',
	'moksafowo_newebpay_linepay',
	'moksafowo_newebpay_esunwallet',
	'moksafowo_newebpay_taiwanpay',
	'moksafowo_newebpay_twqr',
	'moksafowo_newebpay_alipay',
	'moksafowo_newebpay_wechatpay',
	'moksafowo_newebpay_aftee',
	'moksafowo_newebpay_unionpay',
];

NEWEBPAY_IDS.forEach( ( id ) => {
	const settings = getSetting( id + '_data', null );
	if ( ! settings || ! settings.name ) {
		return;
	}

	const Label = ( props ) => {
		const PaymentMethodLabel = props.components.PaymentMethodLabel;
		return (
			<PaymentMethodLabel
				text={ decodeEntities( settings.title || settings.name ) }
			/>
		);
	};

	const Content = () => (
		<div>{ decodeEntities( settings.description || '' ) }</div>
	);

	registerPaymentMethod( {
		name: settings.name,
		label: <Label />,
		ariaLabel: decodeEntities(
			settings.title || __( '藍新金流付款', 'mo-ectools' )
		),
		content: <Content />,
		edit: <Content />,
		canMakePayment: () => true,
		paymentMethodId: settings.name,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} );
