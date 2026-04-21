// set your stripe publishable key
Stripe.setPublishableKey('***REDACTED-pk-live***');
// Stripe.setPublishableKey('***REDACTED-pk-test***');

$(document).ready(function() {
	$(document).on('submit', "#paymentForm", function(event) {
		event.preventDefault();
		$('#formSubmitButton').attr("disabled", "disabled");
		// create stripe token to make payment
		Stripe.createToken({
			number: $('#cardNumber').val(),
			cvc: $('#cardCVC').val(),
			exp_month: $('#cardExpMonth').val(),
			exp_year: $('#cardExpYear').val()
		}, handleStripeResponse);
		return false;
	});
});

// handle the response from stripe
function handleStripeResponse(status, response) {
	console.log(JSON.stringify(response));
	if (response.error) {
		$('#formSubmitButton').removeAttr("disabled");
		$(".paymentError").html(response.error.message);
		$('.paymentErrors').show();
	} else {
		var payForm = $("#paymentForm");
		//get stripe token id from response
		var stripeToken = response['id'];
		//set the token into the form hidden input to make payment
		payForm.append("<input type='hidden' name='stripeToken' value='" + stripeToken + "' />");
		payForm.get(0).submit();
	}
}
