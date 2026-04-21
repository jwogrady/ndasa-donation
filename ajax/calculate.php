<?php
	$value = $_POST['amount'];
	$extra = ((($value * 2.9) / 100) + 0.30);
	$amount = ($value + $extra);
	echo $amount;
?>