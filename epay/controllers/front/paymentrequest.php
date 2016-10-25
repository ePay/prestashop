<?php
/*
  Copyright (c) 2010. All rights reserved ePay - www.epay.dk.

  This program is free software. You are allowed to use the software but NOT allowed to modify the software. 
  It is also not legal to do any changes to the software and distribute it in your own name / brand. 
*/

class EPayPaymentRequestModuleFrontController extends ModuleFrontController
{
	public function postProcess()
	{
		$amount = number_format(Tools::getValue('amount') / 100, 2, ".", "");
		$id_order = Tools::getValue('id_order');
		$id_cart = Tools::getValue('id_cart');
		$currency = Tools::getValue('currency');
		$cardid = Tools::getValue('paymenttype');
		$cardnopostfix = (isset($_GET['cardno']) ? substr($_GET['cardno'],  - 4) : 0);
		$transfee = (isset($_GET['txnfee']) ? $_GET['txnfee'] : 0);
		$fraud = (isset($_GET['fraud']) ? $_GET['fraud'] : 0);
		
		$params = $_GET;
		$var = "";
		
		foreach($params as $key => $value)
		{
			if($key != "hash")
			{
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
		
		$this->module->recordTransaction($id_order, $id_cart, $_GET["txnid"], $cardid, $cardnopostfix, $currency, Tools::getValue('amount'), $transfee, $fraud);
		
		$order = new Order($id_order);
		
		$message = "ePay Transaction ID: " . $_GET["txnid"];
		
		$msg = new Message();
		$message = strip_tags($message, '<br>');
		if (Validate::isCleanHtml($message))
		{
			$msg->message = $message;
			$msg->id_order = intval($order->id);
			$msg->private = 1;
			$msg->add();
		}
		
		$invoice = new OrderInvoice($order->invoice_number);
		$currency = new Currency(Currency::getIdByIsoCodeNum($_GET["currency"]));
		$currency_code = $currency->iso_code;
		$order->addOrderPayment($amount, $this->module->displayName, $_GET["txnid"], $currency_code, null, $invoice);
		
		$payments = $order->getOrderPayments();
		$payment = $payments[count($payments) - 1];

		if($transfee > 0)
		{
			$payment->amount = $payment->amount + number_format($transfee / 100, 2, ".", "");
			
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
				
				$invoice->total_paid_tax_incl = $invoice->total_paid_tax_incl + number_format($transfee / 100, 2, ".", "");
				$invoice->total_paid_tax_excl = $invoice->total_paid_tax_excl + number_format($transfee / 100, 2, ".", "");
				$invoice->total_shipping_tax_incl = $invoice->total_shipping_tax_incl + number_format($transfee / 100, 2, ".", "");
				$invoice->total_shipping_tax_excl = $invoice->total_shipping_tax_excl + number_format($transfee / 100, 2, ".", "");
				
				$invoice->save();
			}
			
			$payment->save();
		}
	}

	public function initContent()
	{
		parent::initContent();
	}
}
