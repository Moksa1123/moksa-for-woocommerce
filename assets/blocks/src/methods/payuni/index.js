/**
 * Block Checkout client registrations for every PAYUNi gateway.
 *
 * Each gateway calls `wp_set_script_translations` + `wp_localize_script` on
 * its own `mo_<id>_data` key, so this single bundle picks up however many
 * gateways are enabled.
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const PAYUNI_IDS = [
	'moksafowo_payuni_unified',  // single-entry mode: PAYUNi 統一金流
	'moksafowo_payuni_credit',
	'moksafowo_payuni_atm',
	'moksafowo_payuni_cvs',
	'moksafowo_payuni_aftee',
	'moksafowo_payuni_applepay',
	'moksafowo_payuni_googlepay',
	'moksafowo_payuni_samsungpay',
	'moksafowo_payuni_linepay',
	'moksafowo_payuni_unionpay',
	'moksafowo_payuni_icash',
	'moksafowo_payuni_jkopay',
	'moksafowo_payuni_credit_red',
	'moksafowo_payuni_installment_3',
	'moksafowo_payuni_installment_6',
	'moksafowo_payuni_installment_9',
	'moksafowo_payuni_installment_12',
	'moksafowo_payuni_installment_18',
	'moksafowo_payuni_installment_24',
	'moksafowo_payuni_installment_30',
];

PAYUNI_IDS.forEach( ( id ) => {
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
			settings.title || __( 'PAYUNi 付款', 'moksa-for-woocommerce' )
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
