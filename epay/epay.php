<?php
/*
  Copyright (c) 2010. All rights reserved ePay - www.epay.dk.

  This program is free software. You are allowed to use the software but NOT allowed to modify the software. 
  It is also not legal to do any changes to the software and distribute it in your own name / brand. 
*/
if (!defined('_PS_VERSION_'))
	exit;

class EPay extends PaymentModule
{
	private $_html = '';
	private $_postErrors = array();
	
	public function __construct()
	{
		$this->name = 'epay';
		$this->version = 4.7;
		$this->author = "ePay - Michael Korsgaard";
		$this->tab = 'payments_gateways';
		
		$this->currencies = true;
		$this->currencies_mode = 'checkbox';
		
		parent::__construct();
		
		if(Configuration::get('EPAY_ENABLE_REMOTE_API') == 1 && !class_exists("SOAPClient"))
			$this->warning = $this->l('You must have SoapClient installed to use Remote API. Contact your hosting provider for further information.');
		
		$this->displayName = 'ePay';
		$this->description = $this->l('Accept Dankort, eDankort, VISA, Electron, MasterCard, Maestro, JCB, Diners, AMEX, Nordea and Danske Bank payments by ePay / Payment Solutions');
	}
	
	public function install()
	{
		if(!parent::install() OR !Configuration::updateValue('EPAY_GOOGLE_PAGEVIEW', '0') OR !Configuration::updateValue('EPAY_INTEGRATION', '1') OR !Configuration::updateValue('EPAY_ENABLE_INVOICE', '0') OR !$this->registerHook('payment') OR !$this->registerHook('rightColumn') OR !$this->registerHook('adminOrder') OR !$this->registerHook('paymentReturn') OR !$this->registerHook('footer'))
			return false;
		
		if(!$this->_createEpayTable())
			return false;
		
		return true;
	}
	
	public function uninstall()
	{
		return parent::uninstall();
	}
	
	function _createEpayTable()
	{
		$table_name = _DB_PREFIX_ . 'epay_transactions';
		
		$columns = array
		(
			'id_order' => 'int(10) unsigned NOT NULL',
			'id_cart' => 'int(10) unsigned NOT NULL',
			'epay_transaction_id' => 'int(10) unsigned NOT NULL',
			'card_type' => 'int(4) unsigned NOT NULL DEFAULT 1',
			'cardnopostfix' => 'int(4) unsigned NOT NULL DEFAULT 1',
			'currency' => 'int(4) unsigned NOT NULL DEFAULT 0',
			'amount' => 'int(10) unsigned NOT NULL',
			'amount_captured' => 'int(10) unsigned NOT NULL DEFAULT 0',
			'amount_credited' => 'int(10) unsigned NOT NULL DEFAULT 0',
			'transfee' => 'int(10) unsigned NOT NULL DEFAULT 0',
			'fraud' => 'tinyint(1) NOT NULL DEFAULT 0',
			'captured' => 'tinyint(1) NOT NULL DEFAULT 0',
			'credited' => 'tinyint(1) NOT NULL DEFAULT 0',
			'deleted' => 'tinyint(1) NOT NULL DEFAULT 0',
			'date_add' => 'datetime NOT NULL'
		);
		
		$query = 'CREATE TABLE IF NOT EXISTS `' . $table_name . '` (';
		
		foreach ($columns as $column_name => $options)
		{
			$query .= '`' . $column_name . '` ' . $options . ', ';
		}
		$query .= ' PRIMARY KEY (`epay_transaction_id`) )';
		
		if(!Db::getInstance()->Execute($query))
			return false;
		
		$i = 0;
		$previous_column = '';
		$query = ' ALTER TABLE `' . $table_name . '` ';
		/* Check the database fields */
		foreach ($columns as $column_name => $options)
		{
			if(!$this->_mysql_column_exists($table_name, $column_name))
			{
				$query .= ($i > 0 ? ', ' : '') . 'ADD `' . $column_name . '` ' . $options . ($previous_column != '' ? ' AFTER `' . $previous_column . '`' : ' FIRST');
				$i++;
			}
			$previous_column = $column_name;
		}
		
		if($i > 0)
			if(!Db::getInstance()->Execute($query))
				return false;
		
		return true;
	}
	
	static function _mysql_column_exists($table_name, $column_name, $link = false)
	{
		$result = Db::getInstance()->executeS("SHOW COLUMNS FROM $table_name LIKE '$column_name'", $link);
		
		return (count($result) > 0);
	}
	
	function recordTransaction($id_order, $id_cart = 0, $transaction_id = 0, $cardid = 0, $cardnopostfix = 0, $currency = 0, $amount = 0, $transfee = 0, $fraud = 0)
	{
		if($id_cart)
			$id_order = Order::getOrderByCartId($id_cart);
		if(!$id_order)
			$id_order = 0;
		
		$captured = (Configuration::get('EPAY_INSTANTCAPTURE') ? 1 : 0);
		
		/* Tilføj transaktionsid til ordren */
		$query = 'INSERT INTO ' . _DB_PREFIX_ . 'epay_transactions
				(id_order, id_cart, epay_transaction_id, card_type, cardnopostfix, currency, amount, transfee, fraud, captured, date_add)
				VALUES 
				(' . $id_order . ', ' . $id_cart . ', ' . $transaction_id . ', ' . $cardid . ', ' . $cardnopostfix . ', ' . $currency . ', ' . $amount . ', ' . $transfee . ', ' . $fraud . ', ' . $captured . ', NOW() )';
		
		if(!Db::getInstance()->Execute($query))
			return false;
		
		return true;
	}
	
	function setCaptured($transaction_id, $amount)
	{
		$query = ' UPDATE ' . _DB_PREFIX_ . 'epay_transactions SET `captured` = 1, `amount` = ' . $amount . ' WHERE `epay_transaction_id` = ' . $transaction_id;
		if(!Db::getInstance()->Execute($query))
			return false;
		return true;
	}
	
	function setCredited($transaction_id, $amount)
	{
		$query = ' UPDATE ' . _DB_PREFIX_ . 'epay_transactions SET `credited` = 1, `amount` = `amount` - ' . $amount . ' WHERE `epay_transaction_id` = ' . $transaction_id;
		if(!Db::getInstance()->Execute($query))
			return false;
		return true;
	}
	
	function deleteTransaction($transaction_id)
	{
		$query = ' UPDATE ' . _DB_PREFIX_ . 'epay_transactions SET `deleted` = 1' . ' WHERE `epay_transaction_id` = ' . $transaction_id;
		if(!Db::getInstance()->Execute($query))
			return false;
		return true;
	}
	
	public function getContent()
	{
		$output = null;
 
	    if (Tools::isSubmit('submit'.$this->name))
	    {
	        $epay_merchantnumber = strval(Tools::getValue('EPAY_MERCHANTNUMBER'));
	        if (!$epay_merchantnumber  || empty($epay_merchantnumber) || !Validate::isGenericName($epay_merchantnumber))
	            $output .= $this->displayError( $this->l('Merchantnumber is required. If you don\'t have one please contact ePay on support@epay.dk in order to obtain one!') );
	        else
	        {
				Configuration::updateValue('EPAY_MERCHANTNUMBER', Tools::getValue("EPAY_MERCHANTNUMBER"));
				Configuration::updateValue('EPAY_WINDOWSTATE', Tools::getValue("EPAY_WINDOWSTATE"));
				Configuration::updateValue('EPAY_WINDOWID', Tools::getValue("EPAY_WINDOWID"));
				Configuration::updateValue('EPAY_ENABLE_REMOTE_API', Tools::getValue("EPAY_ENABLE_REMOTE_API"));
				Configuration::updateValue('EPAY_REMOTE_API_PASSWORD', Tools::getValue("EPAY_REMOTE_API_PASSWORD"));
				Configuration::updateValue('EPAY_INSTANTCAPTURE', Tools::getValue("EPAY_INSTANTCAPTURE"));
				Configuration::updateValue('EPAY_GROUP', Tools::getValue("EPAY_GROUP"));
				Configuration::updateValue('EPAY_AUTHMAIL', Tools::getValue("EPAY_AUTHMAIL"));
				Configuration::updateValue('EPAY_ADDFEETOSHIPPING', Tools::getValue("EPAY_ADDFEETOSHIPPING"));
				Configuration::updateValue('EPAY_MD5KEY', Tools::getValue("EPAY_MD5KEY"));
				Configuration::updateValue('EPAY_OWNRECEIPT', Tools::getValue("EPAY_OWNRECEIPT"));
				Configuration::updateValue('EPAY_GOOGLE_PAGEVIEW', Tools::getValue("EPAY_GOOGLE_PAGEVIEW"));
				Configuration::updateValue('EPAY_ENABLE_INVOICE', Tools::getValue("EPAY_ENABLE_INVOICE"));
	            $output .= $this->displayConfirmation($this->l('Settings updated'));
	        }
	    }
		
	    return $output.$this->displayForm();
	}
	
	public function displayForm()
	{
		// Get default Language
	    $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
	     
	    // Init Fields form array
	    $fields_form[0]['form'] = array(
	        'legend' => array(
	            'title' => $this->l('Settings'),
				'image' => $this->_path.'logo_small.gif'
	        ),
	        'input' => array(
				array(
					'type' => 'text',
					'label' => $this->l('Merchant number'),
					'name' => 'EPAY_MERCHANTNUMBER',
					'size' => 20,
					'required' => true
				),
				 array(
					'type' => 'radio',
					'label' => $this->l('Window state'),
					'name' => 'EPAY_WINDOWSTATE',
					'class' => 't',
					'values' => array(
						array(
							'id' => 'windowstate_overlay',
							'value' => 1,
							'label' => $this->l('Overlay')
						),
						array(
							'id' => 'windowstate_fullscreen',
							'value' => 3,
							'label' => $this->l('Full screen')
						)
					),
					'required' => true
	            ),
				array(
					'type' => 'text',
					'label' => $this->l('Window ID'),
					'name' => 'EPAY_WINDOWID',
					'size' => 20,
					'required' => false
				),
				array(
					'type' => 'radio',
					'label' => $this->l('Enable Remote API'),
					'name' => 'EPAY_ENABLE_REMOTE_API',
					'size' => 20,
					'class' => 't',
					'is_bool' => true,
					'required' => true,
					'values' => array(
						array(
							'id' => 'remoteapi_yes',
							'value' => 1,
							'label' => $this->l('Yes')
						),
						array(
							'id' => 'remoteapi_no',
							'value' => 0,
							'label' => $this->l('No')
						)
					),
				),
				array(
					'type' => 'text',
					'label' => $this->l('Remote API password'),
					'name' => 'EPAY_REMOTE_API_PASSWORD',
					'size' => 20,
					'required' => false
				),
				array(
					'type' => 'radio',
					'label' => $this->l('Use own receipt'),
					'name' => 'EPAY_OWNRECEIPT',
					'size' => 20,
					'class' => 't',
					'is_bool' => true,
					'required' => true,
					'values' => array(
						array(
							'id' => 'ownreceipt_yes',
							'value' => 1,
							'label' => $this->l('Yes')
						),
						array(
							'id' => 'ownreceipt_no',
							'value' => 0,
							'label' => $this->l('No')
						)
					),
				),
				array(
					'type' => 'radio',
					'label' => $this->l('Use instant capture'),
					'name' => 'EPAY_INSTANTCAPTURE',
					'size' => 20,
					'class' => 't',
					'is_bool' => true,
					'required' => true,
					'values' => array(
						array(
							'id' => 'instantcapture_yes',
							'value' => 1,
							'label' => $this->l('Yes')
						),
						array(
							'id' => 'instantcapture_no',
							'value' => 0,
							'label' => $this->l('No')
						)
					),
				),
				array(
					'type' => 'radio',
					'label' => $this->l('Add transaction fee to shipping'),
					'name' => 'EPAY_ADDFEETOSHIPPING',
					'size' => 20,
					'class' => 't',
					'is_bool' => true,
					'required' => true,
					'values' => array(
						array(
							'id' => 'addfeetoshipping_yes',
							'value' => 1,
							'label' => $this->l('Yes')
						),
						array(
							'id' => 'addfeetoshipping_no',
							'value' => 0,
							'label' => $this->l('No')
						)
					),
				),
				array(
					'type' => 'text',
					'label' => $this->l('Group'),
					'name' => 'EPAY_GROUP',
					'size' => 20,
					'required' => false
				),
				array(
					'type' => 'text',
					'label' => $this->l('Auth mail'),
					'name' => 'EPAY_AUTHMAIL',
					'size' => 20,
					'required' => false
				),
				array(
					'type' => 'text',
					'label' => $this->l('MD5 Key'),
					'name' => 'EPAY_MD5KEY',
					'size' => 20,
					'required' => false
				),
				array(
					'type' => 'radio',
					'label' => $this->l('Use Google Pageview Tracking'),
					'name' => 'EPAY_GOOGLE_PAGEVIEW',
					'size' => 20,
					'class' => 't',
					'is_bool' => true,
					'required' => true,
					'values' => array(
						array(
							'id' => 'googlepageview_yes',
							'value' => 1,
							'label' => $this->l('Yes')
						),
						array(
							'id' => 'googlepageview_no',
							'value' => 0,
							'label' => $this->l('No')
						)
					),
				),
				array(
					'type' => 'radio',
					'label' => $this->l('Enable invoice data'),
					'name' => 'EPAY_ENABLE_INVOICE',
					'size' => 20,
					'class' => 't',
					'is_bool' => true,
					'required' => true,
					'values' => array(
						array(
							'id' => 'invoicedata_yes',
							'value' => 1,
							'label' => $this->l('Yes')
						),
						array(
							'id' => 'invoicedata_no',
							'value' => 0,
							'label' => $this->l('No')
						)
					),
				)
	        ),
	        'submit' => array(
	            'title' => $this->l('Save'),
	            'class' => 'button'
	        )
	    );
	     
	    $helper = new HelperForm();
	     
	    // Module, t    oken and currentIndex
	    $helper->module = $this;
	    $helper->name_controller = $this->name;
	    $helper->token = Tools::getAdminTokenLite('AdminModules');
	    $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
	     
	    // Language
	    $helper->default_form_language = $default_lang;
	    $helper->allow_employee_form_lang = $default_lang;
	     
	    // Title and toolbar
	    $helper->title = $this->displayName . " v" . $this->version;
	    $helper->show_toolbar = true;        // false -> remove toolbar
	    $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
	    $helper->submit_action = 'submit'.$this->name;
	    $helper->toolbar_btn = array(
	        'save' =>
	        array(
	            'desc' => $this->l('Save'),
	            'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
	            '&token='.Tools::getAdminTokenLite('AdminModules'),
	        ),
	        'back' => array(
	            'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
	            'desc' => $this->l('Back to list')
	        )
	    );
	     
	    // Load current value
	    $helper->fields_value['EPAY_MERCHANTNUMBER'] = Configuration::get('EPAY_MERCHANTNUMBER');
		$helper->fields_value['EPAY_WINDOWSTATE'] = Configuration::get('EPAY_WINDOWSTATE');
		$helper->fields_value['EPAY_WINDOWID'] = Configuration::get('EPAY_WINDOWID');
		$helper->fields_value['EPAY_ENABLE_REMOTE_API'] = Configuration::get('EPAY_ENABLE_REMOTE_API');
		$helper->fields_value['EPAY_REMOTE_API_PASSWORD'] = Configuration::get('EPAY_REMOTE_API_PASSWORD');
		$helper->fields_value['EPAY_OWNRECEIPT'] = Configuration::get('EPAY_OWNRECEIPT');
		$helper->fields_value['EPAY_INSTANTCAPTURE'] = Configuration::get('EPAY_INSTANTCAPTURE');
		$helper->fields_value['EPAY_ADDFEETOSHIPPING'] = Configuration::get('EPAY_ADDFEETOSHIPPING');
		$helper->fields_value['EPAY_GROUP'] = Configuration::get('EPAY_GROUP');
		$helper->fields_value['EPAY_AUTHMAIL'] = Configuration::get('EPAY_AUTHMAIL');
		$helper->fields_value['EPAY_MD5KEY'] = Configuration::get('EPAY_MD5KEY');
		$helper->fields_value['EPAY_GOOGLE_PAGEVIEW'] = Configuration::get('EPAY_GOOGLE_PAGEVIEW');
		$helper->fields_value['EPAY_ENABLE_INVOICE'] = Configuration::get('EPAY_ENABLE_INVOICE');
		
	    return "<div class=\"warn\"><a href=\"http://www.prestashopguiden.dk/en/configuration#407\" target=\"_blank\">". $this->l('Documentation can be found here') ."<a></div>" . $helper->generateForm($fields_form);
	}
	
	function getEPayLanguage($strlan)
	{
		switch($strlan)
		{
			case "dk":
				return 1;
			case "da":
				return 1;
			case "en":
				return 2;
			case "se":
				return 3;
			case "sv":
				return 3;
			case "no":
				return 4;
			case "gl":
				return 5;
			case "is":
				return 6;
			case "de":
				return 7;
		}
		
		return 0;
	}

	private function getInvoiceData($customer, $summary, $forHash = false)
	{
		$invoice["customer"]["email"] = $customer->email;
		$invoice["customer"]["name"] = $summary["invoice"]->firstname . ' ' . $summary["invoice"]->lastname;
		$invoice["customer"]["address"] = $summary["invoice"]->address1;
		$invoice["customer"]["zip"] = intval((string)$summary["invoice"]->postcode);
		$invoice["customer"]["city"] = $summary["invoice"]->city;
		$invoice["customer"]["country"] = $summary["invoice"]->country;
		
		$invoice["shippingaddress"]["name"] = $summary["delivery"]->firstname . ' ' . $summary["delivery"]->lastname;
		$invoice["shippingaddress"]["address"] = $summary["delivery"]->address1;
		$invoice["shippingaddress"]["zip"] = intval((string)$summary["delivery"]->postcode);
		$invoice["shippingaddress"]["city"] = $summary["delivery"]->city;
		$invoice["shippingaddress"]["country"] = $summary["delivery"]->country;
		
		$invoice["lines"] = array();

		foreach ($summary["products"] as $product)
		{	
			$invoice["lines"][] = array
			(
				"id" => ($product["reference"] == "" ? $product["id_product"] : $product["reference"]),
				"description" => addslashes($product["name"] . ($product["attributes_small"] ? (" " . $product["attributes_small"]) : "")),
				"quantity" => intval((string)$product["cart_quantity"]),
				"price" => round((string)$product["price"],2)*100,
				"vat" => (float)round((string)((round($product["price_wt"],2)-round($product["price"],2))/round((string)$product["price"],2))*100, 2)
			);
		}
		
		$invoice["lines"][] = array
			(
				"id" => $this->l('shipping'),
				"description" => $this->l('Shipping'),
				"quantity" => 1,
				"price" => intval((string)round($summary["total_shipping_tax_exc"],2)*100),
				"vat" => ($summary["total_shipping_tax_exc"] > 0 ? ((float)round((string)((round($summary["total_shipping"],2)-round($summary["total_shipping_tax_exc"],2))/round((string)$summary["total_shipping_tax_exc"],2))*100, 2)) : 0)
			);
		
		foreach ($summary["discounts"] as $discount)
		{			
			$invoice["lines"][] = array
			(
				"id" => $discount["id_discount"],
				"description" => $discount["description"],
				"quantity" => 1,
				"price" => -intval(round((string)$discount["value_tax_exc"],2)*100),
				"vat" => (float)round((string)((round($discount["value_real"],2)-round($discount["value_tax_exc"],2))/round((string)$discount["value_tax_exc"],2))*100, 2)
			);
		}
		
		return $invoice;
	}
	
	private function jsonRemoveUnicodeSequences($struct)
	{
		return preg_replace("/\\\\u([a-f0-9]{4})/e", "iconv('UCS-4LE','UTF-8',pack('V', hexdec('U$1')))", json_encode($struct));
	}
	
	public function hookPayment($params)
	{
		if (!$this->active)
			return;
		if (!$this->checkCurrency($this->context->cart))
			return;
				
		$parameters = array();
		
		$parameters["epay_encoding"] = "UTF-8";
		$parameters["epay_merchantnumber"] = Configuration::get('EPAY_MERCHANTNUMBER');
		$parameters["epay_cms"] = 'prestashop' . $this->version;
		$parameters["epay_windowstate"] = Configuration::get('EPAY_WINDOWSTATE');
		
		if(Configuration::get('EPAY_WINDOWID'))
			$parameters["epay_windowid"] = Configuration::get('EPAY_WINDOWID');
			
		$parameters["epay_instantcapture"]  = Configuration::get('EPAY_INSTANTCAPTURE');
		$parameters["epay_group"]  = Configuration::get('EPAY_GROUP');
		$parameters["epay_mailreceipt"]  = Configuration::get('EPAY_MAILRECEIPT');
		$parameters["epay_ownreceipt"]  = Configuration::get('EPAY_OWNRECEIPT');
		$parameters["epay_currency"]  = $this->context->currency->iso_code;
		$parameters["epay_language"]  = $this->getEPayLanguage(Language::getIsoById($this->context->language->id));
		$parameters["epay_amount"]  = $this->context->cart->getOrderTotal()*100;
		$parameters["epay_orderid"]  = $this->context->cart->id;
		
		if(Configuration::get('EPAY_ENABLE_INVOICE'))
			$parameters["epay_invoice"]  = $this->getInvoiceData($this->context->customer, $this->context->cart->getSummaryDetails());
		
		$parameters["epay_cancelurl"] = $this->context->link->getPageLink('order', true, NULL, "step=3");
		
		$parameters["epay_accepturl"] = $this->context->link->getModuleLink('epay', 'validation', array(), true);
		$parameters["epay_callbackurl"] = $this->context->link->getModuleLink('epay', 'validation', array('callback' => 1), true);
		
		if(Configuration::get('EPAY_GOOGLE_PAGEVIEW'))
			$parameters["epay_googletracker"] = Configuration::get('GANALYTICS_ID');
		
		$hash = "";
		foreach($parameters as $key => $value)
		{
			$hash .= $value;
		}
		
		$parameters["epay_hash"] = md5($hash . Configuration::get('EPAY_MD5KEY'));
		
		$this->context->smarty->assign(array('parameters' => $parameters, 'this_path_epay' => $this->_path));

		return $this->display(__FILE__ , "payment.tpl");
	}
	
	function hookFooter($params)
	{
		$output = '';
		
		if(Configuration::get('EPAY_GOOGLE_PAGEVIEW') == 1 and strlen(Configuration::get('GANALYTICS_ID')) > 0)
		{
			$output .= '
			<script type="text/javascript">
				_gaq.push([\'_setDomainName\', \'none\']);
				_gaq.push([\'_setAllowLinker\', true]);
			</script>';
		}
		
		return $output;	
	}
	
	public function checkCurrency($cart)
	{
		$currency_order = new Currency((int)($cart->id_currency));
		$currencies_module = $this->getCurrency((int)$cart->id_currency);

		if (is_array($currencies_module))
			foreach ($currencies_module as $currency_module)
				if ($currency_order->id == $currency_module['id_currency'])
					return true;
		return false;
	}
	
	public function hookPaymentReturn($params)
	{
		if(!$this->active)
			return;
		
		$result = Db::getInstance()->getRow('
			SELECT o.`id_order`, o.`module`, e.`id_cart`, e.`epay_transaction_id`,
				   e.`card_type`, e.`cardnopostfix`, e.`currency`, e.`amount`, e.`transfee`,
				   e.`fraud`, e.`captured`, e.`credited`, e.`deleted`,
				   e.`date_add`
			FROM ' . _DB_PREFIX_ . 'epay_transactions e
			LEFT JOIN ' . _DB_PREFIX_ . 'orders o ON e.`id_cart` = o.`id_cart`
			WHERE o.`id_order` = ' . intval($_GET["id_order"]));
		
		if($result["cardnopostfix"] > 1)
			$this->context->smarty->assign(array('postfix' => $result["cardnopostfix"]));
		
		return $this->display(__FILE__ , 'payment_return.tpl');
	}
	
	function hookLeftColumn($params)
	{
		$merchantnumber = Configuration::get('EPAY_MERCHANTNUMBER');
		$this->context->smarty->assign(array('merchantnumber' => $merchantnumber));
		
		return $this->display(__FILE__ , 'blockepaymentlogo.tpl');
	}
	
	function hookRightColumn($params)
	{
		return $this->hookLeftColumn($params);
	}
	
	function hookAdminOrder($params)
	{
		$message = '';
		$activate_api = false;
		
		$result = Db::getInstance()->getRow('
			SELECT o.`id_order`, o.`module`, e.`id_cart`, e.`epay_transaction_id`,
				   e.`card_type`, e.`cardnopostfix`, e.`currency`, e.`amount`, e.`transfee`,
				   e.`fraud`, e.`captured`, e.`credited`, e.`deleted`,
				   e.`date_add`
			FROM ' . _DB_PREFIX_ . 'epay_transactions e
			LEFT JOIN ' . _DB_PREFIX_ . 'orders o ON e.`id_cart` = o.`id_cart`
			WHERE o.`id_order` = ' . intval($params["id_order"]));
		
		$currency = new Currency(Currency::getIdByIsoCodeNum($result["currency"]));
		$currency_code = $currency->iso_code;
		
		if(!isset($result["epay_transaction_id"]) OR $result["module"] != "epay")
			return '';
		else
		{
			/* Process remote capture/credit/delete */
			if(Configuration::get('EPAY_ENABLE_REMOTE_API'))
			{
				require_once (dirname(__FILE__ ) . '/api.php');
				
				try
				{
					$remote_result = $this->_procesRemote($params);
					$message = '<div class="conf">';
					if(@$remote_result->captureResult == "true")
						$message .= $this->l('Payment captured') . '</div>';
					elseif(@$remote_result->creditResult == "true")
						$message .= $this->l('Payment credited') . '</div>';
					elseif(@$remote_result->deleteResult == "true")
						$message .= $this->l('Payment deleted') . '</div>';
					elseif(@$remote_result->move_as_capturedResult == "true")
						$message .= $this->l('Payment closed') . '</div>';
					else
						$message = '';
						
					$activate_api = true;
				}
				catch (Exception $e)
				{
					$activate_api = false;
					$message = $this->displayError($e->getMessage());	
				}
			}
			
			$html = '<br />
					<fieldset>
						<legend>
							<img src="../modules/' . $this->name . '/logo_small.gif" /> ' . $this->l('ePay info') . '
						</legend>
						' . $message . '
						<table  style="width: 100%;">
							<tr>
								<td align="right" style="width: 30%;">
									' . $this->l('ePay control panel:') . '
								</td>
								<td>
									&nbsp;<a href="https://ssl.ditonlinebetalingssystem.dk/admin/login.asp" title="ePay login" target="_blank">.../admin/login.asp</a>
									' . '
								</td>
							</tr>
							<tr>
								<td align="right">
									' . $this->l('ePay transaction ID:') . '
								</td>
								<td>
									&nbsp;<b>' . $result["epay_transaction_id"] . '</b>' . '
								</td>
							</tr>
							<tr>
								<td align="right">
									' . $this->l('ePay "Order ID":') . '
								</td>
								<td>
									&nbsp;<b>' . $result["id_cart"] . '</b> (id_cart)' . '
								</td>
							</tr>';
							if($result["cardnopostfix"] > 1)
							{
							$html .= '<tr>
								<td align="right">
									' . $this->l('Postfix :') . '
								</td>
								<td>
									&nbsp;<b>XXXX XXXX XXXX ' . $result["cardnopostfix"] . '</b>' . ($result["fraud"] ? '</td>
							</tr>
							<tr>
								<td align="center" colspan="2">
									<span style="color:red;font-weight:bold;">' . $this->l('Suspicious Payment!') . '</span>' : '') . '
								</td>
							</tr>';
							}
							$html .= '<tr>
								<td align="center" colspan="2">
									<img src="../modules/' . $this->name . '/img/' . $result["card_type"] . '.png" alt="' . $this->getCardNameById(intval($result["card_type"])) . '" title="' . $this->getCardNameById(intval($result["card_type"])) . '" align="middle">';
			
			if(Configuration::get('EPAY_ENABLE_REMOTE_API') && $activate_api)
			{
				try
				{
					$api = new EPayApi();
					$soap_result = $api->gettransactionInformation(Configuration::get('EPAY_MERCHANTNUMBER'), $result["epay_transaction_id"]);
					
					if(!$soap_result->capturedamount or $soap_result->capturedamount == $soap_result->authamount)
					{
						$epay_amount = number_format($soap_result->authamount / 100, 2, ".", "");
					}
					elseif($soap_result->status == 'PAYMENT_CAPTURED')
					{
						$epay_amount = number_format(($soap_result->capturedamount) / 100, 2, ".", "");
					}
					else
					{
						$epay_amount = number_format(($soap_result->authamount - $soap_result->capturedamount) / 100, 2, ".", "");
					}
					
					if($soap_result->status != 'PAYMENT_DELETED' AND !$soap_result->creditedamount)
					{
						$html .= '<form name="epay_remote" action="' . $_SERVER["REQUEST_URI"] . '" method="post" style="display:inline">' . '<input type="hidden" name="epay_transaction_id" value="' . $result["epay_transaction_id"] . '" />' . '<input type="hidden" name="epay_order_id" value="' . $result["id_cart"] . '" />' . $currency_code . ' ' . '<input type="text" id="epay_amount" name="epay_amount" value="' . $epay_amount . '" size="' . strlen($epay_amount) . '" />';
						
						if(!$soap_result->capturedamount or ($soap_result->splitpayment and $soap_result->status != 'PAYMENT_CAPTURED' and ($soap_result->capturedamount != $soap_result->authamount)))
						{
							$html .= ' <input class="button" name="epay_capture" type="submit" value="' . $this->l('Capture') . '" />' . ' <input class="button" name="epay_delete" type="submit" value="' . $this->l('Delete') . '" 
												 		onclick="return confirm(\'' . $this->l('Really want to delete?') . '\');" />';
							if($soap_result->splitpayment)
								$html .= '<br /><input class="button" name="epay_move_as_captured" type="submit" value="' . $this->l('Close transaction') . '" /> ';
							
						}
						elseif($soap_result->status == 'PAYMENT_CAPTURED' OR $soap_result->acquirer == 'EUROLINE')
							$html .= ' <input class="button" name="epay_credit" type="submit" value="' . $this->l('Credit') . '"onclick="return confirm(\'' . $this->l('Do you want to credit:') . ' ' . $currency_code . ' \'+getE(\'epay_amount\').value);" />';
						
						$html .= '</form>';
					}
					else
					{
						$html .= $currency_code . ' ' . $epay_amount;
						$html .= ($soap_result->status == 'PAYMENT_DELETED' ? ' <span style="color:red;font-weight:bold;">' . $this->l('Deleted') . '</span>' : '');
					}
					
					$html .= '<div style="margin-top: 10px;">
								<table class="table" cellspacing="0" cellpadding="0"><tr><th>' . $this->l('Date') . '</th><th>' . $this->l('Event') . '</th></tr>';
					
					$historyArray = $soap_result->history->TransactionHistoryInfo;
					
					if(!array_key_exists(0, $soap_result->history->TransactionHistoryInfo))
					{
						$historyArray = array($soap_result->history->TransactionHistoryInfo);
						// convert to array
					}
					
					for($i = 0; $i < count($historyArray); $i++)
					{
						$html .= "<tr><td>" . str_replace("T", " ", $historyArray[$i]->created) . "</td>";
						$html .= "<td>";
						if(strlen($historyArray[$i]->username) > 0)
						{
							$html .= ($historyArray[$i]->username . ": ");
						}
						$html .= $historyArray[$i]->eventMsg . "</td></tr>";
					}
					
					
					$html .= '</table></div>';
				}
				catch (Exception $e)
				{
					$activate_api = false;
					$this->displayError($e->getMessage());	
				}
				
			}
			
			if(!$activate_api)
				$html .= $currency_code . ' ' . number_format(($result["amount"] + $result["transfee"]) / 100, 2, ",", "");
			
			$html .= '</td></tr></table>
								</fieldset>';
			
			return $html;
		}
	}
	
	function _procesRemote($params)
	{
		if((Tools::isSubmit('epay_capture') OR Tools::isSubmit('epay_move_as_captured') OR Tools::isSubmit('epay_credit') OR Tools::isSubmit('epay_delete')) AND Tools::getIsset('epay_transaction_id'))
		{
			require_once (dirname(__FILE__) . '/api.php');
			
			$api = new EPayApi();
			
			if(Tools::isSubmit('epay_capture'))
				$result = $api->capture(Configuration::get('EPAY_MERCHANTNUMBER'), Tools::getValue('epay_transaction_id'), floatval(Tools::getValue('epay_amount')) * 100);
			elseif(Tools::isSubmit('epay_credit'))
				$result = $api->credit(Configuration::get('EPAY_MERCHANTNUMBER'), Tools::getValue('epay_transaction_id'), floatval(Tools::getValue('epay_amount')) * 100);
			elseif(Tools::isSubmit('epay_delete'))
				$result = $api->delete(Configuration::get('EPAY_MERCHANTNUMBER'), Tools::getValue('epay_transaction_id'));
			elseif(Tools::isSubmit('epay_move_as_captured'))
				$result = $api->moveascaptured(Configuration::get('EPAY_MERCHANTNUMBER'), Tools::getValue('epay_transaction_id'));
			
			if(@$result->captureResult == "true")
				$this->setCaptured(Tools::getValue('epay_transaction_id'), floatval(Tools::getValue('epay_amount')) * 100);
			elseif(@$result->creditResult == "true")
				$this->setCredited(Tools::getValue('epay_transaction_id'), floatval(Tools::getValue('epay_amount')) * 100);
			elseif(@$result->deleteResult == "true")
				$this->deleteTransaction(Tools::getValue('epay_transaction_id'));
			elseif(@$result->move_as_capturedResult == "true")
			{
				//Do nothing
			}
			else
			{
				if(Tools::isSubmit('epay_capture'))
					$pbsresponse = $result->pbsResponse;
				elseif(!Tools::isSubmit('epay_delete') && !Tools::isSubmit('epay_move_as_captured'))
					$pbsresponse = $result->pbsresponse;

				$api->getEpayError(Configuration::get('EPAY_MERCHANTNUMBER'), $result->epayresponse);
				
				if(!Tools::isSubmit('epay_delete') && !Tools::isSubmit('epay_move_as_captured'))
					$api->getPbsError(Configuration::get('EPAY_MERCHANTNUMBER'), $pbsresponse);
			}
			
			return $result;
		}
	}
	
	static function getCardNameById($cardid)
	{
		switch($cardid)
		{
			case 1:
				return 'Dankort / VISA/Dankort';
			case 2:
				return 'eDankort';
			case 3:
				return 'VISA / VISA Electron';
			case 4:
				return 'MasterCard';
			case 6:
				return 'JCB';
			case 7:
				return 'Maestro';
			case 8:
				return 'Diners Club';
			case 9:
				return 'American Express';
			case 10:
				return 'ewire';
			case 12:
				return 'Nordea e-betaling';
			case 13:
				return 'Danske Netbetalinger';
			case 14:
				return 'PayPal';
			case 16:
				return 'MobilPenge';
			case 17:
				return 'Klarna';
			case 18:
				return 'Svea';
		}
		
		return 'Unknown';
	}	
}

?>
