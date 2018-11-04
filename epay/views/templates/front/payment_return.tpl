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

<div class="epay-compleated-container">
  
  <div class="icon-check"></div>
  <p id="epay_completed_payment">
    <b>{$epay_completed_paymentText nofilter}</b>
  </p>
  <p>
    {$epay_completed_transactionText nofilter} <b> {$epay_completed_transactionValue nofilter}</b>
    <br/>
	{$epay_completed_cardNoPostFixText nofilter} <b> {$epay_completed_cardNoPostFixValue nofilter}</b>
	<br/>
    {$epay_completed_emailText nofilter} <b> {$epay_completed_emailValue nofilter}</b>
  </p>
</div>