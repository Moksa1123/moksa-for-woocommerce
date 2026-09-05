/**
 * Block Checkout client registrations for every PayNow gateway.
 *
 * 設定的取得走 shared/payment-method-data.js —— WC 11.1 起改成單一
 * `paymentMethodData` 物件，舊的 `<id>_data` 已不存在，那支 helper 兩種都吃。
 * 開啟的 PayNow gateway，不論勾了幾個。
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getPaymentMethodData } from '../../shared/payment-method-data';
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
			settings.title || __( 'PayNow', 'moksa-for-woocommerce' )
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
