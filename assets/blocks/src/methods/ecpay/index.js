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
	'mo_ecpay_unified',           // single-entry mode：ECPay 綠界（ChoosePayment=ALL）
	'mo_ecpay_credit',
	'mo_ecpay_atm',
	'mo_ecpay_cvs',
	'mo_ecpay_barcode',
	'mo_ecpay_webatm',
	'mo_ecpay_credit_inst3',
	'mo_ecpay_credit_inst6',
	'mo_ecpay_credit_inst12',
	'mo_ecpay_credit_inst18',
	'mo_ecpay_credit_inst24',
	'mo_ecpay_applepay',
	'mo_ecpay_twqr',
	'mo_ecpay_bnpl',
	'mo_ecpay_weixin',
	'mo_ecpay_jkopay',
	'mo_ecpay_ipass',
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
