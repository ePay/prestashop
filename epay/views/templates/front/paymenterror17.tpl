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
<div>
    <p class="alert alert-warning warning">{l s='Your payment failed because of' mod='epay'} <strong>"{$paymenterror nofilter}"</strong>
    <br/>
    {l s='Please contact the shop to correct the error and complete your payment.' mod='epay'}
    </p>
</div>
{/block}