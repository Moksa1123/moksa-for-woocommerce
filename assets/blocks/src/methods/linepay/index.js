/**
 * LINE Pay payment method (Block Checkout).
 *
 * Server side: src/Modules/Linepay/Blocks/PaymentMethodType.php
 *
 * @package Moksafowo
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const PAYMENT_METHOD_ID = 'moksafowo_linepay';

const settings = getSetting( `${ PAYMENT_METHOD_ID }_data`, {} );
const label = decodeEntities( settings.title || __( 'LINE Pay', 'moksa-for-woocommerce' ) );

const Label = ( { components } ) => {
	const { PaymentMethodLabel } = components;
	return <PaymentMethodLabel text={ label } />;
};

const Content = () =>
	decodeEntities(
		settings.description ||
			__( 'Pay with LINE Pay. You will be redirected to the LINE Pay payment page.', 'moksa-for-woocommerce' )
	);

registerPaymentMethod( {
	name: PAYMENT_METHOD_ID,
	label: <Label />,
	ariaLabel: label,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	paymentMethodId: PAYMENT_METHOD_ID,
	supports: {
		features: settings.supports || [ 'products', 'refunds' ],
	},
} );
