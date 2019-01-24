jQuery(document).ready(function($) {
	/*--- Aggiunta/Rimozione dei preferiti -----------------------------------*/
	var $mfc_hook_addremove = $('[data-mfc-hook="add-or-remove"]')
	;
	
	$mfc_hook_addremove.click(function(e) {
		var $this = $(this);
		
		if (!$this.hasClass('wait')) {
			$this.addClass('wait');
			
			$.ajax({
				type: 'POST',
				dataType: 'json',
				url: mfc_config.admin_ajax,
				data: {
					action: 'mfc_add_or_remove',
					nonce: mfc_config.nonce,
					item_id: $this.data('item-id')
				},
				success: function (data) {
					$this.removeClass('wait');
					
					if (data.success === false) {
						console.log('Error MFC!');
						
						$this.removeClass('wait');
						
						return;
					} else if (data.remove === true) {
						$this.removeClass('mfc-remove-favorite');
						$this.addClass('mfc-add-favorite');
					} else if (data.remove === false) {
						$this.removeClass('mfc-add-favorite');
						$this.addClass('mfc-remove-favorite');
					}
					
					$this.html(data.html);
				}
			});
		}
	});
	/*----------------------------------------------------------------------*/
});