/**
 * Block Checkout client registrations for every PayNow gateway.
 *
 * Each gateway 走自己的 `{id}_data` setting key（PHP 端 PaynowBlocksMethod
 * 透過 get_payment_method_data() 注入），所以單支 bundle 自動 cover 所有
 * 開啟的 PayNow gateway，不論勾了幾個。
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const PAYNOW_IDS = [
	'moksafowo_paynow_credit',
	'moksafowo_paynow_credit_installment',
	'moksafowo_paynow_webatm',
	'moksafowo_paynow_atm',
	'moksafowo_paynow_cvs',
	'moksafowo_paynow_ibon',
	'moksafowo_paynow_famiport',
	'moksafowo_paynow_icash',
	'moksafowo_paynow_unionpay',
];

PAYNOW_IDS.forEach( ( id ) => {
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
			settings.title || __( '立吉富付款', 'mo-ectools' )
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
