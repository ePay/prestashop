{*
* Copyright (c) 2019. All rights reserved ePay A/S (a Bambora Company).
*
* This program is free software. You are allowed to use the software but NOT allowed to modify the software.
* It is also not legal to do any changes to the software and distribute it in your own name / brand.
*
* All use of the payment modules happens at your own risk. We offer a free test account that you can use to test the module.
*
* @author    ePay A/S (a Bambora Company)
* @copyright Bambora (http://bambora.com) (http://www.epay.dk)
* @license   ePay A/S (a Bambora Company)
*
*}

<script type="text/javascript" src="{$epayPaymentWindowJsUrl|escape:'htmlall':'UTF-8'}" charset="UTF-8"></script>
	<script type="text/javascript">
		{literal}
		function openEPayPaymentWindow() {
			var requestString = {/literal}{$epayPaymentWindowRequest|replace:'epay_':''|@json_encode nofilter}{literal};
		
			var requestStringJson = JSON.parse(requestString);
			paymentwindow = new PaymentWindow(requestStringJson);
			paymentwindow.open();
		}
		{/literal}
	</script>	

<p class="payment_module">
  <a title="{$epayPaymentTitle|escape:'htmlall':'UTF-8'}" href="javascript: paymentwindow.open();">
    <span style="height:49px; width:86px; float:left; margin-right: 1em;" id="epay_logos">
      <img src="{$thisPathEpay|escape:'htmlall':'UTF-8'}epay.png" alt="{$epayPaymentTitle|escape:'htmlall':'UTF-8'}" style="float:left;" />
    </span>
    <span style="float:left;">
    {$epayPaymentTitle|escape:'htmlall':'UTF-8'}
      <br />	
      <span style="width:100%; float: left;" id="epay_card_logos"></span>
    </span>
  </a>
</p>

<script type="text/javascript" src="https://relay.ditonlinebetalingssystem.dk/integration/paymentlogos/PaymentLogos.aspx?merchantnumber={$epayMerchant|escape:'htmlall':'UTF-8'}&direction=2&padding=2&rows=2&logo=0&showdivs=0&divid=epay_card_logos"></script>