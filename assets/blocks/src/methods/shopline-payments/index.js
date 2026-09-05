/**
 * Block Checkout client registrations for every Shopline Payments gateway.
 *
 * 設定的取得走 shared/payment-method-data.js —— WC 11.1 起改成單一
 * `paymentMethodData` 物件，舊的 `<id>_data` 已不存在，那支 helper 兩種都吃。
 * 開啟的 Shopline Payments gateway，不論勾了幾個。
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getPaymentMethodData } from '../../shared/payment-method-data';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const SHOPLINE_IDS = [
	'moksafowo_shopline_payments',
];

SHOPLINE_IDS.forEach( ( id ) => {
	const settings = getPaymentMethodData( id );
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
