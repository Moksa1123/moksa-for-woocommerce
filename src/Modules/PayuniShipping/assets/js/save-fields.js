/**
 * PayUni Checkout Form Field Preservation
 * Saves and restores checkout form fields when navigating to/from CVS store selection
 * Uses sessionStorage for tab-specific data that auto-clears when tab closes
 * 
 * @since 1.5.9
 */
(function (window, document, $) {

	const checkoutForm = {};
	
	// Flag to track if we've already restored the form
	checkoutForm.hasRestored = false;

	checkoutForm.init = function () {
		// Check what's in sessionStorage for debugging if needed
		const storedData = window.sessionStorage.getItem(STORAGE_KEY);
		if (storedData) {
			try {
				JSON.parse(storedData); // Validate JSON format
			} catch (e) {
				console.error('PayUni: Error parsing stored data:', e);
			}
		}
		
		// Wait for checkout form to be ready
		if ($('form.woocommerce-checkout').length === 0) {
			// Try again after a short delay if form not ready
			setTimeout(checkoutForm.init, 500);
			return;
		}
		
		// Check if we're returning from CVS selection (has POST data)
		const returningFromCVS = $('input[name="moksafowo_payuni_selected_store_id"]:not(#moksafowo_payuni_selected_store_id)').length > 0;
		if (returningFromCVS) {
			// 減少延遲時間，更快復原
			setTimeout(function() {
				checkoutForm.checkStorage();
			}, 500);
		} else {
			checkoutForm.checkStorage();
		}
		
		checkoutForm.startListener();
	}

	// Fields to ignore when saving (CVS related fields and temporary fields)
	const ignoreFields = [
		'moksafowo_payuni_storename',
		'moksafowo_payuni_storeid',
		'moksafowo_payuni_storeaddress',
		'moksafowo_payuni_selected_store_id',
		'moksafowo_payuni_selected_store_name',
		'moksafowo_payuni_selected_store_address',
		'moksafowo_payuni_selected_store_data',
		'_wpnonce',
		'_wp_http_referer',
		'terms',
		'terms-field',
		'mailchimp_woocommerce_newsletter',
		'g-recaptcha-response',
		// All WC checkout nonces — restoring a stale nonce after coming back
		// from PAYUNi store map breaks `wp_verify_nonce()` and triggers
		// 「我們無法處理您的訂單」(WC core class-wc-checkout.php:1289).
		'woocommerce-process-checkout-nonce',
		'woocommerce-login-nonce',
		'woocommerce-register-nonce',
		'woocommerce-edit-account-nonce',
		'woocommerce-edit-address-nonce',
		'woocommerce-pay-for-order-nonce',
	];

	// Storage key - using sessionStorage (no timestamp needed)
	const STORAGE_KEY = 'moksafowo_payuni_woo_form';

	/**
	 * Check sessionStorage and restore form fields
	 */
	checkoutForm.checkStorage = function () {
		// Only restore once per page load
		if (checkoutForm.hasRestored) {
			return;
		}
		
		let formValues = window.sessionStorage.getItem(STORAGE_KEY);

		if (formValues && JSON.parse(formValues)) {
			formValues = JSON.parse(formValues);
			
			// Wait a bit more for WooCommerce to render all fields
			const $checkoutForm = $('form.woocommerce-checkout');
			if ($checkoutForm.length === 0) {
				setTimeout(function() {
					checkoutForm.checkStorage();
				}, 500);
				return;
			}
			
			// Mark as restored to prevent multiple restorations
			checkoutForm.hasRestored = true;

			// Track fields that were restored
			let restoredCount = 0;
			let notFoundFields = [];
			
			for (var i in formValues) {
				// Skip ignored fields
				if (ignoreFields.includes(formValues[i].name)) {
					continue;
				}

				// Skip fields that start with underscore (WordPress internal fields)
				if (formValues[i].name.substring(0, 1) === '_') {
					continue;
				}

				var $item = jQuery('[name="' + formValues[i].name + '"]');
				
				if ($item.length === 0) {
					// Try with ID selector as fallback
					$item = jQuery('#' + formValues[i].name);
					if ($item.length === 0) {
						notFoundFields.push(formValues[i].name);
						continue; // Field doesn't exist, skip
					}
				}

				let restored = false;
				
				switch ($item.prop('tagName')) {
					case 'INPUT':
						if ($item.attr('type') == 'checkbox') {
							// 使用 trigger('click') 來觸發 WooCommerce 事件
							// Support multiple checkboxes with same name
							var $checkbox = $item.filter('[value="' + formValues[i].value + '"]');
							if ($checkbox.length > 0 && !$checkbox.prop('checked')) {
								$checkbox.trigger('click');
								restored = true;
							}
							break;
						} else if ($item.attr('type') == 'radio') {
							// 找到對應值的 radio 並使用 trigger('click')
							var $radio = jQuery('[name="' + formValues[i].name + '"][value="' + formValues[i].value + '"]');
							if ($radio.length > 0 && !$radio.prop('checked')) {
								$radio.trigger('click');
								restored = true;
							}
							break;
						} else {
							// Text, email, tel, etc. - 智能觸發：只在值真正改變時觸發
							var currentVal = $item.val();
							if (currentVal !== formValues[i].value) {
								$item.val(formValues[i].value);
								$item.trigger('change');
								restored = true;
							}
							break;
						}
					case 'TEXTAREA':
						// 智能觸發：只在值真正改變時觸發
						var currentVal = $item.val();
						if (currentVal !== formValues[i].value) {
							$item.val(formValues[i].value);
							$item.trigger('change');
							restored = true;
						}
						break;
					case 'SELECT':
						var oldVal = $item.val();
						if (Array.isArray(oldVal)) {
							// Multi-select
							if (!oldVal.includes(formValues[i].value)) {
								oldVal.push(formValues[i].value);
								$item.val(oldVal);
								$item.trigger('change');
								restored = true;
							}
						} else {
							// Single select - 智能觸發：只在值真正改變時觸發
							if (oldVal !== formValues[i].value) {
								$item.val(formValues[i].value);
								$item.trigger('change');
								restored = true;
							}
						}
						break;
					default:
						break;
				}
				
				if (restored) {
					restoredCount++;
				}
			}
			
			if (notFoundFields.length > 0) {
				// If most fields weren't found, the form might not be ready yet
				if (notFoundFields.length > formValues.length * 0.5) {
					// Reset the flag to allow another attempt
					checkoutForm.hasRestored = false;
				}
			}
			
			// 不需要額外觸發 change 事件，因為我們已經在每個欄位智能觸發了
		}
	}

	/**
	 * Start listening to form changes
	 */
	checkoutForm.startListener = function () {
		const $form = $('form.woocommerce-checkout');
		
		// Listen for checkout updates to restore form after returning from CVS
		let restorationAttempts = 0;
		$(document.body).on('updated_checkout', function() {
			// Only try to restore if we have stored data
			if (window.sessionStorage.getItem(STORAGE_KEY)) {
				// Reset the flag to allow restoration after each checkout update
				// This is needed because WooCommerce might rebuild the form multiple times
				if (restorationAttempts < 3) {
					checkoutForm.hasRestored = false;
					restorationAttempts++;
					
					// 減少延遲時間，加快復原速度
					setTimeout(function() {
						checkoutForm.checkStorage();
					}, 300);
				}
			}
		});
		
		// Save form data on any change
		$form.on('change', 'input, select, textarea', function() {
			// Don't save if we're currently selecting a CVS store
			if (window.moksafowoPayuniSelectingStore) {
				return;
			}
			
			let wooForm = $form.serializeArray().filter(function(field) {
				// Filter out ignored fields
				if (ignoreFields.indexOf(field.name) !== -1) {
					return false;
				}
				// Filter out WordPress internal fields
				if (field.name.substring(0, 1) === '_') {
					return false;
				}
				// Keep all non-empty values and also keep empty values for important fields
				if (field.value !== '') {
					return true;
				}
				// Keep empty values for important fields that might be intentionally cleared
				if (['billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone', 'shipping_first_name', 'shipping_last_name'].includes(field.name)) {
					return true;
				}
				return false;
			});
			
			if (wooForm.length > 0) {
				window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(wooForm));
			}
		});
		
		// Clear storage when order is placed successfully
		$form.on('checkout_place_order_success', function() {
			window.sessionStorage.removeItem(STORAGE_KEY);
		});
		
		// Also clear on order received page
		if ($('body').hasClass('woocommerce-order-received')) {
			window.sessionStorage.removeItem(STORAGE_KEY);
		}
	}

	/**
	 * Public method to manually save form
	 * Called by store-selector.js before redirecting to CVS selection
	 */
	checkoutForm.saveForm = function() {
		const $form = $('form.woocommerce-checkout');
		if ($form.length === 0) {
			return;
		}
		
		let wooForm = $form.serializeArray().filter(function(field) {
			// Filter out ignored fields
			if (ignoreFields.indexOf(field.name) !== -1) {
				return false;
			}
			// Filter out WordPress internal fields
			if (field.name.substring(0, 1) === '_') {
				return false;
			}
			// Keep all non-empty values and also keep empty values for important fields
			if (field.value !== '') {
				return true;
			}
			// Keep empty values for important fields that might be intentionally cleared
			if (['billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone', 'shipping_first_name', 'shipping_last_name'].includes(field.name)) {
				return true;
			}
			return false;
		});
		
		window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(wooForm));
	}

	// Initialize when document is ready
	$(document).ready(function() {
		// Clear the CVS selection flag on page load
		window.moksafowoPayuniSelectingStore = false;
		
		// Check if we have POST data from CVS return (store selector sets these)
		const hasStoreData = $('#moksafowo_payuni_selected_store_id').length > 0 || 
							 $('input[name="moksafowo_payuni_selected_store_id"]').length > 0;
		
		if (hasStoreData) {
			// 減少延遲時間，讓 WooCommerce 有時間完成渲染
			setTimeout(checkoutForm.init, 400);
		} else {
			// Normal initialization - 減少延遲時間
			setTimeout(checkoutForm.init, 50);
		}
	});
	
	// Expose public methods for integration with store-selector.js
	window.moksafowoPayuniCheckoutForm = {
		saveForm: checkoutForm.saveForm,
		clearStorage: function() {
			window.sessionStorage.removeItem(STORAGE_KEY);
		}
	};

})(window, document, jQuery);