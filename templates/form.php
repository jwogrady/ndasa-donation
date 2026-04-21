		<section class="container">
			<div class="row">
				<div class="col-md-10 offset-md-1">
					<p style="color:#9c9c9c;font-weight:600;">
						The NDASA Foundation exists to advance prevention, education, and recovery in communities across 
						the country. Your donation helps fund scholarships for students, support grants for first 
						responders and nonprofits, and deliver educational resources that make a real impact. Every 
						contribution—large or small—helps us advocate for healthier, drug-free communities. Make a 
						one-time or recurring gift today and join us in building a safer tomorrow.
					</p>
					<form id="paymentForm" action="index.php" method="post">
						<input type="hidden" name="action" value="process">
						<label>Contact Name</label>
						<div class="row">
							<div class="col-md-6">
								<div class="form-group">
									<input type="text" name="fname" class="form-control">
									<small>First</small>
								</div>
							</div>
							<div class="col-md-6">
								<div class="form-group">
									<input type="text" name="lname" class="form-control">
									<small>Last</small>
								</div>
							</div>
						</div>
						<div class="form-group">
							<label>Contact Phone</label>
							<input type="text" name="phone" class="form-control">
						</div>
						<div class="form-group">
							<label>Contact Email</label>
							<input type="text" name="email" class="form-control">
						</div>
						<div class="form-group">
							<label>Donation Amount</label>
							<input type="text" id="amount" name="amount" class="form-control">
							<small>Minimum Amount: $10.00</small>
						</div>
						<div class="form-group">
							<p style="font-weight:600">Would you like to help us by paying the processing fee of 2.9% +.30?</p>
							<div class="form-check-inline">
								<label class="form-check-label">
									<input type="radio" class="form-check-input" name="optradio" value="yes">Yes
								</label>
							</div>
							<div class="form-check-inline">
								<label class="form-check-label">
									<input type="radio" class="form-check-input" name="optradio" value="no">No
								</label>
							</div>							
						</div>
						<div class="form-group">
							<div style="font-size:1.2rem;font-weight:bold;margin-top:10px">Total: <span id="total">$0.00</span></div>
						</div>


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
						<div class="paymentErrors alert alert-danger alert-dismissible fade show" role="alert" style="display:none">
							<span class="paymentError"></span>
							<button type="button" class="close" data-dismiss="alert" aria-label="Close">
								<span aria-hidden="true">&times;</span>
							</button>
						</div>
						<div class="form-group">
							<input type="submit" id="formSubmitButton" class="btn btn-lg btn-primary">
						</div>
						

					</form>
						
				</div>
			</div>
		</section>
