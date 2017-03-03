{*
* Copyright (c) 2017. All rights reserved ePay A/S (a Bambora Company).
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

<script type="text/javascript">
  {literal}
  function PaymentWindowReady() {
  paymentwindow = new PaymentWindow({
  {/literal}
  {foreach from=$parameters key=k item=v}
  '{$k|escape:'htmlall':'UTF-8'|replace:'epay_':''}': "{$v}",
  {/foreach}
  {literal}
  });
  }
  {/literal}
</script>

<p class="payment_module">
  <a title="{l s='Pay using ePay' mod='epay'}" href="javascript: paymentwindow.open();">
    <span style="height:49px; width:86px; float:left; margin-right: 1em;" id="epay_logos">
      <img src="{$this_path_epay|escape:'htmlall':'UTF-8'}epay.png" alt="{l s='Pay using ePay' mod='epay'}" style="float:left;" />
    </span>
    <span style="float:left;">
    {l s='Pay using ePay' mod='epay'}
      <br />	
      <span style="width:100%; float: left;" id="epay_card_logos">Cards</span>
    </span>
  </a>
</p>

<script type="text/javascript" src="https://relay.ditonlinebetalingssystem.dk/integration/paymentlogos/PaymentLogos.aspx?merchantnumber={$parameters["epay_merchantnumber"]|escape:'htmlall':'UTF-8'}&direction=2&padding=2&rows=2&logo=0&showdivs=0&cardwidth=45&divid=epay_card_logos"></script>
<script type="text/javascript" src="https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/paymentwindow.js" charset="UTF-8"></script>