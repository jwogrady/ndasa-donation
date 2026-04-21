	$('input[name="optradio"]').change(function() {
		var amount = $('#amount').val();
		var opt = $('input[name="optradio"]:checked').val();
		if(amount.length >= 2){
			if(opt == 'yes'){
				$.ajax({
					type: "POST",
					url: "/donation/ajax/calculate.php",
					data: { amount: amount },
					success: function(message){
						$('#total').text('$'+message);
					}
				});
			}
			else {
				$('#total').text('$'+amount);
			}
		}
	});
	
	$('#amount').on('input', function(){
		var amount = $(this).val();
		if(amount.length >= 2){
			var opt = $('input[name="optradio"]:checked').val();
			if(opt == 'yes'){
				$.ajax({
					type: "POST",
					url: "/donation/ajax/calculate.php",
					data: { amount: amount },
					success: function(message){
						$('#total').text('$'+message);
					}
				});
			}
			else {
				$('#total').text('$'+amount);
			}
		}
	});