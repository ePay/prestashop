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

{extends "$layout"}

{block name="content"}
<section>
	<h3>{l s='Thank you for using Bambora Online ePay' mod='epay'}</h3>
	<p>{l s='Please wait...' mod='epay'}</p>
	<script type="text/javascript">
		{literal}
		var isPaymentWindowReady = false;
		function PaymentWindowReady() {
			var requestString = {/literal}{$epayPaymentWindowRequest|replace:'epay_':''|@json_encode nofilter}{literal};
			var windowState = {/literal}'{$epayWindowState nofilter}'{literal};	
			var cancelUrl = {/literal}'{$epayCancelUrl nofilter}'{literal};
			
			var requestStringJson = JSON.parse(requestString);
			paymentwindow = new PaymentWindow(requestStringJson);
			if(windowState == 1) {
				paymentwindow.on("close", function(){
					window.location.href = cancelUrl;
				});
			}
			isPaymentWindowReady = true;
		}
		{/literal}		
	</script>	
	<script type="text/javascript" src="{$epayPaymentWindowJsUrl nofilter}" charset="UTF-8"></script>
    <script type="text/javascript">
		{literal}	
		var timerOpenWindow;
		function openPaymentWindow()
		{
			if(isPaymentWindowReady)
			{
				clearInterval(timerOpenWindow);
				paymentwindow.open();
			}
		}
		document.onreadystatechange = function ()
		{
			if(document.readyState === "complete")
			{
				timerOpenWindow = setInterval("openPaymentWindow()", 500);
			}
		}
		{/literal}
    </script>
</section>
{/block}