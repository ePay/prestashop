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

<p>{l s='Your order on' mod='epay'} <span class="bold">{$shop_name|escape:'htmlall':'UTF-8'}</span> {l s='is complete.' mod='epay'} 
  {if $postfix}
    <br /><br />
    {l s='The transaction was made with card' mod='epay'} <b>XXXX XXXX XXXX {$postfix|escape:'htmlall':'UTF-8'}</b>
  {/if}
    <br /><br />
  </p>

