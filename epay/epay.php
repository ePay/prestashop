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
		$this->version = '4.9.0';
		$this->author = "ePay A/S (a Bambora Company)";
		$this->tab = 'payments_gateways';

		$this->currencies = true;
		$this->currencies_mode = 'checkbox';

		parent::__construct();

		if((Configuration::get('EPAY_ENABLE_REMOTE_API') == 1 || Configuration::get('EPAY_ENABLE_PAYMENTREQUEST') == 1) && !class_exists("SOAPClient"))
			$this->warning = $this->l('You must have SoapClient installed to use Remote API. Contact your hosting provider for further information.');

		if(Configuration::get('EPAY_ENABLE_PAYMENTREQUEST') == 1 && strlen(Configuration::get('EPAY_REMOTE_API_PASSWORD')) <= 0)
			$this->warning = $this->l('You must set Remote API password to use payment requests. Remember to set the password in the ePay administration under the menu API / Webservices -> Access.');

		$this->displayName = 'ePay';
		$this->description = $this->l('Accept Dankort, eDankort, VISA, Electron, MasterCard, Maestro, JCB, Diners, AMEX, Nordea and Danske Bank payments by ePay / Payment Solutions');
	}

	public function install()
	{
		if(!parent::install() OR
            !Configuration::updateValue('EPAY_GOOGLE_PAGEVIEW', '0') OR
            !Configuration::updateValue('EPAY_INTEGRATION', '1') OR
            !Configuration::updateValue('EPAY_ENABLE_INVOICE', '0') OR
            !$this->registerHook('payment') OR
            !$this->registerHook('rightColumn') OR
            !$this->registerHook('leftColumn') OR
            !$this->registerHook('adminOrder') OR
            !$this->registerHook('paymentReturn') OR
            !$this->registerHook('footer') OR
            !$this->registerHook('backOfficeHeader') OR
			!$this->registerHook('displayHeader'))
        {
            return false;
        }

		if(!$this->createEPayTransactionTable())
			return false;

		/*
		if(!$this->createEPayPaymentRequestTable())
        return false;
         */

		return true;
	}

	public function uninstall()
	{
		return parent::uninstall();
	}

	private function createEPayTransactionTable()
	{
		$table_name = _DB_PREFIX_ . 'epay_transactions';

		$columns = array
		(
			'id_order' => 'int(10) unsigned NOT NULL',
			'id_cart' => 'int(10) unsigned NOT NULL',
			'epay_transaction_id' => 'int(10) unsigned NOT NULL',
			'epay_orderid' => 'varchar(20) NOT NULL',
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

		//Check the database fields
		foreach ($columns as $column_name => $options)
		{
			if(!$this->mysqlColumnExists($table_name, $column_name))
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

	private static function mysqlColumnExists($table_name, $column_name, $link = false)
	{
		$result = Db::getInstance()->executeS("SHOW COLUMNS FROM $table_name LIKE '$column_name'", $link);

		return (count($result) > 0);
	}

	public function recordTransaction($id_order, $id_cart = 0, $transaction_id = 0, $card_id = 0, $cardnopostfix = 0, $currency = 0, $amount = 0, $transfee = 0, $fraud = 0)
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
				(' . $id_order . ', ' . $id_cart . ', ' . $transaction_id . ', ' . $card_id . ', ' . $cardnopostfix . ', ' . $currency . ', ' . $amount . ', ' . $transfee . ', ' . $fraud . ', ' . $captured . ', NOW() )';

		if(!Db::getInstance()->Execute($query))
			return false;

		return true;
	}

	private function setCaptured($transaction_id, $amount)
	{
		$query = ' UPDATE ' . _DB_PREFIX_ . 'epay_transactions SET `captured` = 1, `amount` = ' . $amount . ' WHERE `epay_transaction_id` = ' . $transaction_id;
		if(!Db::getInstance()->Execute($query))
			return false;
		return true;
	}

	private function setCredited($transaction_id, $amount)
	{
		$query = ' UPDATE ' . _DB_PREFIX_ . 'epay_transactions SET `credited` = 1, `amount` = `amount` - ' . $amount . ' WHERE `epay_transaction_id` = ' . $transaction_id;
		if(!Db::getInstance()->Execute($query))
			return false;
		return true;
	}

	private function deleteTransaction($transaction_id)
	{
		$query = ' UPDATE ' . _DB_PREFIX_ . 'epay_transactions SET `deleted` = 1 WHERE `epay_transaction_id` = ' . $transaction_id;
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
				Configuration::updateValue('EPAY_ENABLE_PAYMENTREQUEST', Tools::getValue("EPAY_ENABLE_PAYMENTREQUEST"));

	            $output .= $this->displayConfirmation($this->l('Settings updated'));
	        }
	    }

	    return $output.$this->displayForm();
	}

	private function displayForm()
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
				),
				array(
					'type' => 'radio',
					'label' => $this->l('Enable payment request'),
					'name' => 'EPAY_ENABLE_PAYMENTREQUEST',
					'size' => 20,
					'class' => 't',
					'is_bool' => true,
					'required' => true,
					'values' => array(
						array(
							'id' => 'paymentrequest_yes',
							'value' => 1,
							'label' => $this->l('Yes')
						),
						array(
							'id' => 'paymentrequest_no',
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

	    // Module, token and currentIndex
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
		$helper->fields_value['EPAY_ENABLE_PAYMENTREQUEST'] = Configuration::get('EPAY_ENABLE_PAYMENTREQUEST');

	    return "<div class=\"warn\"><a href=\"http://www.prestashopguiden.dk/en/configuration#407\" target=\"_blank\">". $this->l('Documentation can be found here') ."<a></div>" . $helper->generateForm($fields_form);
	}

	private function displayPaymentRequestForm($params)
	{
		$order = new Order($params['id_order']);
		$employee = new Employee($this->context->cookie->id_employee);

		// Get default Language
	    $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

	    // Init Fields form array
	    $fields_form[0]['form'] = array(
	        'legend' => array(
	            'title' => $this->l('Create payment request'),
				'image' => $this->_path.'logo_small.gif'
	        ),
	        'input' => array(
				array(
					'type' => 'text',
					'label' => $this->l('Requester name'),
					'name' => 'epay_paymentrequest_requester_name',
					'size' => 20,
					'required' => true
				),
				array(
					'type' => 'textarea',
					'label' => $this->l('Requester comment'),
					'name' => 'epay_paymentrequest_requester_comment',
					'rows' => 3,
					'cols' => 50,
					'required' => false
				),
				array(
					'type' => 'text',
					'label' => $this->l('Recipient name'),
					'name' => 'epay_paymentrequest_recipient_name',
					'size' => 20,
					'required' => true
				),
				array(
					'type' => 'text',
					'label' => $this->l('Recipient e-mail'),
					'name' => 'epay_paymentrequest_recipient_email',
					'size' => 20,
					'required' => true
				),
				array(
					'type' => 'text',
					'label' => $this->l('Reply to name'),
					'name' => 'epay_paymentrequest_replyto_name',
					'size' => 20,
					'required' => true
				),
				array(
					'type' => 'text',
					'label' => $this->l('Reply to e-mail'),
					'name' => 'epay_paymentrequest_replyto_email',
					'size' => 20,
					'required' => true
				),
				array(
					'type' => 'text',
					'label' => $this->l('Amount'),
					'name' => 'epay_paymentrequest_amount',
					'size' => 20,
					'suffix' => Currency::getCurrency($order->id_currency)["iso_code"],
					'required' => true,
					'readonly' => true
				),
	        ),
	        'submit' => array(
	            'title' => $this->l('Send payment request'),
	            'class' => 'button'
	        )
	    );

	    $helper = new HelperForm();

	    $helper->module = $this;
	    $helper->name_controller = $this->name;
	    $helper->token = Tools::getAdminTokenLite('AdminOrders');
	    $helper->currentIndex = AdminController::$currentIndex.'&vieworder&id_order='.$params['id_order'];
	    $helper->identifier = 'id_order';
		$helper->id = $params['id_order'];
		$helper->submit_action = 'sendpaymentrequest';

	    $helper->default_form_language = $default_lang;
	    $helper->allow_employee_form_lang = $default_lang;

	    // Title and toolbar
	    $helper->show_toolbar = false;        // false -> remove toolbar

	    //$helper->submit_action = 'submit'.$this->name.'paymentrequest';

	    // Load current value

	    $helper->fields_value['epay_paymentrequest_requester_name'] = Tools::getValue('epay_paymentrequest_requester_name') ? Tools::getValue('epay_paymentrequest_requester_name') : Configuration::get('PS_SHOP_NAME');
		$helper->fields_value['epay_paymentrequest_requester_comment'] = "";

		$helper->fields_value['epay_paymentrequest_recipient_name'] = $this->context->customer->firstname . ' ' . $this->context->customer->lastname;
		$helper->fields_value['epay_paymentrequest_recipient_email'] = $this->context->customer->email;

		$helper->fields_value['epay_paymentrequest_replyto_name'] = $employee->firstname.' '.$employee->lastname;
		$helper->fields_value['epay_paymentrequest_replyto_email'] = $employee->email;

		$helper->fields_value['epay_paymentrequest_amount'] = number_format(($order->total_paid-$order->getTotalPaid()), 2, ",", "");

	    return $helper->generateForm($fields_form);
	}

	private function transactionInfoTableRow($name, $value)
	{
		$return = '<tr><td style="width: 250px;">' . $name . '</td><td><b>' . $value . '</b></td></tr>';

		return $return;
	}

	private function displayTransactionForm($params)
	{
		$transactions = Db::getInstance()->executeS('
		SELECT o.`id_order`, o.`module`, e.`id_cart`, e.`epay_transaction_id`,
			   e.`card_type`, e.`cardnopostfix`, e.`currency`, e.`amount`, e.`transfee`,
			   e.`fraud`, e.`captured`, e.`credited`, e.`deleted`,
			   e.`date_add`
		FROM ' . _DB_PREFIX_ . 'epay_transactions e
		LEFT JOIN ' . _DB_PREFIX_ . 'orders o ON e.`id_cart` = o.`id_cart`
		WHERE o.`id_order` = ' . intval($params["id_order"]));

		$order = new Order($params['id_order']);
		$employee = new Employee($this->context->cookie->id_employee);

		$return = "";
        $process_remote_result = "";

		/* Process remote capture/credit/delete */
		if(Configuration::get('EPAY_ENABLE_REMOTE_API'))
		{
			require_once(dirname(__FILE__ ) . '/api.php');

			try
			{
				$remote_result = $this->procesRemote($params);
				$process_remote_result = '<div class="alert alert-success" style="width:250px;">';
				if(@$remote_result->captureResult == "true")
					$process_remote_result .= $this->l('Payment captured') . '</div>';
				elseif(@$remote_result->creditResult == "true")
					$process_remote_result .= $this->l('Payment credited') . '</div>';
				elseif(@$remote_result->deleteResult == "true")
					$process_remote_result .= $this->l('Payment deleted') . '</div>';
				elseif(@$remote_result->move_as_capturedResult == "true")
					$process_remote_result .= $this->l('Payment closed') . '</div>';
				else
					$process_remote_result = '';

				$activate_api = true;
			}
			catch(Exception $e)
			{
				$activate_api = false;
				$return .= $this->displayError($e->getMessage());
			}
		}

		// Init Fields form array
		foreach($transactions as $transaction)
		{
			$currency = new Currency(Currency::getIdByIsoCodeNum($transaction["currency"]));
			$currency_code = $currency->iso_code;

			if(isset($transaction["epay_transaction_id"]) && $transaction["module"] == "epay")
			{
				$return .= '
					<br />
						<div class="panel">
							<div class="panel-heading">
                                <img src="../modules/' . $this->name . '/logo_small.gif"/> ePay
							</div>

							<table class="table" cellspacing="0" cellpadding="0">

							' . $this->transactionInfoTableRow($this->l('ePay Administration'), '<a href="https://admin.ditonlinebetalingssystem.dk/admin/login.asp" title="ePay login" target="_blank">' . $this->l('Open') . '</a>') . '
							' . $this->transactionInfoTableRow($this->l('ePay "Order ID"'), $transaction["id_cart"]) .'
                            ' . $this->transactionInfoTableRow($this->l('ePay "Transaction ID"'), $transaction["epay_transaction_id"]);

                if($transaction["fraud"])
                    $return .= $this->transactionInfoTableRow($this->l('Fraud'), '<span style="color:red;font-weight:bold;"><img src="../img/admin/bullet_red.png" />' . $this->l('Suspicious Payment!') . '</span>');

                $paymentTypeColumn .= '<div style="display: flex; align-items: center;"><img src="../modules/' . $this->name . '/img/' . $transaction["card_type"] . '.png" alt="' . $this->getCardNameById(intval($transaction["card_type"])) . '" title="' . $this->getCardNameById(intval($transaction["card_type"])) . '" style="margin-right:3px;"/><div>'.$this->getCardNameById(intval($transaction["card_type"]));

                if($transaction["cardnopostfix"] > 1)
                {
                    $paymentTypeColumn .= '<br/> XXXX XXXX XXXX ' . $transaction["cardnopostfix"];
                }

                $paymentTypeColumn .= '</div></div>';
                $return .= $this->transactionInfoTableRow($this->l('Payment type'), $paymentTypeColumn);

				if(!$activate_api)
                {
                    $return .= $this->transactionInfoTableRow($this->l('Amount') ,$currency_code . ' ' . number_format(($transaction["amount"] + $transaction["transfee"]) / 100, 2, ",", ""));
                }

				$return .= '</table><br>'.$process_remote_result;

				if(Configuration::get('EPAY_ENABLE_REMOTE_API') && $activate_api)
				{
					try
					{
						$api = new EPayApi();
						$soap_result = $api->gettransactionInformation(Configuration::get('EPAY_MERCHANTNUMBER'), $transaction["epay_transaction_id"]);

						if($soap_result)
						{
							if(!$soap_result->capturedamount or $soap_result->capturedamount == $soap_result->authamount)
								$epay_amount = number_format($soap_result->authamount / 100, 2, ",", "");
							elseif($soap_result->status == 'PAYMENT_CAPTURED')
								$epay_amount = number_format(($soap_result->capturedamount) / 100, 2, ",", "");
							else
								$epay_amount = number_format(($soap_result->authamount - $soap_result->capturedamount) / 100, 2, ",", "");

							if($soap_result->status != 'PAYMENT_DELETED' AND !$soap_result->creditedamount)
							{
								$return .= '<form name="epay_remote" action="' . $_SERVER["REQUEST_URI"] . '" method="post" style="display:inline; width: 49%;">' . '<input type="hidden" name="epay_transaction_id" value="' . $transaction["epay_transaction_id"] . '" />' . '<input type="hidden" name="epay_order_id" value="' . $transaction["id_cart"] . '" /><div class="input-group" style="width: 250px;"><div class="input-group-addon">'. $currency_code . '</div><input type="text" id="epay_amount" name="epay_amount" value="' . $epay_amount . '"/></div>';

								if(!$soap_result->capturedamount or ($soap_result->splitpayment and $soap_result->status != 'PAYMENT_CAPTURED' and ($soap_result->capturedamount != $soap_result->authamount)))
								{
									$return .= '<input class="btn btn-success" name="epay_capture" style="margin: 3px 3px 3px 0;" type="submit" value="' . $this->l('Capture') . '" />' . '<input class="btn btn-danger" name="epay_delete" style="margin: 3px 3px 3px 0;" type="submit" value="' . $this->l('Delete') . '"
													 		onclick="return confirm(\'' . $this->l('Really want to delete?') . '\');" />';
									if($soap_result->splitpayment)
										$return .= '<input class="btn btn-warning" style="margin: 3px 3px 3px 0;" name="epay_move_as_captured" type="submit" value="' . $this->l('Close transaction') . '" /> ';

								}
								elseif($soap_result->status == 'PAYMENT_CAPTURED' OR $soap_result->acquirer == 'EUROLINE')
									$return .= ' <input class="btn btn-warning" name="epay_credit" style="margin: 3px 3px 3px 0;" type="submit" value="' . $this->l('Credit') . '"onclick="return confirm(\'' . $this->l('Do you want to credit:') . ' ' . $currency_code . ' \'+getE(\'epay_amount\').value);" />';

								$return .= '</form>';
							}
							else
							{
								$return .= '<div class="input-group" style="width: 250px;"><div class="input-group-addon">'. $currency_code . '</div><input type="text" id="epay_amount" name="epay_amount" disabled value="' . $epay_amount . '"/></div>';
								$return .= ($soap_result->status == 'PAYMENT_DELETED' ? ' <span style="color:red;font-weight:bold;">' . $this->l('Deleted') . '</span>' : '');
							}

							$return .= '</br></br><div class="row"><div class="col-xs-12 col-md-8 col-lg-6 col-sm-12 panel panel-sm"><div class="panel-heading"><i class="icon-time"></i> '.$this->l('Transaction Log').'</div>
									<table class="table" cellspacing="0" cellpadding="0"><thead><tr><th><span class="title_box">' . $this->l('Date') . '</span></th><th><span class="title_box">' . $this->l('Event') . '</span></th></tr><thead><tbody>';

							$historyArray = $soap_result->history->TransactionHistoryInfo;

							if(!array_key_exists(0, $soap_result->history->TransactionHistoryInfo))
							{
								$historyArray = array($soap_result->history->TransactionHistoryInfo);
								// convert to array
							}

							for($i = 0; $i < count($historyArray); $i++)
							{
								$return .= "<tr><td>" . str_replace("T", " ", $historyArray[$i]->created) . "</td>";
								$return .= "<td>";
								if(strlen($historyArray[$i]->username) > 0)
								{
									$return .= ($historyArray[$i]->username . ": ");
								}
								$return .= $historyArray[$i]->eventMsg . "</td></tr>";
							}


							$return .= '</tbody></table></div></div>';
						}
					}
					catch (Exception $e)
					{
						$activate_api = false;
						$this->displayError($e->getMessage());
					}

				}

				$return .= '</div>';
			}
		}

		return $return;
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
		$invoice["customer"]["name"] = $this->removeSpecialCharacters($summary["invoice"]->firstname . ' ' . $summary["invoice"]->lastname);
		$invoice["customer"]["address"] = $this->removeSpecialCharacters($summary["invoice"]->address1);
		$invoice["customer"]["zip"] = intval((string)$summary["invoice"]->postcode);
		$invoice["customer"]["city"] = $this->removeSpecialCharacters($summary["invoice"]->city);
		$invoice["customer"]["country"] = $this->removeSpecialCharacters($summary["invoice"]->country);

		$invoice["shippingaddress"]["name"] = $this->removeSpecialCharacters($summary["delivery"]->firstname . ' ' . $summary["delivery"]->lastname);
		$invoice["shippingaddress"]["address"] = $this->removeSpecialCharacters($summary["delivery"]->address1);
		$invoice["shippingaddress"]["zip"] = intval((string)$summary["delivery"]->postcode);
		$invoice["shippingaddress"]["city"] = $this->removeSpecialCharacters($summary["delivery"]->city);
		$invoice["shippingaddress"]["country"] = $this->removeSpecialCharacters($summary["delivery"]->country);

		$invoice["lines"] = array();

		foreach ($summary["products"] as $product)
		{
			$invoice["lines"][] = array
			(
				"id" => ($product["reference"] == "" ? $product["id_product"] : $product["reference"]),
				"description" => $this->removeSpecialCharacters($product["name"] . ($product["attributes_small"] ? (" " . $product["attributes_small"]) : "")),
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
				"description" => $this->removeSpecialCharacters($discount["description"]),
				"quantity" => 1,
				"price" => -intval(round((string)$discount["value_tax_exc"],2)*100),
				"vat" => (float)round((string)((round($discount["value_real"],2)-round($discount["value_tax_exc"],2))/round((string)$discount["value_tax_exc"],2))*100, 2)
			);
		}
        $json_invoice = json_encode($invoice, JSON_UNESCAPED_UNICODE);
		return $json_invoice;
	}

    function removeSpecialCharacters($value)
    {
        return preg_replace('/[^\p{Latin}\d ]/u', '', $value);
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
        if(key_exists("epay_invoice", $parameters))
        {
            $parameters["epay_invoice"] = htmlspecialchars($parameters["epay_invoice"]);
        }
		$this->context->smarty->assign(array('parameters' => $parameters, 'this_path_epay' => $this->_path));

		if(_PS_VERSION_ >= "1.6.0.0")
			return $this->display(__FILE__ , "payment16.tpl");
		else
			return $this->display(__FILE__ , "payment.tpl");
	}

    public function hookDisplayHeader()
	{
        if ($this->context->controller != null)
		{
			$this->context->controller->addCSS($this->_path.'css/epayStyle.css', 'all');
		}
	}

    public function hookBackOfficeHeader($params) {
        $this->context->controller->addCSS($this->_path.'css/epayStyle.css', 'all');
    }

	function hookFooter($params)
	{
		$output = '';

		if(Configuration::get('EPAY_GOOGLE_PAGEVIEW') == 1 && strlen(Configuration::get('GANALYTICS_ID')) > 0)
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
		$order = new Order($params['id_order']);

		$html = $this->displayTransactionForm($params)	. '<br>';

        if(Configuration::get('EPAY_ENABLE_PAYMENTREQUEST') == 1 && strlen(Configuration::get('EPAY_REMOTE_API_PASSWORD')) > 0 && ($order->total_paid-$order->getTotalPaid()) > 0)
		{
			if(Tools::isSubmit('sendpaymentrequest'))
				$html .= $this->createPaymentRequest(
					$order,
					$params['id_order'],
					Tools::getValue('epay_paymentrequest_amount'),
					Currency::getCurrency($order->id_currency)["iso_code_num"],
					Tools::getValue('epay_paymentrequest_requester_name'),
					Tools::getValue('epay_paymentrequest_requester_comment'),
					Tools::getValue('epay_paymentrequest_recipient_email'),
					Tools::getValue('epay_paymentrequest_recipient_name'),
					Tools::getValue('epay_paymentrequest_replyto_email'),
					Tools::getValue('epay_paymentrequest_replyto_name')
				);

			$html .= $this->displayPaymentRequestForm($params) . '<br>';
		}

		return $html;
	}

	private function createPaymentRequest($order, $orderid, $amount, $currency, $requester, $comment, $recipient_email, $recipient_name, $replyto_email, $replyto_name)
	{
		$return = "";

		try
		{
			$languageIso = Language::getIsoById($this->context->language->id);

			//Get ordernumber
			$sql = 'SELECT COUNT(*) FROM '._DB_PREFIX_.'epay_transactions WHERE `id_order` = ' . intval($orderid);
			$orderPostfix = Db::getInstance()->getValue($sql) + 1;

			$params = array();

			$params["authentication"] = array();
			$params["authentication"]["merchantnumber"] = Configuration::get('EPAY_MERCHANTNUMBER');
			$params["authentication"]["password"] = Configuration::get('EPAY_REMOTE_API_PASSWORD');

			$params["language"] = ($languageIso == "da" ? "da" : "en");

			$params["paymentrequest"] = array();
			$params["paymentrequest"]["reference"] = $orderid;
			$params["paymentrequest"]["closeafterxpayments"] = 1;

			$params["paymentrequest"]["parameters"] = array();
			$params["paymentrequest"]["parameters"]["amount"] = $this->handleUserAmountInput($amount) * 100;
			$params["paymentrequest"]["parameters"]["callbackurl"] = $this->context->link->getModuleLink('epay', 'paymentrequest', array('id_order' => $orderid, 'id_cart' => $order->id_cart), true);
			$params["paymentrequest"]["parameters"]["currency"] = $currency;
			$params["paymentrequest"]["parameters"]["group"] = Configuration::get('EPAY_GROUP');
			$params["paymentrequest"]["parameters"]["instantcapture"] = Configuration::get('EPAY_INSTANTCAPTURE') == "1" ? "automatic" : "manual";
			$params["paymentrequest"]["parameters"]["orderid"] = $orderid . "PAYREQ" . $orderPostfix;
			$params["paymentrequest"]["parameters"]["windowid"] = Configuration::get('EPAY_WINDOWID');

			$soapClient = new SoapClient("https://paymentrequest.api.epay.eu/v1/PaymentRequestSOAP.svc?wsdl");
			$createPaymentRequest = $soapClient->createpaymentrequest(array('createpaymentrequestrequest' => $params));

			if($createPaymentRequest->createpaymentrequestResult->result)
			{
				$sendParams = array();

				$sendParams["authentication"] = $params["authentication"];

				$sendParams["language"] = ($languageIso == "da" ? "da" : "en");

				$sendParams["email"] = array();
				$sendParams["email"]["comment"] = $comment;
				$sendParams["email"]["requester"] = $requester;

				$sendParams["email"]["recipient"] = array();
				$sendParams["email"]["recipient"]["emailaddress"] = $recipient_email;
				$sendParams["email"]["recipient"]["name"] = $recipient_name;

				$sendParams["email"]["replyto"] = array();
				$sendParams["email"]["replyto"]["emailaddress"] = $replyto_email;
				$sendParams["email"]["replyto"]["name"] = $recipient_name;

				$sendParams["paymentrequest"] = array();
				$sendParams["paymentrequest"]["paymentrequestid"] = $createPaymentRequest->createpaymentrequestResult->paymentrequest->paymentrequestid;

				$sendPaymentRequest = $soapClient->sendpaymentrequest(array('sendpaymentrequestrequest' => $sendParams));

				if($sendPaymentRequest->sendpaymentrequestResult->result)
				{
					$message = "Payment request (" . $createPaymentRequest->createpaymentrequestResult->paymentrequest->paymentrequestid . ") created and sent to: " . $recipient_email;

					$msg = new Message();
					$message = strip_tags($message, '<br>');
					if (Validate::isCleanHtml($message))
					{
						$msg->message = $message;
						$msg->id_order = intval($orderid);
						$msg->private = 1;
						$msg->add();
					}

					$return = $this->displayConfirmation($this->l('Payment request is sent.'));
				}
				else
				{
					throw new Exception ($sendPaymentRequest->sendpaymentrequestResult->message);
				}
			}
			else
			{
				throw new Exception ($createPaymentRequest->createpaymentrequestResult->message);
			}
		}
		catch(Exception $e)
		{
			$return = $this->displayError($e->getMessage());
		}

		return $return;
	}

	private function procesRemote($params)
	{
		if((Tools::isSubmit('epay_capture') OR Tools::isSubmit('epay_move_as_captured') OR Tools::isSubmit('epay_credit') OR Tools::isSubmit('epay_delete')) AND Tools::getIsset('epay_transaction_id'))
		{
			require_once (dirname(__FILE__) . '/api.php');

			$api = new EPayApi();

			if(Tools::isSubmit('epay_capture'))
				$result = $api->capture(Configuration::get('EPAY_MERCHANTNUMBER'), Tools::getValue('epay_transaction_id'), $this->handleUserAmountInput(Tools::getValue('epay_amount')) * 100);
			elseif(Tools::isSubmit('epay_credit'))
				$result = $api->credit(Configuration::get('EPAY_MERCHANTNUMBER'), Tools::getValue('epay_transaction_id'), $this->handleUserAmountInput(Tools::getValue('epay_amount')) * 100);
			elseif(Tools::isSubmit('epay_delete'))
				$result = $api->delete(Configuration::get('EPAY_MERCHANTNUMBER'), Tools::getValue('epay_transaction_id'));
			elseif(Tools::isSubmit('epay_move_as_captured'))
				$result = $api->moveascaptured(Configuration::get('EPAY_MERCHANTNUMBER'), Tools::getValue('epay_transaction_id'));

			if(@$result->captureResult == "true")
				$this->setCaptured(Tools::getValue('epay_transaction_id'), $this->handleUserAmountInput(Tools::getValue('epay_amount')) * 100);
			elseif(@$result->creditResult == "true")
				$this->setCredited(Tools::getValue('epay_transaction_id'), $this->handleUserAmountInput(Tools::getValue('epay_amount')) * 100);
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

	static function getCardNameById($card_id)
	{
		switch($card_id)
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
            case 11:
				return 'Forbrugsforeningen';
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
            case 19:
				return 'SEB';
            case 20:
				return 'Nordea';
            case 21:
				return 'Handelsbanken';
            case 22:
				return 'Swedbank';
            case 23:
				return 'ViaBill';
            case 24:
				return 'Beeptify';
            case 25:
				return 'iDEAL';
            case 26:
				return 'Gavekort';
            case 27:
				return 'Paii';
            case 28:
				return 'Brandts Gavekort';
            case 29:
				return 'MobilePay Online';
            case 30:
				return 'Resurs Bank';
            case 31:
				return 'Ekspres Bank';
            case 32:
				return 'Swipp';
		}

		return 'Unknown';
	}

    public static function handleUserAmountInput($price, $currency = null, Context $context = null)
    {
        //  if (!is_float($price)) {
        //     return $price;
        // }
        if (!$context) {
            $context = Context::getContext();
        }
        if ($currency === null) {
            $currency = $context->currency;
        }
        elseif (is_int($currency)) {
            $currency = Currency::getCurrencyInstance((int)$currency);
        }
        if (is_array($currency)) {
            $c_format = $currency['format'];
        } elseif (is_object($currency)) {
            $c_format = $currency->format;
        } else {
            return false;
        }

        $ret = 0;
        /*
         * If the language is RTL and the selected currency format contains spaces as thousands separator
         * then the number will be printed in reverse since the space is interpreted as separating words.
         * To avoid this we replace the currency format containing a space with the one containing a comma (,) as thousand
         * separator when the language is RTL.
         *
         */
        if (($c_format == 2) && ($context->language->is_rtl == 1)) {
            $c_format = 4;
        }
        switch ($c_format) {
            /* X 0,000.00 */
            case 1:
                $ret =  str_replace(',','',$price);
                break;
            /* 0 000,00 X*/
            case 2:
                $ret =  str_replace(' ','', str_replace(',','.',$price));
                break;
            /* X 0.000,00 */
            case 3:
                $temp = str_replace('.','',$price);
                $ret = str_replace(',','.',$temp);
                break;
            /* 0,000.00 X */
            case 4:
                $ret = str_replace(',','',$price);
                break;
            /* X 0'000.00  Added for the switzerland currency */
            case 5:
                $ret = str_replace("'", "", $price);
                break;
        }

        return floatval($ret);
    }
}

?>
