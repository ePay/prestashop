{*
* Copyright (c) 2018. All rights reserved ePay A/S (a Bambora Company).
*
* This program is free software. You are allowed to use the software but NOT allowed to modify the software.
* It is also not legal to do any changes to the software and distribute it in your own name / brand.
*
* All use of the payment modules happens at your own risk. We offer a free test account that you can use to test the module.
*
* @author    ePay A/S (a Bambora Company)
* @copyright Bambora (https://bambora.com) (http://www.epay.dk)
* @license   ePay A/S (a Bambora Company)
*
*}

<div class="epay-compleated-container">
    <div class="icon-check"></div>
    <p id="epay_completed_payment">
        <strong>{$epay_completed_paymentText|escape:'htmlall':'UTF-8'}</strong>
    </p>
    <p>
        {$epay_completed_transactionText|escape:'htmlall':'UTF-8'} <strong> {$epay_completed_transactionValue|escape:'htmlall':'UTF-8'}</strong>
        <br/>
        {$epay_completed_cardNoPostFixText|escape:'htmlall':'UTF-8'} <strong> {$epay_completed_cardNoPostFixValue|escape:'htmlall':'UTF-8'}</strong>
        <br/>
        {$epay_completed_emailText|escape:'htmlall':'UTF-8'} <strong> {$epay_completed_emailValue|escape:'htmlall':'UTF-8'}</strong>
    </p>
</div>
