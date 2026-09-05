/**
 * Block Checkout client registrations for every NewebPay gateway.
 *
 * 設定的取得走 shared/payment-method-data.js —— WC 11.1 起改成單一
 * `paymentMethodData` 物件，舊的 `<id>_data` 已不存在，那支 helper 兩種都吃。
 * 開啟的 NewebPay gateway，不論勾了幾個。
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getPaymentMethodData } from '../../shared/payment-method-data';
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
			settings.title || __( 'NewebPay', 'moksa-for-woocommerce' )
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
