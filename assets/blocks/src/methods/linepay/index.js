/**
 * LINE Pay payment method (Block Checkout).
 *
 * Server side: src/Modules/Linepay/Blocks/PaymentMethodType.php
 *
 * @package mowp
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

const PAYMENT_METHOD_ID = 'moksafowo_linepay';

const settings = getSetting( `${ PAYMENT_METHOD_ID }_data`, {} );
const label = decodeEntities( settings.title || __( 'LINE Pay', 'mo-ectools' ) );

const Label = ( { components } ) => {
	const { PaymentMethodLabel } = components;
	return <PaymentMethodLabel text={ label } />;
};

const Content = () =>
	decodeEntities(
		settings.description ||
			__( '使用 LINE Pay 完成付款，將跳轉至 LINE Pay 付款頁。', 'mo-ectools' )
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
