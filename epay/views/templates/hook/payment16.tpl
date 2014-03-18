<div class="row">
	<div class="col-xs-12 col-md-6">
		<form action="https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/Default.aspx" method="post" id="ePayForm">
			{foreach from=$parameters key=k item=v}
				{if ($k|replace:'epay_':'') == "windowstate"}
					<input type="hidden" name="windowstate" value="3">
				{else}
					<input type="hidden" name="{$k|replace:'epay_':''}" value="{$v}">
				{/if}
			{/foreach}

			<a title="{l s='Pay using ePay' mod='epay'}" href="javascript: ePayForm.submit();">
				<span style="height:49px; width:86px; float:left; margin-right: 1em;" id="epay_logos">
					<img src="{$this_path_epay}epay.png" alt="{l s='Pay using ePay' mod='epay'}" style="float:left;" />
				</span>
				<span style="float:left;">
				{l s='Pay using ePay' mod='epay'}
					<br />	
					<span style="width:100%; float: left;" id="epay_card_logos">Cards</span>
				</span>
			</a>
			
		</form>

		<script type="text/javascript" src="https://relay.ditonlinebetalingssystem.dk/integration/paymentlogos/PaymentLogos.aspx?merchantnumber={$parameters["epay_merchantnumber"]}&direction=2&padding=2&rows=2&logo=0&showdivs=0&cardwidth=45&divid=epay_card_logos"></script>
	</div>
</div>