/**
 * Block Checkout client registrations for every ECPay gateway.
 *
 * Each gateway 走自己的 `mo_<id>_data` setting key（PHP 端 EcpayBlocksMethod
 * 透過 get_payment_method_data() 注入），所以單支 bundle 自動 cover 所有開啟的
 * ECPay gateway，不論勾了幾個。
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const ECPAY_IDS = [
	'moksafowo_ecpay_unified',           // single-entry mode：ECPay 綠界（ChoosePayment=ALL）
	'moksafowo_ecpay_credit',
	'moksafowo_ecpay_atm',
	'moksafowo_ecpay_cvs',
	'moksafowo_ecpay_barcode',
	'moksafowo_ecpay_webatm',
	'moksafowo_ecpay_credit_inst3',
	'moksafowo_ecpay_credit_inst6',
	'moksafowo_ecpay_credit_inst12',
	'moksafowo_ecpay_credit_inst18',
	'moksafowo_ecpay_credit_inst24',
	'moksafowo_ecpay_applepay',
	'moksafowo_ecpay_twqr',
	'moksafowo_ecpay_bnpl',
	'moksafowo_ecpay_weixin',
	'moksafowo_ecpay_jkopay',
	'moksafowo_ecpay_ipass',
];

ECPAY_IDS.forEach( ( id ) => {
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
			settings.title || __( 'ECPay 綠界付款', 'mowp' )
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
