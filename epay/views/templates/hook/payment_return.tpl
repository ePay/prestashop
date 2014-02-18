	<p>{l s='Your order on' mod='epay'} <span class="bold">{$shop_name}</span> {l s='is complete.' mod='epay'} 
	{if $postfix}
		<br /><br />
		{l s='The transaction was made with card' mod='epay'} <b>XXXX XXXX XXXX {$postfix}</b>
	{/if}
		<br /><br />
	</p>

