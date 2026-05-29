var mo_payuni_shipping_info;
var listeners;

(function ($) {
	'use strict';

	$(document).ready(function () {
		movePayuniAttachToFirstPosition();
	});

	if (typeof mo_payuni_shipping_object !== 'undefined') {
       	mo_payuni_shipping_info = mo_payuni_shipping_object;
    }

	$(document.body).on('updated_checkout.wpbr_payuni_event_attach', function (e, data) {
		
		if ( typeof data.fragments.mo_payuni_shipping_info == 'undefined' || typeof data.fragments.mo_payuni_shipping_info.shipping_data == 'undefined' ) {
			return;
		}

		if ( typeof data.fragments.mo_payuni_shipping_info.shipping_data.ShipType == 'undefined' && data.fragments.ecpay_shipping_info == undefined) {
			return;//do nothing
		}

		if ( typeof data.fragments.mo_payuni_shipping_info.shipping_data.ShipType !== 'undefined' ) {
			//detach and reattach the handler setTimout to 500 ms
			$(document.body).off('updated_checkout.wpbr_payuni_shipping', update_payuni_cvs_shipping_fields);
			movePayuniShippingToEnd();
			$(document.body).on('updated_checkout.wpbr_payuni_shipping', update_payuni_cvs_shipping_fields);
	        
		} else {
			// reset the order of the handler
			resetUpdatedCheckoutOrder();
		}
	});

	function resetUpdatedCheckoutOrder() {
		var currentListeners = $._data($(document.body)[0], 'events').updated_checkout;

		//find the payuni event attach listener
		const attachListener = currentListeners.find(listener => 
			listener.namespace === 'wpbr_payuni_event_attach'
		);
		if (attachListener) {
			const attachIndex = currentListeners.indexOf(attachListener);
			//find the payuni shipping listener and move it after the attach listener
			const shippingListener = currentListeners.find(listener => 
				listener.namespace === 'wpbr_payuni_shipping'
			);
			if (shippingListener) {
				currentListeners.splice(attachIndex + 1, 0, currentListeners.splice(currentListeners.indexOf(shippingListener), 1)[0]);
			}
		}

	}

	function movePayuniShippingToEnd() {
		var currentListeners = $._data($(document.body)[0], 'events').updated_checkout;
		//using find to get the listener
		const listener = currentListeners.find(listener => listener.namespace == 'wpbr_payuni_shipping');
		if ( listener ) {
			moveListener(listener, currentListeners.length - 1);
		}

	}

	function movePayuniAttachToFirstPosition() {

		var currentListeners = $._data($(document.body)[0], 'events').updated_checkout;

		const listener = currentListeners.find(listener => 
			listener.namespace === 'wpbr_payuni_event_attach'
		);
		if (listener) {
			moveListener(listener, 0);
		}

	}

	function moveListener(listener, toPosition) {
		var currentListeners = $._data($(document.body)[0], 'events').updated_checkout;
		// Find the index of the listener first
		const fromPosition = currentListeners.indexOf(listener);
		if ( fromPosition == toPosition ) {
			return;
		}

		const toMoveListener = currentListeners.splice(fromPosition, 1)[0];
		currentListeners.splice(toPosition, 0, toMoveListener);
	}

})(jQuery);
