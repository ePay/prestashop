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

<section>
	<div class="epay_section_container">
		{if $onlyShowLogoes != true}
		<p class="epay_section_text">{l s='You have chosen to pay for the order online. Once you have completed your order, you will be transferred to the Bambora Online ePay. Here you need to process your payment. Once payment is completed, you will automatically be returned to our shop.' mod='epay'}</p>
		{/if}
		<div id="epay_paymentlogos">
			<script type="text/javascript" src="https://relay.ditonlinebetalingssystem.dk/integration/paymentlogos/PaymentLogos.aspx?merchantnumber={$merchantNumber|escape:'htmlall':'UTF-8'}&direction=2&padding=2&rows=1&logo=0&showdivs=0&divid=epay_paymentlogos"></script>
		</div>
	</div>
</section>
