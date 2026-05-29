(function($) {
    'use strict';

    const PayuniStoreSelector = {
        // Configuration
        config: {
            debug: false, // Set to true for debugging
            namespace: 'payuni_store',
            cvsMethodPrefix: 'mo_payuni_shipping_711',
            checkDelay: 200 // Single delay for initialization check
        },

        // State management
        state: {
            initialized: false,
            currentMethod: null,
            isProcessing: false
        },

        // Cached jQuery elements
        cache: {},

        /**
         * Initialize the store selector
         */
        init: function() {
            // Prevent multiple initializations
            if (this.state.initialized) {
                return;
            }

            // Setup default config if not provided
            if (typeof payuni_store_selector === 'undefined') {
                window.payuni_store_selector = {
                    ajax_url: '/wp-admin/admin-ajax.php',
                    nonce: '',
                    stored_store_data: null,
                    hide_billing_address_fields: false,
                    labels: {
                        select_store: '選擇門市',
                        change_store: '更換門市', 
                        no_store_selected: '尚未選擇門市',
                        open_map: '選擇門市',
                        loading: '跳轉中...',
                        error: '載入失敗，請稍後再試'
                    }
                };
            }

            this.cacheElements();
            this.bindEvents();
            
            // Single delayed initialization check
            setTimeout(() => {
                this.initializeStoreData();
                this.checkShippingMethod();
                // Update label on initial load
                const selected = this.getSelectedShippingMethod();
                this.updateShipToDifferentLabel(selected);
            }, this.config.checkDelay);

            this.state.initialized = true;
            this.log('Store Selector initialized');
        },

        /**
         * Cache frequently used jQuery elements
         */
        cacheElements: function() {
            this.cache = {
                $body: $(document.body),
                $checkoutForm: $('form.checkout, form#order_review'),
                $shippingFields: $('.woocommerce-shipping-fields'),
                $shippingWrapper: $('.woocommerce-shipping-fields__field-wrapper')
            };
        },

        /**
         * Get the selected shipping method (handles both radio button and single method cases)
         */
        getSelectedShippingMethod: function() {
            let selected = $('input[name="shipping_method[0]"]:checked').val();
            
            // Handle case when only one shipping method exists (no radio button, or hidden input)
            if (!selected) {
                selected = $('input[name="shipping_method[0]"]').val();
                this.log('Single shipping method detected:', selected);
            }
            
            this.log('Selected shipping method:', selected);
            return selected;
        },

        /**
         * Bind all events with proper namespacing
         */
        bindEvents: function() {
            const ns = this.config.namespace;

            // Shipping method change
            $(document).on(`change.${ns}`, 'input[name="shipping_method[0]"]', () => {
                this.handleShippingMethodChange();
            });

            // Store map button click
            $(document).on(`click.${ns}`, '.payuni-store-map-btn', (e) => {
                e.preventDefault();
                this.openStoreMap();
            });

            // Checkout update event
            this.cache.$body.on(`updated_checkout.${ns}`, (event, data) => {
                this.handleCheckoutUpdate(data);
            });

            // Ship to different address checkbox
            $(document).on(`change.${ns}`, '#ship-to-different-address-checkbox', () => {
                this.updateFieldVisibility();
            });
        },

        /**
         * Initialize store data from various sources
         */
        initializeStoreData: function() {
            // Clear the CVS selection flag when returning from store selection
            window.payuniSelectingStore = false;
            
            // Priority 1: Check for WC Session data via PHP
            if (mo_payuni_store_selector.stored_store_data) {
                this.log('Found stored data from WC Session');
                this.displayStore(mo_payuni_store_selector.stored_store_data);
                return;
            }

            // Priority 2: Check for POST data from store map return
            const postData = this.getPostData();
            if (postData) {
                this.log('Found POST data from store map');
                this.displayStore(postData);
                this.cleanupPostData();
                return;
            }

            // Priority 3: Check hidden fields
            const hiddenData = this.getHiddenFieldData();
            if (hiddenData) {
                this.log('Found data in hidden fields');
                this.displayStore(hiddenData);
            }
        },

        /**
         * Get POST data from store map return
         */
        getPostData: function() {
            const $tempId = $('input[name="payuni_selected_store_id"]:not(#payuni_selected_store_id)');
            const $tempName = $('input[name="payuni_selected_store_name"]');
            const $tempAddress = $('input[name="payuni_selected_store_address"]');

            if ($tempId.length && $tempName.length && $tempAddress.length) {
                return {
                    id: $tempId.val(),
                    name: $tempName.val(),
                    address: $tempAddress.val()
                };
            }

            return null;
        },

        /**
         * Get data from hidden fields
         */
        getHiddenFieldData: function() {
            const dataStr = $('#payuni_selected_store_data').val();
            if (dataStr) {
                try {
                    return JSON.parse(dataStr);
                } catch (e) {
                    this.log('Error parsing hidden field data', e);
                }
            }
            return null;
        },

        /**
         * Clean up temporary POST data fields
         */
        cleanupPostData: function() {
            $('input[name="payuni_selected_store_id"]:not(#payuni_selected_store_id)').remove();
            $('input[name="payuni_selected_store_name"]').remove();
            $('input[name="payuni_selected_store_address"]').remove();
            $('input[name="payuni_selected_store_telephone"]').remove();
            $('input[name="payuni_selected_store_outside"]').remove();
            $('input[name="payuni_selected_store_ship"]').remove();
            $('input[name="payuni_selected_store_data"]:not(#payuni_selected_store_data)').remove();
        },

        /**
         * Handle shipping method change
         */
        handleShippingMethodChange: function() {
            const selected = this.getSelectedShippingMethod();
            
            if (selected !== this.state.currentMethod) {
                this.state.currentMethod = selected;
                this.checkShippingMethod();
                this.updateShipToDifferentLabel(selected);
            }
        },

        /**
         * Update the "Ship to different address?" label dynamically
         * This is more performant than using PHP gettext filter
         */
        updateShipToDifferentLabel: function(shippingMethod) {
            // WooCommerce structure: <label><input><span>Text</span></label>
            const $labelSpan = $('h3#ship-to-different-address span');
            
            if ($labelSpan.length) {
                // Store original text if not already stored
                if (!$labelSpan.data('original-text')) {
                    $labelSpan.data('original-text', $labelSpan.text());
                }
                
                // Check if PayUni 7-11 shipping is selected
                if (shippingMethod && shippingMethod.indexOf(this.config.cvsMethodPrefix) !== -1) {
                    // Update label text for CVS shipping
                    $labelSpan.text('不同的收件人資訊？');
                } else {
                    // Restore original label for other shipping methods
                    const originalText = $labelSpan.data('original-text') || '運送到不同的地址？';
                    $labelSpan.text(originalText);
                }
            }
        },

        /**
         * Check current shipping method and update UI
         */
        checkShippingMethod: function() {
            const selected = this.getSelectedShippingMethod();
            
            // If no shipping method found, check if store selector row is visible (PHP already rendered it)
            if (!selected && $('.payuni-store-selector-row').length > 0) {
                // PHP has already determined this is a CVS method, show the selector
                this.showStoreSelector();
                this.updateFieldVisibility();
                return;
            }
            
            const methodId = selected ? selected.split(':')[0] : '';
            
            // Check if it's a PAYUNi CVS method
            const isCVS = methodId && methodId.indexOf(this.config.cvsMethodPrefix) !== -1;
            
            // Check for other CVS plugins to avoid conflicts
            const hasOtherCVS = this.checkOtherCVSPlugins(methodId);
            
            // If other CVS plugin is detected, do nothing - let that plugin handle everything
            if (hasOtherCVS) {
                this.hideStoreSelector(); // Hide PayUni store selector only
                return; // Exit early, don't touch any fields
            }
            
            if (isCVS) {
                this.showStoreSelector();
                this.updateFieldVisibility();
            } else {
                this.hideStoreSelector();
                this.restoreFieldVisibility();
            }
        },

        /**
         * Check for other CVS plugins to avoid conflicts
         */
        checkOtherCVSPlugins: function(methodId) {
            // Check for common CVS shipping plugins
            const otherCVSPrefixes = [
                'ry_ecpay_shipping',
                'ry_newebpay_shipping',
                'ry_smilepay_shipping',
            ];

            return otherCVSPrefixes.some(prefix => methodId && methodId.indexOf(prefix) !== -1);
        },

        /**
         * Handle checkout update event
         */
        handleCheckoutUpdate: function(data) {
            // Re-check shipping method after update
            this.checkShippingMethod();
            
            // Update ship to different label based on current method
            const selected = this.getSelectedShippingMethod();
            this.updateShipToDifferentLabel(selected);

            // Check for store data in fragments
            if (data && data.fragments && data.fragments.payuni_stored_data) {
                const methodId = selected ? selected.split(':')[0] : '';
                
                if (methodId && methodId.indexOf(this.config.cvsMethodPrefix) !== -1) {
                    this.displayStore(data.fragments.payuni_stored_data);
                }
            }

            // Update field visibility after checkout update
            // Small delay to ensure WooCommerce has finished updating the DOM
            setTimeout(() => {
                this.updateFieldVisibility();
            }, 100);
        },

        /**
         * Show store selector UI
         */
        showStoreSelector: function() {
            this.ensureHiddenFields();
            this.checkExistingStoreData();
            
            // The actual UI is rendered by PHP, we just ensure it's visible
            $('.payuni-store-selector').show();
            $('.payuni-store-selector-row').show();
            
            this.log('Store selector shown');
        },

        /**
         * Hide store selector UI
         */
        hideStoreSelector: function() {
            $('.payuni-store-selector').hide();
            $('.payuni-store-selector-row').hide();
        },

        /**
         * Ensure hidden fields exist in the form
         */
        ensureHiddenFields: function() {
            const $form = this.cache.$checkoutForm;
            
            if ($form.length === 0) {
                this.log('Warning: Checkout form not found');
                return;
            }

            // Create hidden fields if they don't exist
            if ($('#payuni_selected_store_id').length === 0) {
                $form.append('<input type="hidden" name="payuni_selected_store_id" id="payuni_selected_store_id" value="">');
            }
            
            if ($('#payuni_selected_store_data').length === 0) {
                $form.append('<input type="hidden" name="payuni_selected_store_data" id="payuni_selected_store_data" value="">');
            }
        },

        /**
         * Check for existing store data in hidden fields
         */
        checkExistingStoreData: function() {
            const data = this.getHiddenFieldData();
            if (data) {
                this.displayStore(data);
                return true;
            }
            return false;
        },

        /**
         * Open store map for selection
         */
        openStoreMap: function() {
            // Prevent multiple requests
            if (this.state.isProcessing) {
                return;
            }

            this.state.isProcessing = true;
            const $btn = $('.payuni-store-map-btn');
            $btn.prop('disabled', true).text(mo_payuni_store_selector.labels.loading);
            
            // Save form data before redirecting to CVS selection
            // Set flag to prevent auto-save during CVS selection
            window.payuniSelectingStore = true;
            
            // If save-fields.js is loaded, use its saveForm method
            if (typeof window.PayuniCheckoutForm !== 'undefined' && window.PayuniCheckoutForm.saveForm) {
                window.PayuniCheckoutForm.saveForm();
                this.log('Form data saved before CVS selection');
            }

            // Get shipping method, handling both radio button and single method cases
            const shippingMethod = this.getSelectedShippingMethod();

            $.ajax({
                url: mo_payuni_store_selector.ajax_url,
                type: 'POST',
                data: {
                    action: 'payuni_open_store_map',
                    nonce: mo_payuni_store_selector.nonce,
                    shipping_method: shippingMethod
                },
                success: (response) => {
                    if (response.success && response.data.form_data) {
                        this.submitMapForm(response.data);
                    } else {
                        this.handleMapError(response.data ? response.data.message : null);
                    }
                },
                error: () => {
                    this.handleMapError();
                },
                complete: () => {
                    this.state.isProcessing = false;
                }
            });
        },

        /**
         * Submit form to open map
         */
        submitMapForm: function(data) {
            const $form = $('<form>', {
                method: 'POST',
                action: data.api_url,
                id: 'payuni-store-map-form'
            });

            $.each(data.form_data, (key, value) => {
                $form.append($('<input>', {
                    type: 'hidden',
                    name: key,
                    value: value
                }));
            });

            $('body').append($form);
            $form.submit();
        },

        /**
         * Handle map error
         */
        handleMapError: function(message) {
            alert(message || mo_payuni_store_selector.labels.error);
            $('.payuni-store-map-btn')
                .prop('disabled', false)
                .text(mo_payuni_store_selector.labels.open_map);
        },

        /**
         * Display selected store information
         */
        displayStore: function(storeData) {
            if (!storeData || !storeData.id || !storeData.name) {
                this.log('Invalid store data');
                return;
            }

            // Ensure hidden fields exist
            this.ensureHiddenFields();

            // Save to hidden fields
            $('#payuni_selected_store_id').val(storeData.id);
            $('#payuni_selected_store_data').val(JSON.stringify(storeData));

            // Update UI
            $('.payuni-selected-store .store-name').text(storeData.name);
            $('.payuni-selected-store .store-address').text(storeData.address);
            $('.payuni-selected-store .store-id').text('門市代號: ' + storeData.id);

            // Show selected store, hide no-store message
            $('.payuni-no-store').hide();
            $('.payuni-selected-store').show();
        },

        /**
         * Clear store data
         */
        clearStoreData: function() {
            // Clear hidden fields
            $('#payuni_selected_store_id').val('');
            $('#payuni_selected_store_data').val('');

            // Reset UI
            $('.payuni-selected-store').hide();
            $('.payuni-no-store').show();

            // Clear from server session
            if (mo_payuni_store_selector.ajax_url) {
                $.post(mo_payuni_store_selector.ajax_url, {
                    action: 'payuni_clear_store_data',
                    nonce: mo_payuni_store_selector.nonce
                });
            }
        },

        /**
         * Update field visibility based on shipping method
         */
        updateFieldVisibility: function() {
            const selected = this.getSelectedShippingMethod();
            const methodId = selected ? selected.split(':')[0] : '';
            const isCVS = methodId && methodId.indexOf(this.config.cvsMethodPrefix) !== -1;
            const hasOtherCVS = this.checkOtherCVSPlugins(methodId);
            const isShipToDifferent = $('#ship-to-different-address-checkbox').is(':checked');

            // Skip field management if other CVS plugin is detected
            if (hasOtherCVS) {
                return;
            }

            // Only hide fields if BOTH CVS is selected AND shipping to different address
            if (isCVS && isShipToDifferent) {
                // Hide address fields except name and phone for CVS
                this.hideAddressFields();
                this.updateCheckboxLabel('不同的收件人資訊？');
            } else {
                // Show all fields for regular shipping or when not shipping to different address
                this.showAddressFields();
                
                // Only update checkbox label if CVS is selected
                if (isCVS) {
                    this.updateCheckboxLabel('不同的收件人資訊？');
                } else {
                    this.restoreCheckboxLabel();
                }
            }

            // Handle billing address fields based on setting
            if (mo_payuni_store_selector.hide_billing_address_fields) {
                if (isCVS) {
                    this.hideBillingAddressFields();
                } else {
                    this.showBillingAddressFields();
                }
            }
        },

        /**
         * Hide address fields for CVS shipping (only when shipping to different address)
         */
        hideAddressFields: function() {
            // Ensure shipping section is visible
            this.cache.$shippingFields.show();
            
            // Method 1: Hide using the CSS class we added in PHP
            $('.payuni-cvs-hide').hide();
            
            // Method 2: Also hide fields directly for immediate effect
            $('#shipping_country_field').hide();
            $('#shipping_postcode_field').hide();
            $('#shipping_state_field').hide();
            $('#shipping_city_field').hide();
            $('#shipping_address_1_field').hide();
            $('#shipping_address_2_field').hide();
            $('#shipping_company_field').hide();
            
            // Ensure name and phone fields are visible
            $('#shipping_first_name_field').show().removeClass('payuni-cvs-hide');
            $('#shipping_last_name_field').show().removeClass('payuni-cvs-hide');
            $('#shipping_phone_field').show().removeClass('payuni-cvs-hide');
        },

        /**
         * Show all address fields
         */
        showAddressFields: function() {
            // Only manipulate if shipping fields section exists
            if (this.cache.$shippingFields.length) {
                this.cache.$shippingFields.show();
                
                // Remove the payuni-cvs-hide class and inline styles from all fields
                // This is needed because .payuni-cvs-hide has !important in CSS
                const fieldsToShow = [
                    '#shipping_country_field',
                    '#shipping_postcode_field', 
                    '#shipping_state_field',
                    '#shipping_city_field',
                    '#shipping_address_1_field',
                    '#shipping_address_2_field',
                    '#shipping_company_field',
                    '#shipping_first_name_field',
                    '#shipping_last_name_field',
                    '#shipping_phone_field'
                ];
                
                fieldsToShow.forEach(field => {
                    $(field).removeClass('payuni-cvs-hide').removeAttr('style').show();
                });
                
                // Also ensure all shipping fields are visible and remove classes
                this.cache.$shippingWrapper.find('p, .form-row').each(function() {
                    $(this).removeClass('payuni-cvs-hide').removeAttr('style');
                });
            }
        },

        /**
         * Restore normal field visibility
         */
        restoreFieldVisibility: function() {
            const selected = this.getSelectedShippingMethod();
            const methodId = selected ? selected.split(':')[0] : '';
            const hasOtherCVS = this.checkOtherCVSPlugins(methodId);
            
            // Skip field management if other CVS plugin is detected
            if (hasOtherCVS) {
                return;
            }
            
            this.showAddressFields();
            this.restoreCheckboxLabel();
            
            // Also restore billing address fields if the setting was enabled
            if (mo_payuni_store_selector.hide_billing_address_fields) {
                this.showBillingAddressFields();
            }
        },

        /**
         * Update checkbox label for CVS
         */
        updateCheckboxLabel: function(text) {
            const $label = $('label[for="ship-to-different-address-checkbox"]');
            const $target = $label.find('span').length ? $label.find('span') : $label;
            
            if ($target.length) {
                if (!$target.data('original-text')) {
                    $target.data('original-text', $target.text());
                }
                $target.text(text);
            }
        },

        /**
         * Restore original checkbox label
         */
        restoreCheckboxLabel: function() {
            const $label = $('label[for="ship-to-different-address-checkbox"]');
            const $target = $label.find('span').length ? $label.find('span') : $label;
            
            if ($target.length && $target.data('original-text')) {
                $target.text($target.data('original-text'));
            }
        },

        /**
         * Hide billing address fields for CVS shipping
         */
        hideBillingAddressFields: function() {
            // Hide billing address fields (but keep name, email, phone)
            const fieldsToHide = [
                '#billing_country_field',
                '#billing_postcode_field',
                '#billing_state_field',
                '#billing_city_field',
                '#billing_address_1_field',
                '#billing_address_2_field',
                '#billing_company_field'
            ];
            
            fieldsToHide.forEach(field => {
                const $field = $(field);
                const $input = $field.find('input, select');
                
                // Store original required state
                if (!$input.data('payuni-original-required')) {
                    $input.data('payuni-original-required', $input.prop('required'));
                }
                
                // Remove required attribute and validation
                $input.prop('required', false);
                $field.removeClass('validate-required');
                $field.find('abbr.required').hide();
                
                // Hide the field. Don't fill placeholder values — PHP filter
                // modify_billing_fields_for_cvs() already removes the required
                // attribute so empty fields pass server-side validation. Filling
                // 'N/A' just bleeds back when user later switches to home delivery.
                $field.addClass('payuni-cvs-hide-billing').hide();
            });
            
            this.log('Billing address fields hidden for CVS shipping');
        },

        /**
         * Show billing address fields
         */
        showBillingAddressFields: function() {
            const fieldsToShow = [
                '#billing_country_field',
                '#billing_postcode_field',
                '#billing_state_field',
                '#billing_city_field',
                '#billing_address_1_field',
                '#billing_address_2_field',
                '#billing_company_field'
            ];
            
            fieldsToShow.forEach(field => {
                const $field = $(field);
                const $input = $field.find('input, select');

                const originalRequired = $input.data('payuni-original-required');
                if (originalRequired !== undefined) {
                    $input.prop('required', originalRequired);
                    if (originalRequired) {
                        $field.addClass('validate-required');
                    }
                }

                $field.find('abbr.required').show();

                // Belt-and-suspenders: clear any 'N/A' from older plugin versions
                // that may have left it in DOM before this version's hide stopped
                // injecting it.
                if ($input.val() === 'N/A') {
                    $input.val('');
                }

                $field.removeClass('payuni-cvs-hide-billing').removeAttr('style').show();
            });
            
            this.log('Billing address fields shown');
        },

        /**
         * Logging utility
         */
        log: function(...args) {
            if (this.config.debug) {
                console.log('[PAYUNi Store]', ...args);
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        PayuniStoreSelector.init();
    });

    // Expose for debugging if needed
    window.PayuniStoreSelector = PayuniStoreSelector;

})(jQuery);
