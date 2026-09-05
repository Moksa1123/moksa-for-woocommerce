/**
 * Block Checkout client registrations for every ECPay gateway.
 *
 * 設定的取得走 shared/payment-method-data.js —— WC 11.1 起改成單一
 * `paymentMethodData` 物件，舊的 `<id>_data` 已不存在，那支 helper 兩種都吃。
 * ECPay gateway，不論勾了幾個。
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getPaymentMethodData } from '../../shared/payment-method-data';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const ECPAY_IDS = [
	'moksafowo_ecpay_unified',           // single-entry mode：ECPay 綠界（ChoosePayment=ALL）
	'moksafowo_ecpay_credit',
	'moksafowo_ecpay_atm',
	'moksafowo_ecpay_cvs',
	'moksafowo_ecpay_barcode',
	'moksafowo_ecpay_webatm',
	'moksafowo_ecpay_credit_3',
	'moksafowo_ecpay_credit_6',
	'moksafowo_ecpay_credit_12',
	'moksafowo_ecpay_credit_18',
	'moksafowo_ecpay_credit_24',
	'moksafowo_ecpay_applepay',
	'moksafowo_ecpay_twqr',
	'moksafowo_ecpay_bnpl',
	'moksafowo_ecpay_weixin',
	'moksafowo_ecpay_jkopay',
	'moksafowo_ecpay_ipass',
];

ECPAY_IDS.forEach( ( id ) => {
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
			settings.title || __( 'ECPay', 'moksa-for-woocommerce' )
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
