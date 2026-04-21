<?php
	include('includes/config.php');
	require($base_path . 'vendor/autoload.php');

	$action = $_REQUEST['action'];

	if($action == 'process'){
		$fname = $_POST['fname'];
		$lname = $_POST['lname'];
		$phone = $_POST['phone'];
		$email = $_POST['email'];
		$amount = $_POST['amount'];
		$optradio = $_POST['optradio'];
		$cardholder_fname = $_POST['cardholder_fname'];
		$cardholder_lname = $_POST['cardholder_lname'];
		$cardnum = $_POST['cardnum'];
		$cardzip = $_POST['cardzip'];
		$exp_month = $_POST['exp_month'];
		$exp_year = $_POST['exp_year'];
		$cvv = $_POST['cvv'];

		if($optradio == 'yes'){
			$extra = ((($amount * 2.9) / 100) + 0.30);
			$total = ($amount + $extra);
		}
		else {
			$total = $amount;
		}

		if(!empty($_POST['stripeToken'])){
			// get token and user details
			$stripeToken = $_POST['stripeToken'];
			$custName = $cardholder_fname . ' ' . $cardholder_lname;
			$custEmail = $authform_email;
			$cardNumber = $cardnum;
			$cardCVC = $cvv;
			$cardExpMonth = $exp_month;
			$cardExpYear = $exp_year;
			//set stripe secret key and publishable key
			/*
			$stripe = array(
				"secret_key" => "***REDACTED-sk-test***",
				"publishable_key" => "***REDACTED-pk-test***"
			);
			*/
			$stripe = array(
				"secret_key" => "***REDACTED-sk-live***",
				"publishable_key" => "***REDACTED-pk-live***"
			);
			\Stripe\Stripe::setApiKey($stripe['secret_key']);
			try {
				$customer = \Stripe\Customer::create(array(
					'email' => $custEmail,
					'source' => $stripeToken
				));
			}
			catch(Exception $e) {
				echo 'Message: ' .$e->getMessage();
			}
			$iname = uniqid('DT');
			$iska  = uniqid('SKA');
			$itemName = "NDASA Donation";
			$itemNumber = $iname;
			$itemPrice = (int)($total * 100);
			$currency = "usd";
			$orderID = $iska;
			// details for which payment performed
			$payArray = array(
				'customer' => $customer->id,
				'amount' => $itemPrice,
				'currency' => $currency,
				'description' => $itemName,
				'metadata' => array(
					'order_id' => $orderID
				)
			);
			try {
				$payDetails = \Stripe\Charge::create($payArray);
			}
			catch(Exception $e) {
				echo 'Message: ' .$e->getMessage();
			}
			// get payment details
			$paymenyResponse = $payDetails->jsonSerialize();
			// check whether the payment is successful
			if($paymenyResponse['amount_refunded'] == 0 && empty($paymenyResponse['failure_code']) && $paymenyResponse['paid'] == 1 && $paymenyResponse['captured'] == 1){
				// transaction details
				$amountPaid = $paymenyResponse['amount'];
				$balanceTransaction = $paymenyResponse['balance_transaction'];
				$paidCurrency = $paymenyResponse['currency'];
				$paymentStatus = $paymenyResponse['status'];
				$paymentDate = date("Y-m-d H:i:s");
				$transid = uniqid('d');
				$extra_data = array(
					'amount' => $total,
					'email' => $email,
					'phone' => $phone,
					'contact' => $fname . ' ' . $lname,
					'company_name' => $company_name,
					'ipaddr' => $ipaddr
				);
				if($paymentStatus == 'succeeded'){
					$paymentMessage = "The payment was successful. Order ID: " . $transid;
					$json = array();
					$json['transaction_id']   = $transid;
					$json['approval_code']    = $balanceTransaction;
					$json['approval_message'] = $paymentStatus;
					$extra_data['amount']     = ($amountPaid / 100);
					$_SESSION['transaction_id'] = $json['transaction_id'];
					$_SESSION['amount'] = $extra_data['amount'];
					$_SESSION['approval_code'] = $json['approval_code'];
					$_SESSION['approval_message'] = $json['approval_message'];
					$_SESSION['avs_response'] = $json['avs_response'];
					$_SESSION['csc_response'] = $json['csc_response'];
					$_SESSION['cardholder_fname'] = $extra_data['cardholder_fname'];
					$_SESSION['cardholder_lname'] = $extra_data['cardholder_lname'];
					$_SESSION['invoice'] = $extra['invoice'];
					// send email to info@accrediteddrugtesting.com
					$message  = 'Amount  : ' . $extra_data['amount'] . "\n";
					$message .= 'Trans Id: ' . $json['transaction_id'] . "\n";
					$message .= 'Approval: ' . $json['approval_code'] . "\n";
					$message .= 'Status  : ' . str_replace('ZIP  MATCH -', '', str_replace('ZIP MATCH -', '', $json['approval_message'])) . "\n\n";
					$message .= 'Contact Name  : ' . $extra_data['contact'] . "\n";
					$message .= 'Phone : ' . $extra_data['phone'] . "\n";
					$message .= 'Email  : ' . $extra_data['email'] . "\n";
					$message2  = "Thank You for Your Support\n\n";
					$message2 .= "Every donation, regardless of size, makes a difference. Your generosity enables us to expand our impact and build a stronger, healthier community.\n\n";
					$message2 .= "Together, we can achieve lasting change.\n\n";
					$message2 .= "James Greer\n";
					$message2 .= "Chairman, Board of Trustees\n";					
					$to       = 'john@accrediteddrugtesting.com';
					$subject  = 'NDASA Donation: ' . $extra_data['contact'];
					$headers  = 'From: info@accrediteddrugtesting.com' . "\r\n" . 'Reply-To: info@accrediteddrugtesting.com' . "\r\n" . 'X-Mailer: PHP/' . phpversion();
					mail($to, $subject, $message, $headers);
					mail("wicross.wc@gmail.com", $subject, $message, $headers);
					// send email to the buyer
					mail($extra_data['email'], $subject, $message2, $headers);
					header("Location: /donation/index.php?action=success");
					exit;
				}
				else {
					$paymentMessage = " Payment failed! Reason 1";
					$error = '<span style="color:red">Payment Failed at Payment Processor. If a charge appears on your card please call customer service.</span>'.$paymentMessage;
					$pagetitle = 'Schedule Tests Payment | (800) 221- 4291';
					include($base_path . 'includes/header.php');
					include($base_path . 'templates/payment-error.php');
					include($base_path . 'includes/footer.php');
					exit;
				}
			}
			else {
				$paymentMessage = " Payment failed! Reason 2";
				$error = '<span style="color:red">Payment Failed at Payment Processor. If a charge appears on your card please call customer service.</span>'.$paymentMessage;
				$pagetitle = 'Schedule Tests Payment | (800) 221- 4291';
				include($base_path . 'includes/header.php');
				include($base_path . 'templates/payment-error.php');
				include($base_path . 'includes/footer.php');
				exit;
			}
		}
		else {
			$paymentMessage = " Payment failed! Reason 3";
			$error = '<span style="color:red">Payment Failed at Payment Processor. If a charge appears on your card please call customer service.</span>'.$paymentMessage;
			$pagetitle = 'Schedule Tests Payment | (800) 221- 4291';
			include($base_path . 'includes/header.php');
			include($base_path . 'templates/payment-error.php');
			include($base_path . 'includes/footer.php');
			exit;
		}
	}
	else if($action == 'success'){
		include($base_path . 'includes/header.php');
		include($base_path . 'templates/success.php');
		include($base_path . 'includes/footer.php');
		exit;
	}
	else {
		include('includes/header.php');
		include('templates/form.php');
		include('includes/footer.php');
	}

?>