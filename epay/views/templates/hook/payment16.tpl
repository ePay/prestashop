<form action="https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/Default.aspx" method="post" id="ePayForm">
	{foreach from=$parameters key=k item=v}
		{if ($k|replace:'epay_':'') == "windowstate"}
			<input type="hidden" name="windowstate" value="3">
		{else}
			<input type="hidden" name="{$k|replace:'epay_':''}" value="{$v}">
		{/if}
	{/foreach}
</form>

<p class="payment_module">
	<a title="{l s='Pay using ePay' mod='epay'}" href="javascript: ePayForm.submit();">
		<img src="{$this_path_epay}epay.png" alt="{l s='Pay using ePay' mod='epay'}" style="float:left; margin-right: 15px;" />
		<span id="epay_card_logos">Cards</span>		
	</a>
</p>
<script type="text/javascript" src="https://relay.ditonlinebetalingssystem.dk/integration/paymentlogos/PaymentLogos.aspx?merchantnumber={$parameters["epay_merchantnumber"]}&direction=2&padding=2&rows=2&logo=0&showdivs=0&cardwidth=45&divid=epay_card_logos"></script>