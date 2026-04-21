		<style>
			.entry-content { font-family: Rubik; font-size: 17px; line-height: 20px; padding: 50px 30px; background: #fff; color: #626262; }
			.entry-content h2 { font-family: Rubik; font-size: 36px; font-weight: 400; line-height: 42px; margin-top:25px; margin-bottom:15px; color: red; }
			.entry-content p { margin: 0px; }
			#shopstart b { color: #2241a2; font-size: 20px; font-weight: 700; line-height: 34px; text-decoration:underline }
			.yellow-static {  font-size: 18px !important; padding: 0 !important; }
		</style>
		<div class="container">
			<div class="entry-content">
				<div class="row">
					<div class="col-md-12">
						<p style="margin-top:25px;color:#626262;font-weight:700">Total</p>
						<p style="margin-top:15px;color:#060">$<?= $total ?></p>
						<p style="margin-top:25px;color:#626262;font-weight:700">Credit Card Information <span style="color:darkred">*</span></p>
						<p><img src="/assets/images/card-icons.png" alt="Credit/Debit Cards Accepted"></p>
					</div>
				</div>
				<form action="/test-shop.php" method="post" id="paymentForm">
					<input type="hidden" name="action" value="process_stripe">
<?= $invars ?>
					
					<div class="row tpad30">
						<div class="col-md-12">
							<div class="form-group">
								<label>Credit Card Number <span style="color:darkred">*</span></label>
								<input type="text" id="cardNumber" name="cardnum" class="form-control" placeholder="Card Number" required="required">
							</div>
						</div>
						<div class="col-sm-4">
							<div class="form-group">
								<label>Expiry Month <span style="color:darkred">*</span></label>
								<select id="cardExpMonth" name="exp_month" class="form-control" aria-required="true" aria-invalid="false" required="required">
									<option value="" selected="selected">Month</option>
									<option value="01">01</option>
									<option value="02">02</option>
									<option value="03">03</option>
									<option value="04">04</option>
									<option value="05">05</option>
									<option value="06">06</option>
									<option value="07">07</option>
									<option value="08">08</option>
									<option value="09">09</option>
									<option value="10">10</option>
									<option value="11">11</option>
									<option value="12">12</option>
								</select>
							</div>
						</div>
						<div class="col-sm-4">
							<div class="form-group">
								<label>Expiry Year <span style="color:darkred">*</span></label>
								<select id="cardExpYear" name="exp_year" class="form-control" aria-required="true" aria-invalid="false" required="required">
									<option value="" selected="selected">Year</option>
									<option value="2020">2020</option>
									<option value="2021">2021</option>
									<option value="2022">2022</option>
									<option value="2023">2023</option>
									<option value="2024">2024</option>
									<option value="2025">2025</option>
									<option value="2026">2026</option>
									<option value="2027">2027</option>
									<option value="2028">2028</option>
									<option value="2029">2029</option>
									<option value="2030">2030</option>
									<option value="2031">2031</option>
									<option value="2032">2032</option>
									<option value="2033">2033</option>
									<option value="2034">2034</option>
									<option value="2035">2035</option>
									<option value="2036">2036</option>
								</select>
							</div>
						</div>
						<div class="col-sm-3">
							<div class="form-group">
								<label>Security Code <span style="color:darkred">*</span></label>
								<input type="text" id="cardCVC" name="cvv" class="form-control" required="required">
							</div>
						</div>
						<div class="col-sm-1">
							<img src="/assets/images/cvv.png" alt="CVV Code">
						</div>
					</div>
					<div class="row tpad15">
						<div class="col-sm-4">
							<div class="form-group">
								<label>Cardholder First Name <span style="color:darkred">*</span></label>
								<input type="text" name="cardholder_fname" class="form-control" required="required">
							</div>
						</div>
						<div class="col-sm-4">
							<div class="form-group">
								<label>Cardholder Last Name <span style="color:darkred">*</span></label>
								<input type="text" name="cardholder_lname" class="form-control" required="required">
							</div>
						</div>
						<div class="col-sm-4">
							<div class="form-group">
								<label>Billing Zip Code <span style="color:darkred">*</span></label>
								<input type="text" name="cardzip" class="form-control" required="required">
							</div>
						</div>
					</div>
					
					<div class="row tpad25">
						<div class="col-sm-6">
							<p style="color:#0d8cc9">Payment Information</p>
							<p><strong><span style="color:#e5a63b">Accredited Drug Testing Inc.</span> provides secure and safe processing of your order using Authorize.net Secure Checkout.</strong></p>
							<p class="tpad25 bpad25"><img src="/assets/images/sslcert.png" alt="Secure Checkout"></p>
						</div>
					</div>
					<div class="row">
						<div class="col-md-12">
							<span class="paymentErrors alert-danger"></span>
						</div>
					</div>
					<div class="row">
						<div class="col-md-12 tests">
							<button type="submit" id="makePayment" class="button yellow-static">Submit Order (click once)</button>
						</div>
					</div>
				</form>
			</div>
		</div>
		
