/**
 * Block Checkout client registrations for every PChomePay gateway.
 *
 * 設定的取得走 shared/payment-method-data.js —— WC 11.1 起改成單一
 * `paymentMethodData` 物件，舊的 `<id>_data` 已不存在，那支 helper 兩種都吃。
 * 開啟的 PChomePay gateway，不論勾了幾個。
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getPaymentMethodData } from '../../shared/payment-method-data';
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
			settings.title || __( 'PChomePay', 'moksa-for-woocommerce' )
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
