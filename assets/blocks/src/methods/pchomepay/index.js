/**
 * Block Checkout client registrations for every PChomePay gateway.
 *
 * Each gateway 走自己的 `{id}_data` setting key（PHP 端 PchomepayBlocksMethod
 * 透過 get_payment_method_data() 注入），所以單支 bundle 自動 cover 所有
 * 開啟的 PChomePay gateway，不論勾了幾個。
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const PCHOMEPAY_IDS = [
	'moksafowo_pchomepay_card',
	'moksafowo_pchomepay_pi',
	'moksafowo_pchomepay_atm',
	'moksafowo_pchomepay_barcode',
	'moksafowo_pchomepay_cvs711',
	'moksafowo_pchomepay_cvsfamily',
	'moksafowo_pchomepay_cvshilife',
];

PCHOMEPAY_IDS.forEach( ( id ) => {
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
			settings.title || __( '支付連付款', 'mo-ectools' )
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
