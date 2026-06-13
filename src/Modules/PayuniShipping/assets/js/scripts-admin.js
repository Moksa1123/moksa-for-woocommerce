(function( $ ) {
	'use strict';

	$(document).on('click', '.edit_address', function () {

		if ($('#_shipping_payuni_storeid').length) {
			$('a.load_customer_shipping').remove();
			$('a.billing-same-as-shipping').remove();

			$('._shipping_company_field').hide();
			$('._shipping_address_1_field').hide();
			$('._shipping_address_2_field').hide();
			$('._shipping_city_field').hide();
			$('._shipping_postcode_field').hide();
			$('._shipping_country_field').hide();
			$('._shipping_state_field').hide();
		}
	});

	// 列印 / 下載標籤 — 開新分頁送 PAYUNi（物流資訊卡片化後從這裡委派，原在 metabox inline JS）。
	$(document).on('click', '.print-label', function(){
		var newTab = window.open(ajaxurl + '?' + $.param({
			action: $(this).data('action'),
			orderids: $(this).data('id'),
			service: $(this).data('service')
		}), '_blank');
		setTimeout(function(){ if (newTab) { newTab.location.reload(); } }, 5000);
	});

	$(document).on('click', '.update-delivery-status', function(event){
		event.preventDefault();
		var post_id = $(this).data('id');
		$.ajax({
			url: moksafowo_payuni_shipping.ajax_url,
			data: {
				action: 'moksafowo_payuni_shipping_delivery_status',
				post_id: post_id,
				security: moksafowo_payuni_shipping.security,
			},
			dataType: "json",
			type: 'post',
			success: function (data) {
				console.log(data);
				if (data.success) {
					window.location.reload();
				} else {
					alert(moksafowo_payuni_shipping.translations.shipping_status_update_failed + ' ' + data.result);
				}

				window.location.reload();

			},
			always: function () {}
		});

	});

	$(document).on('click', '.cancel-shipping', function (event) {

		event.preventDefault();
		var post_id = $(this).data('id');
		$.ajax({
			url: moksafowo_payuni_shipping.ajax_url,
			data: {
				action: 'cancel_shipping_order',
				post_id: post_id,
				security: moksafowo_payuni_shipping.security,
			},
			dataType: "json",
			type: 'post',
			success: function ( data ) {
				console.log(data);
				if (data.success) {

				} else {
					alert( moksafowo_payuni_shipping.translations.cancel_shipping_failed + ' ' + data.result);
				}
				window.location.reload();

			},
			always: function () {}
		});

	});

	$(document).on('change', '#package-spec-select', function(event){
		event.preventDefault();
		var $select = $(this);
		var $cell = $select.closest('td');
		var order_id = $select.data('order-id');
		var package_spec = $select.val();
		var original_value = $select.data('original-value') || $select.find('option:selected').val();
		
		// Store original value if not already stored
		if (!$select.data('original-value')) {
			$select.data('original-value', original_value);
		}
		
		// Add loading indicator
		$select.prop('disabled', true);
		$cell.append('<span class="package-spec-loading" style="margin-left: 8px; color: #666;"><span class="spinner" style="visibility: visible; float: none; width: 16px; height: 16px; margin: 0;"></span> 更新中...</span>');
		
		$.ajax({
			url: moksafowo_payuni_shipping.ajax_url,
			data: {
				action: 'moksafowo_payuni_shipping_update_package_spec',
				order_id: order_id,
				package_spec: package_spec,
				security: moksafowo_payuni_shipping.security,
			},
			dataType: "json",
			type: 'post',
			success: function (data) {
				console.log(data);
				if (data.success) {
					// Update original value to new value
					$select.data('original-value', package_spec);

					// Prepend the new order note into the notes panel (WC-native pattern) — no reload needed.
					if (data.note_html) {
						var $notes = $('ul.order_notes');
						if ($notes.length) {
							$notes.find('li.no-items').remove();
							$notes.prepend(data.note_html);
						}
					}

					// Show success indicator briefly
					$('.package-spec-loading').html('<span style="color: #46b450;">✓ 更新成功</span>');
					setTimeout(function() {
						$('.package-spec-loading').fadeOut(300, function() {
							$(this).remove();
						});
					}, 1500);
				} else {
					// Show error and revert selection
					$('.package-spec-loading').html('<span style="color: #dc3232;">✗ 更新失敗</span>');
					$select.val(original_value); // Revert to original value
					
					// Show error message
					var error_msg = data.result || 'Unknown error';
					setTimeout(function() {
						$('.package-spec-loading').html('<span style="color: #dc3232;" title="' + error_msg + '">更新失敗</span>');
						setTimeout(function() {
							$('.package-spec-loading').fadeOut(300, function() {
								$(this).remove();
							});
						}, 2000);
					}, 1000);
				}
			},
			error: function() {
				// Show error and revert selection
				$('.package-spec-loading').html('<span style="color: #dc3232;">✗ 連線失敗</span>');
				$select.val(original_value); // Revert to original value
				
				setTimeout(function() {
					$('.package-spec-loading').fadeOut(300, function() {
						$(this).remove();
					});
				}, 2000);
			},
			complete: function () {
				$select.prop('disabled', false);
			}
		});
	});

	// Batch print functionality
	$(document).ready(function() {
		// Check if batch print localization exists
		if (typeof moksafowo_payuni_batch_print === 'undefined') {
			return;
		}
		
		var $modal = $('#moksafowo-payuni-batch-print-modal');
		var currentShipType = null;
		var selectedOrdersForPrint = [];
		var allOrdersData = []; // Store all orders for filtering
		var filteredOrdersData = []; // Store filtered orders
		
		// Handle batch print button clicks - show modal
		$(document).on('click', '.moksafowo-payuni-batch-print-btn', function(e) {
			e.preventDefault();
			
			var $button = $(this);
			currentShipType = $button.data('ship-type');
			var $form = $('#posts-filter, #wc-orders-filter').first();
			var selectedOrders = [];
			
			// Get selected order IDs
			$form.find('input[name="post[]"]:checked, input[name="id[]"]:checked').each(function() {
				selectedOrders.push($(this).val());
			});
			
			// Setup modal
			console.log('Current ship type:', currentShipType, 'Type:', typeof currentShipType); // Debug log
			// Determine ship type name based on the value
			var shipTypeName;
			if (currentShipType == '2' || currentShipType == 'TCAT') {
				shipTypeName = '黑貓宅配';
			} else if (currentShipType == '1' || currentShipType == 'SEVEN') {
				shipTypeName = '7-ELEVEN 超商';
			} else {
				// Fallback - if the value is unexpected
				shipTypeName = currentShipType;
			}
			$modal.find('.moksafowo-payuni-modal-title').text('批次列印 ' + shipTypeName + ' 標籤');
			
			// Reset modal state
			$modal.find('.moksafowo-payuni-orders-loading').show();
			$modal.find('.moksafowo-payuni-orders-list').hide();
			$modal.find('.moksafowo-payuni-no-orders').hide();
			$modal.find('.moksafowo-payuni-print-confirm').prop('disabled', true);
			$modal.find('.selected-count').text('0');
			selectedOrdersForPrint = [];
			
			// Set ship type on modal for conditional styling
			$modal.attr('data-ship-type', currentShipType);
			
			// Show modal
			$modal.fadeIn(200);
			
			// Load orders
			$.ajax({
				url: moksafowo_payuni_batch_print.ajax_url,
				type: 'POST',
				data: {
					action: 'moksafowo_payuni_get_unprinted_orders',
					security: moksafowo_payuni_batch_print.nonce,
					ship_type: currentShipType,
					selected_ids: selectedOrders
				},
				success: function(response) {
					if (response.success) {
						var data = response.data;
						$modal.find('.moksafowo-payuni-orders-loading').hide();
						
							if (data.orders.length > 0) {
							// Store all orders for filtering
							allOrdersData = data.orders;
							filteredOrdersData = data.orders;
							
							// Update description
							var description = data.ship_type_name + ' 訂單（最多顯示 50 筆）';
							$modal.find('.moksafowo-payuni-orders-info .description').text(description);
							
							// Show filters for TCat orders only
							if (currentShipType == '2' || currentShipType == 2) {
								$modal.find('.moksafowo-payuni-tcat-filters').show();
								
								// Update package spec options based on temperature selection
								updatePackageSpecOptions();
							} else {
								$modal.find('.moksafowo-payuni-tcat-filters').hide();
							}
							
							// Build table headers based on ship type
							var thead = $modal.find('.moksafowo-payuni-table-header');
							thead.empty();
							
							var headerRow;
							if (currentShipType == '2' || currentShipType == 2) {
								// TCat orders - include package spec column
								headerRow = '<tr>' +
									'<th style="width: 40px;"><input type="checkbox" class="moksafowo-payuni-select-all" /></th>' +
									'<th>訂單編號</th>' +
									'<th>收件人</th>' +
									'<th>運送方式</th>' +
									'<th>狀態</th>' +
									'<th>包裹規格</th>' +
									'<th>物流查詢編號</th>' +
									'</tr>';
							} else {
								// CVS orders - no package spec column
								headerRow = '<tr>' +
									'<th style="width: 40px;"><input type="checkbox" class="moksafowo-payuni-select-all" /></th>' +
									'<th>訂單編號</th>' +
									'<th>收件人</th>' +
									'<th>運送方式</th>' +
									'<th>狀態</th>' +
									'<th>物流查詢編號</th>' +
									'</tr>';
							}
							thead.append(headerRow);
							
							// Render filtered orders
							renderFilteredOrders();
							
							// Auto-select all printable orders
							$modal.find('.moksafowo-payuni-order-checkbox:not(:disabled)').prop('checked', true);
							
							// Also check the "select all" checkbox
							var total = $modal.find('.moksafowo-payuni-order-checkbox:not(:disabled)').length;
							var checked = $modal.find('.moksafowo-payuni-order-checkbox:checked').length;
							$modal.find('.moksafowo-payuni-select-all').prop('checked', checked === total && total > 0);
							
							updateSelectedCount();
							
								$modal.find('.moksafowo-payuni-orders-list').show();
						} else {
								// Show empty message when no printable orders are available
								var emptyMsg = '沒有可列印的 ' + (data.ship_type_name || '') + ' 訂單';
								$modal.find('.moksafowo-payuni-orders-info .description').text(emptyMsg);
								$modal.find('.moksafowo-payuni-orders-list').show();
								$modal.find('.moksafowo-payuni-no-orders').show();
								$modal.find('.moksafowo-payuni-print-confirm').prop('disabled', true);
								$modal.find('.selected-count').text('0');
						}
					} else {
						alert(response.data);
						$modal.fadeOut();
					}
				},
				error: function() {
					alert('載入訂單失敗，請稍後再試');
					$modal.fadeOut();
				}
			});
		});
		
		// Handle package spec display click - convert to dropdown
		$(document).on('click', '.moksafowo-payuni-package-spec-display', function(event) {
			event.preventDefault();
			event.stopPropagation();
			
			var $display = $(this);
			var orderId = $display.data('order-id');
			var currentValue = $display.data('value');
			var goodsType = $display.data('goods-type');
			
			// Build options based on goods type
			var options = '';
			if (goodsType === '2' || goodsType === '3') {
				// Frozen or Refrigerated - no 150cm option
				options = '<option value="1"' + (currentValue == '1' ? ' selected' : '') + '>60cm</option>' +
						  '<option value="2"' + (currentValue == '2' ? ' selected' : '') + '>90cm</option>' +
						  '<option value="3"' + (currentValue == '3' ? ' selected' : '') + '>120cm</option>';
			} else {
				// Normal - includes 150cm option
				options = '<option value="1"' + (currentValue == '1' ? ' selected' : '') + '>60cm</option>' +
						  '<option value="2"' + (currentValue == '2' ? ' selected' : '') + '>90cm</option>' +
						  '<option value="3"' + (currentValue == '3' ? ' selected' : '') + '>120cm</option>' +
						  '<option value="4"' + (currentValue == '4' ? ' selected' : '') + '>150cm</option>';
			}
			
			// Create select element
			var $select = $('<select class="moksafowo-payuni-package-spec-select" ' +
				'data-order-id="' + orderId + '" ' +
				'data-original-value="' + currentValue + '" ' +
				'data-goods-type="' + goodsType + '" ' +
				'style="width: 70px; font-size: 13px; padding: 2px 4px;">' +
				options +
				'</select>');
			
			// Replace display with select
			$display.replaceWith($select);
			$select.focus();
			
			// Flag to prevent duplicate updates
			var isUpdating = false;
			
			// Handle change event (primary handler)
			$select.on('change', function() {
				if (!isUpdating) {
					isUpdating = true;
					var selectedValue = $select.val();
					var selectedText = $select.find('option:selected').text();
					updatePackageSpec(orderId, selectedValue, selectedText, $select);
				}
			});
			
			// Handle select blur - only convert back if not updating
			$select.on('blur', function() {
				// Small delay to allow change event to fire first
				setTimeout(function() {
					if (!isUpdating) {
						var selectedValue = $select.val();
						var selectedText = $select.find('option:selected').text();
						
						// Only update if value changed
						if (selectedValue !== currentValue) {
							isUpdating = true;
							updatePackageSpec(orderId, selectedValue, selectedText, $select);
						} else {
							// Convert back to display without saving
							convertSelectToDisplay($select, selectedText, selectedValue, goodsType);
						}
					}
				}, 100);
			});
		});
		
		// Function to update package spec via AJAX
		function updatePackageSpec(orderId, packageSpec, displayText, $element) {
			var $cell = $element.closest('td');
			var goodsType = $element.data('goods-type');
			
			// Remove any existing loading indicators first
			$cell.find('.package-spec-loading').remove();
			
			// Add loading indicator
			$element.prop('disabled', true);
			$cell.append('<span class="package-spec-loading" style="margin-left: 4px; color: #666;">' +
				'<span class="spinner" style="visibility: visible; float: none; width: 14px; height: 14px; margin: 0;"></span>' +
				'</span>');
			
			// Make AJAX request
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'moksafowo_payuni_shipping_update_package_spec',
					security: moksafowo_payuni_batch_print.package_spec_nonce || moksafowo_payuni_shipping_order.nonce,
					order_id: orderId,
					package_spec: packageSpec
				},
				success: function(response) {
					var $loading = $cell.find('.package-spec-loading');
					
					if (response.success) {
						// Show success indicator
						$loading.html('<span style="color: #46b450;">✓</span>');
						
						// Convert back to display with new value
						setTimeout(function() {
							$loading.remove();
							if ($element.parent().length) { // Check if element still exists
								convertSelectToDisplay($element, displayText, packageSpec, goodsType);
							}
						}, 800);
					} else {
						// Show error and revert
						$loading.html('<span style="color: #dc3232;">✗</span>');
						var originalValue = $element.data('original-value');
						var originalText = $element.find('option[value="' + originalValue + '"]').text();
						
						setTimeout(function() {
							$loading.remove();
							if ($element.parent().length) { // Check if element still exists
								convertSelectToDisplay($element, originalText, originalValue, goodsType);
							}
						}, 1500);
					}
				},
				error: function() {
					var $loading = $cell.find('.package-spec-loading');
					
					// Show error and revert
					$loading.html('<span style="color: #dc3232;">✗</span>');
					var originalValue = $element.data('original-value');
					var originalText = $element.find('option[value="' + originalValue + '"]').text();
					
					setTimeout(function() {
						$loading.remove();
						if ($element.parent().length) { // Check if element still exists
							convertSelectToDisplay($element, originalText, originalValue, goodsType);
						}
					}, 1500);
				}
			});
		}
		
		// Function to convert select back to display
		function convertSelectToDisplay($select, displayText, value, goodsType) {
			var $display = $('<span class="moksafowo-payuni-package-spec-display" ' +
				'data-order-id="' + $select.data('order-id') + '" ' +
				'data-value="' + value + '" ' +
				'data-goods-type="' + goodsType + '" ' +
				'style="color: #2271b1; cursor: pointer; text-decoration: none;">' +
				displayText +
				'</span>');
			
			$select.replaceWith($display);
		}
		
		// Handle select all checkbox
		$(document).on('change', '.moksafowo-payuni-select-all', function() {
			var isChecked = $(this).prop('checked');
			$modal.find('.moksafowo-payuni-order-checkbox:not(:disabled)').prop('checked', isChecked);
			updateSelectedCount();
		});
		
		// Handle individual checkbox
		$(document).on('change', '.moksafowo-payuni-order-checkbox', function() {
			updateSelectedCount();
			
			// Update select all checkbox
			var total = $modal.find('.moksafowo-payuni-order-checkbox:not(:disabled)').length;
			var checked = $modal.find('.moksafowo-payuni-order-checkbox:checked').length;
			$modal.find('.moksafowo-payuni-select-all').prop('checked', checked === total && total > 0);
		});
		
		// Update selected count
		function updateSelectedCount() {
			selectedOrdersForPrint = [];
			$modal.find('.moksafowo-payuni-order-checkbox:checked').each(function() {
				selectedOrdersForPrint.push($(this).val());
			});
			
			$modal.find('.selected-count').text(selectedOrdersForPrint.length);
			$modal.find('.moksafowo-payuni-print-confirm').prop('disabled', selectedOrdersForPrint.length === 0);
		}
		
		// Render filtered orders in the table
		function renderFilteredOrders() {
			var tbody = $modal.find('.moksafowo-payuni-orders-table-wrapper tbody');
			tbody.empty();
			
			if (filteredOrdersData.length === 0) {
				tbody.append('<tr><td colspan="7" style="text-align: center; padding: 20px;">沒有符合過濾條件的訂單</td></tr>');
				return;
			}
			
			filteredOrdersData.forEach(function(order) {
				// Build status badge with WooCommerce native styling
				var statusClass = 'order-status status-' + order.status;
				var statusBadge = '<mark class="' + statusClass + '"><span>' + order.status_name + '</span></mark>';
				
				// Build row based on ship type
				var row;
				if (currentShipType == '2' || currentShipType == 2) {
					// TCat orders - include package spec column
					var packageSpecDisplay = '';
					if (order.package_spec && order.package_spec !== '-') {
						packageSpecDisplay = '<span class="moksafowo-payuni-package-spec-display" ' +
							'data-order-id="' + order.id + '" ' +
							'data-value="' + (order.package_spec_value || '1') + '" ' +
							'data-goods-type="' + (order.goods_type || '1') + '" ' +
							'style="color: #2271b1; cursor: pointer; text-decoration: none;">' +
							order.package_spec +
							'</span>';
					} else {
						packageSpecDisplay = '-';
					}
					
					row = '<tr data-order-id="' + order.id + '">' +
						'<td><input type="checkbox" class="moksafowo-payuni-order-checkbox" value="' + order.id + '" ' + 
						(order.printable ? '' : 'disabled') + ' /></td>' +
						'<td><a href="' + order.edit_url + '" target="_blank"><strong>#' + order.number + '</strong></a></td>' +
						'<td>' + order.customer + '</td>' +
						'<td>' + order.shipping_method + '</td>' +
						'<td>' + statusBadge + '</td>' +
						'<td class="package-spec-cell">' + packageSpecDisplay + '</td>' +
						'<td>' + (order.ship_no || '-') + '</td>' +
						'</tr>';
				} else {
					// CVS orders - no package spec column
					row = '<tr data-order-id="' + order.id + '">' +
						'<td><input type="checkbox" class="moksafowo-payuni-order-checkbox" value="' + order.id + '" ' + 
						(order.printable ? '' : 'disabled') + ' /></td>' +
						'<td><a href="' + order.edit_url + '" target="_blank"><strong>#' + order.number + '</strong></a></td>' +
						'<td>' + order.customer + '</td>' +
						'<td>' + order.shipping_method + '</td>' +
						'<td>' + statusBadge + '</td>' +
						'<td>' + (order.ship_no || '-') + '</td>' +
						'</tr>';
				}
				tbody.append(row);
			});
			
			// Update count
			updateSelectedCount();
		}
		
		// Update package spec options based on goods type selection
		function updatePackageSpecOptions() {
			var goodsType = $('#moksafowo-payuni-filter-goods-type').val();
			var $specSelect = $('#moksafowo-payuni-filter-package-spec');
			var currentSpec = $specSelect.val();
			
			// Clear and rebuild options
			$specSelect.empty();
			$specSelect.append('<option value="">全部</option>');
			$specSelect.append('<option value="1">60cm</option>');
			$specSelect.append('<option value="2">90cm</option>');
			$specSelect.append('<option value="3">120cm</option>');
			
			// Only add 150cm option for normal temperature (goods_type = 1)
			if (!goodsType || goodsType === '1') {
				$specSelect.append('<option value="4">150cm</option>');
			}
			
			// Restore previous selection if still valid
			if (currentSpec) {
				$specSelect.val(currentSpec);
				// If 150cm was selected but no longer available, clear selection
				if (currentSpec === '4' && (goodsType === '2' || goodsType === '3')) {
					$specSelect.val('');
				}
			}
		}
		
		// Apply filters to orders
		function applyFilters() {
			var goodsType = $('#moksafowo-payuni-filter-goods-type').val();
			var packageSpec = $('#moksafowo-payuni-filter-package-spec').val();
			
			// Filter orders
			filteredOrdersData = allOrdersData.filter(function(order) {
				var matchGoodsType = !goodsType || order.goods_type == goodsType;
				var matchPackageSpec = !packageSpec || order.package_spec_value == packageSpec;
				return matchGoodsType && matchPackageSpec;
			});
			
			// Update UI
			renderFilteredOrders();
			
			// Show/hide clear button
			if (goodsType || packageSpec) {
				$modal.find('.moksafowo-payuni-clear-filter').show();
				
				// Show filtered count
				var message = '已過濾：顯示 ' + filteredOrdersData.length + ' / ' + allOrdersData.length + ' 筆訂單';
				$modal.find('.moksafowo-payuni-filter-message').text(message).show();
			} else {
				$modal.find('.moksafowo-payuni-clear-filter').hide();
				$modal.find('.moksafowo-payuni-filter-message').hide();
			}
		}
		
		// Handle goods type filter change
		$(document).on('change', '#moksafowo-payuni-filter-goods-type', function() {
			updatePackageSpecOptions();
		});
		
		// Handle apply filter button
		$(document).on('click', '.moksafowo-payuni-apply-filter', function() {
			applyFilters();
		});
		
		// Handle clear filter button
		$(document).on('click', '.moksafowo-payuni-clear-filter', function() {
			$('#moksafowo-payuni-filter-goods-type').val('');
			$('#moksafowo-payuni-filter-package-spec').val('');
			applyFilters();
		});
		
		// Handle print confirm
		$(document).on('click', '.moksafowo-payuni-print-confirm', function() {
			if (selectedOrdersForPrint.length === 0) {
				return;
			}
			
			var $button = $(this);
			var originalText = $button.html();
			
			// For TCat orders, validate that all selected orders have same specs
			if (currentShipType == '2' || currentShipType == 2) {
				var selectedOrders = filteredOrdersData.filter(function(order) {
					// Convert both to strings for comparison
					var orderId = String(order.id);
					return selectedOrdersForPrint.some(function(selectedId) {
						return String(selectedId) === orderId;
					});
				});
				
				if (selectedOrders.length > 1) {
					var firstGoodsType = String(selectedOrders[0].goods_type);
					var firstPackageSpec = String(selectedOrders[0].package_spec_value);
					var inconsistentOrders = [];
					
					for (var i = 0; i < selectedOrders.length; i++) {
						var currentGoodsType = String(selectedOrders[i].goods_type);
						var currentPackageSpec = String(selectedOrders[i].package_spec_value);
						
						if (i > 0) {
							if (currentGoodsType !== firstGoodsType || currentPackageSpec !== firstPackageSpec) {
								inconsistentOrders.push('#' + selectedOrders[i].number);
							}
						}
					}
					
					if (inconsistentOrders.length > 0) {
						alert('批次列印黑貓宅配標籤時，所有訂單必須有相同的溫層和包裹規格。\n請使用過濾功能或重新選擇訂單。');
						
						// Re-enable button
						$button.prop('disabled', false);
						$button.html(originalText);
						return;
					}
				}
			}
			
			// Disable button and show loading
			$button.prop('disabled', true);
			$button.html('<span class="spinner is-active" style="float: none; margin-top: 0; margin-right: 5px;"></span> 處理中...');
			
			// Make AJAX request for batch print
			$.ajax({
				url: moksafowo_payuni_batch_print.ajax_url,
				type: 'POST',
				data: {
					action: 'moksafowo_payuni_batch_print',
					security: moksafowo_payuni_batch_print.nonce,
					order_ids: selectedOrdersForPrint,
					ship_type: currentShipType
				},
				success: function(response) {
					if (response.success) {
						var data = response.data;
						
						// Close modal
						$modal.fadeOut();
						
						// Open print window
						var printWindow = window.open(data.print_url, '_blank');
						
						if (printWindow && !printWindow.closed) {
							printWindow.focus();
							
							// Show success message
							var message = data.error_count > 0 
								? '成功處理 ' + data.valid_count + ' 筆訂單，' + data.error_count + ' 筆失敗'
								: '成功處理 ' + data.valid_count + ' 筆訂單';
							
							// Create notice
							var noticeHtml = '<div class="notice notice-success is-dismissible" style="margin-top: 20px;"><p>' + message + '</p></div>';
							$('.wrap > h1, .woocommerce-layout__header').first().after(noticeHtml);
							
							// Auto dismiss after 5 seconds
							setTimeout(function() {
								$('.notice-success').fadeOut();
							}, 5000);
						} else {
							// Popup was blocked
							alert('瀏覽器阻擋了彈出視窗，請允許彈出視窗或點擊下方連結手動開啟：\n' + data.print_url);
							window.location.href = data.print_url;
						}
					} else {
						alert('列印失敗：' + response.data);
					}
				},
				error: function() {
					alert('發生錯誤，請稍後再試');
				},
				complete: function() {
					$button.prop('disabled', false).html(originalText);
				}
			});
		});
		
		// Handle modal close
		$(document).on('click', '.moksafowo-payuni-modal-close, .moksafowo-payuni-modal-cancel', function() {
			$modal.fadeOut();
			// Reset filters
			$('#moksafowo-payuni-filter-goods-type').val('');
			$('#moksafowo-payuni-filter-package-spec').val('');
			$modal.find('.moksafowo-payuni-clear-filter').hide();
			$modal.find('.moksafowo-payuni-filter-message').hide();
		});
		
		// Close modal on background click
		$(document).on('click', '.moksafowo-payuni-modal', function(e) {
			if (e.target === this) {
				$modal.fadeOut();
				// Reset filters
				$('#moksafowo-payuni-filter-goods-type').val('');
				$('#moksafowo-payuni-filter-package-spec').val('');
				$modal.find('.moksafowo-payuni-clear-filter').hide();
				$modal.find('.moksafowo-payuni-filter-message').hide();
			}
		});
		
		// Add CSS for spinner
		if ($('#moksafowo-payuni-batch-print-css').length === 0) {
			$('head').append('<style id="moksafowo-payuni-batch-print-css">' +
				'.moksafowo-payuni-batch-print-buttons { display: inline-block; }' +
				'.moksafowo-payuni-batch-print-buttons .button { margin-right: 5px; }' +
				'.moksafowo-payuni-batch-print-buttons .dashicons { margin-right: 3px; }' +
				'.moksafowo-payuni-print-status { line-height: 28px; }' +
				'.moksafowo-payuni-print-status .spinner { margin-top: 3px !important; }' +
				'.moksafowo-payuni-orders-list .wp-list-table { margin-top: 0px !important; }' +
			'</style>');
		}
	});

})( jQuery );