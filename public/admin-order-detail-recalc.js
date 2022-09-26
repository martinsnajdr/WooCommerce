	window.onOrderTotalsRecalculateComplete = function ( ) {
		var urlParams = new URLSearchParams(window.location.search);

		if (urlParams.has('post')) {
			var orderId= urlParams.get('post');
		}

		jQuery.ajax( {
			type: 'POST',
			dataType: 'json',
			url: ajaxurl,
			data: {
				orderId: orderId,
				action: 'packetery_update_metabox_values'
			}
		} ).always( function( response ) {
			if ( response && response.redirectTo ) {
				window.location.href = response.redirectTo;
			}
		} ).done(function ( response )
			{
				if (response) {
					var fieldsToUpdate = ['packetery_weight', 'packetery_width', 'packetery_length', 'packetery_height',  'packetery_COD', 'packetery_value'];
					for (var i = 0; i < fieldsToUpdate.length; i++) {
						var fieldName =  fieldsToUpdate[i];
						if (response[fieldName]) {
							jQuery('#frm-' + fieldName).val(response[fieldName]);
						} else {
							jQuery('#frm-' + fieldName).val('');
						}
					}
					jQuery('#frm-packetery_adult_content').prop('checked', response.packetery_adult_content ? response.packetery_adult_content : false);
				}
			}
		);
	};

jQuery('body').on('order-totals-recalculate-complete', window.onOrderTotalsRecalculateComplete);
