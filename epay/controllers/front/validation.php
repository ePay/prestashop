<?php
/*
  Copyright (c) 2010. All rights reserved ePay - www.epay.dk.

  This program is free software. You are allowed to use the software but NOT allowed to modify the software. 
  It is also not legal to do any changes to the software and distribute it in your own name / brand. 
*/

class EPayValidationModuleFrontController extends ModuleFrontController
{	
	public $ssl = true;
	public $display_column_left = false;

	public function postProcess()
	{
		$amount = number_format(Tools::getValue('amount') / 100, 2, ".", "");
		$id_cart = Tools::getValue('orderid');
		$cart = new Cart($id_cart);
		$currency = Tools::getValue('currency');
		if (!$currencyid = Currency::getIdByIsoCodeNum($currency))
			$currencyid = NULL;
		$cardid = Tools::getValue('paymenttype');
		$cardnopostfix = (isset($_GET['cardno']) ? substr($_GET['cardno'],  - 4) : 0);
		$transfee = (isset($_GET['txnfee']) ? $_GET['txnfee'] : 0);
		$fraud = (isset($_GET['fraud']) ? $_GET['fraud'] : 0);
		
		$mailVars = array();
		
		$params = $_GET;
		$var = "";
		
		foreach($params as $key => $value)
		{
			if($key != "hash")
			{
				$mailVars['{epay_' . $key . '}'] = $value;
				$var .= $value;
			}
			else
			{
				break;
			}
		}

		if(strlen(Configuration::get('EPAY_MD5KEY')) > 0)
		{
			$genstamp = md5($var . Configuration::get('EPAY_MD5KEY'));
			
			if($genstamp != $_REQUEST["hash"])
				die(Tools::displayError('Error in MD5 data! Please review your passwords in both ePay and your Prestashop admin!'));
		}
		
		if($cart->OrderExists() == 0)
		{	
			$message = "ePay Transaction ID: " . $_GET["txnid"];
			
			if($this->module->validateOrder((int)$id_cart, Configuration::get('PS_OS_PAYMENT'), $amount, $this->module->getCardnameById($cardid), $message, $mailVars, $currencyid, false, $cart->secure_key))
			{
				$this->module->recordTransaction(null, $id_cart, $_GET["txnid"], $cardid, $cardnopostfix, $currency, Tools::getValue('amount'), $transfee, $fraud);
				
				$order = new Order($this->module->currentOrder);
				
				$payment = $order->getOrderPayments();
				$payment[0]->transaction_id = Tools::getValue('txnid');
				$payment[0]->amount = $amount;
				$payment[0]->card_number = 'XXXX XXXX XXXX ' . $cardnopostfix;
				$payment[0]->card_brand = $this->module->getCardnameById($cardid);
				
				if($transfee > 0)
				{
					$payment[0]->amount = $payment[0]->amount + number_format($transfee / 100, 2, ".", "");
					
					if(Configuration::get('EPAY_ADDFEETOSHIPPING'))
					{
						$order->total_paid = $order->total_paid + number_format($transfee / 100, 2, ".", "");
						$order->total_paid_tax_incl = $order->total_paid_tax_incl + number_format($transfee / 100, 2, ".", "");
						$order->total_paid_tax_excl = $order->total_paid_tax_excl + number_format($transfee / 100, 2, ".", "");
						$order->total_paid_real = $order->total_paid_real + number_format($transfee / 100, 2, ".", "");
						$order->total_shipping = $order->total_shipping + number_format($transfee / 100, 2, ".", "");
						$order->total_shipping_tax_incl = $order->total_shipping_tax_incl + number_format($transfee / 100, 2, ".", "");
						$order->total_shipping_tax_excl = $order->total_shipping_tax_excl + number_format($transfee / 100, 2, ".", "");
						$order->save();
						
						$invoice = $payment[0]->getOrderInvoice($this->module->currentOrder);
						$invoice->total_paid_tax_incl = $invoice->total_paid_tax_incl + number_format($transfee / 100, 2, ".", "");
						$invoice->total_paid_tax_excl = $invoice->total_paid_tax_excl + number_format($transfee / 100, 2, ".", "");
						$invoice->total_shipping_tax_incl = $invoice->total_shipping_tax_incl + number_format($transfee / 100, 2, ".", "");
						$invoice->total_shipping_tax_excl = $invoice->total_shipping_tax_excl + number_format($transfee / 100, 2, ".", "");
						
						$invoice->save();
					}
					
					$payment[0]->save();
				}
			}
		}
		
		$id_order = Order::getOrderByCartId($id_cart);
				
		if(Tools::getValue('callback') != "1")
			Tools::redirectLink(__PS_BASE_URI__.'order-confirmation.php?key='.$cart->secure_key.'&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.(int)$id_order);
	}

	/**
	* @see FrontController::initContent()
	*/
	public function initContent()
	{
		parent::initContent();

		$this->context->smarty->assign(array(
			'total' => $this->context->cart->getOrderTotal(true, Cart::BOTH),
			'this_path' => $this->module->getPathUri(),//keep for retro compat
			'this_path_cod' => $this->module->getPathUri(),
			'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->module->name.'/'
		));

		$this->setTemplate('validation.tpl');
	}
}
	
?>