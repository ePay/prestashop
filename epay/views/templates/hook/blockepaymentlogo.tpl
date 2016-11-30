<!--
  Copyright (c) 2010. All rights reserved ePay - www.epay.dk.

  This program is free software. You are allowed to use the software but NOT allowed to modify the software. 
  It is also not legal to do any changes to the software and distribute it in your own name / brand. 
-->

{assign var='tmp' value=$base_dir_ssl}
{assign var='base_dir_ssl' value=$tmp}
<!-- Block ePay-ment logo module -->
<div id="epay_payment_logo_block" class="epay_payment_logo_block">
	<script type="text/javascript" src="https://relay.ditonlinebetalingssystem.dk/integration/PaymentLogos/PaymentLogos.aspx?merchantnumber={$merchantnumber}&direction=2&padding=5&rows=1&divid=epay_payment_logo_block"></script>
</div>
<!-- /Block ePay-ment logo module -->