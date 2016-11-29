<form action="https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/Default.aspx" method="post" id="ePayForm">
	{foreach from=$parameters key=k item=v}
		{if ($k|replace:'epay_':'') == "windowstate"}
			<input type="hidden" name="windowstate" value="3">
		{else}
			<input type="hidden" name="{$k|replace:'epay_':''}" value="{$v}">
		{/if}
	{/foreach}
</form>
<div class="epay_paymentwindow_container">
<p class="payment_module">
	<a class="epay_payment_content" title="{l s='Pay using ePay' mod='epay'}" href="javascript: ePayForm.submit();">
		<span id="epay_card_logos">Cards</span>		
	</a>
</p>
  </div>
<script type="text/javascript" src="https://relay.ditonlinebetalingssystem.dk/integration/paymentlogos/PaymentLogos.aspx?merchantnumber={$parameters["epay_merchantnumber"]}&direction=2&padding=2&rows=1&logo=0&showdivs=0&cardwidth=45&divid=epay_card_logos"></script>