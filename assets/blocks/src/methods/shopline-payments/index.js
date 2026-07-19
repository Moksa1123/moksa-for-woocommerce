/**
 * Block Checkout client registrations for every Shopline Payments gateway.
 *
 * Each gateway 走自己的 `{id}_data` setting key（PHP 端 ShoplinePaymentsBlocksMethod
 * 透過 get_payment_method_data() 注入），所以單支 bundle 自動 cover 所有
 * 開啟的 Shopline Payments gateway，不論勾了幾個。
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const SHOPLINE_IDS = [
	'moksafowo_shopline_payments',
];

SHOPLINE_IDS.forEach( ( id ) => {
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
			settings.title || __( 'Shopline Payments', 'moksa-for-woocommerce' )
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
