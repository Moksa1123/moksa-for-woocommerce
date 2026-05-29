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
	'mo_payuni_unified',  // single-entry mode: PAYUNi 統一金流
	'mo_payuni_credit',
	'mo_payuni_atm',
	'mo_payuni_cvs',
	'mo_payuni_aftee',
	'mo_payuni_applepay',
	'mo_payuni_googlepay',
	'mo_payuni_samsungpay',
	'mo_payuni_linepay',
	'mo_payuni_unionpay',
	'mo_payuni_icash',
	'mo_payuni_jkopay',
	'mo_payuni_credit_red',
	'mo_payuni_installment_3',
	'mo_payuni_installment_6',
	'mo_payuni_installment_9',
	'mo_payuni_installment_12',
	'mo_payuni_installment_18',
	'mo_payuni_installment_24',
	'mo_payuni_installment_30',
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
			settings.title || __( 'PAYUNi 付款', 'mowp' )
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
