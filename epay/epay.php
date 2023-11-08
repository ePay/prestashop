<?php

/**
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
 */
include 'lib/epayTools.php';
include 'lib/epayApi.php';
include 'lib/epayModels.php';

if (!defined('_PS_VERSION_')) {
    exit;
}

class EPay extends PaymentModule
{
    const MODULE_VERSION = '5.1.2';

    const V15 = '15';

    const V16 = '16';

    const V17 = '17';

    public function __construct()
    {
        $this->name = 'epay';
        $this->version = '5.1.2';
        $this->author = 'Bambora Online';
        $this->tab = 'payments_gateways';

        $this->ps_versions_compliancy = array('min' => '1.5', 'max' => _PS_VERSION_);
        $this->controllers = array(
            'accept',
            'callback',
            'payment',
            'paymentrequest'
        );
        $this->is_eu_compatible = 1;
        $this->bootstrap = true;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = 'Bambora Online ePay';
        $this->description = $this->l(
            'Accept online payments quick and secure by Bambora Online ePay'
        );

        if ((Configuration::get('EPAY_ENABLE_REMOTE_API') == 1 || Configuration::get(
                    'EPAY_ENABLE_PAYMENTREQUEST'
                ) == 1) && (!class_exists('SOAPClient') || !extension_loaded(
                    'soap'
                ))) {
            $this->warning = $this->l(
                'You must have SoapClient installed to use Remote API. Contact your hosting provider for further information.'
            );
        }

        if (Configuration::get('EPAY_ENABLE_PAYMENTREQUEST') == 1 && Tools::strlen(
                Configuration::get('EPAY_REMOTE_API_PASSWORD')
            ) <= 0) {
            $this->warning = $this->l(
                'You must set Remote API password to use payment requests. Remember to set the password in the ePay administration under the menu API / Webservices -> Access.'
            );
        }
    }

    //region Install and Setup

    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('payment')
            || !$this->registerHook('rightColumn')
            || !$this->registerHook('leftColumn')
            || !$this->registerHook('footer')
            || !$this->registerHook('adminOrder')
            || !$this->registerHook('paymentReturn')
            || !$this->registerHook('PDFInvoice')
            || !$this->registerHook('Invoice')
            || !$this->registerHook('backOfficeHeader')
            || !$this->registerHook('displayHeader')
            || !$this->registerHook('actionOrderStatusPostUpdate')
            || !$this->registerHook('displayBackOfficeHeader')
        ) {
            return false;
        }
        if ($this->getPsVersion() === $this::V17) {
            if (!$this->registerHook('paymentOptions')) {
                return false;
            }
        }
        if ($this->getPsVersion() === $this::V17) {
            if (!$this->registerHook('displayAdminOrderSideBottom')) {
                return false;
            }
            if (!$this->registerHook('displayAdminOrderMainBottom')) {
                return false;
            }
        }
        if (!$this->createEPayTransactionTable()) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    private function createEPayTransactionTable()
    {
        $table_name = _DB_PREFIX_ . 'epay_transactions';

        $columns = array(
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
            'date_add' => 'datetime NOT NULL',
        );

        $query = 'CREATE TABLE IF NOT EXISTS `' . $table_name . '` (';

        foreach ($columns as $column_name => $options) {
            $query .= '`' . $column_name . '` ' . $options . ', ';
        }

        $query .= ' PRIMARY KEY (`epay_transaction_id`) )';

        if (!Db::getInstance()->Execute($query)) {
            return false;
        }

        $i = 0;
        $previous_column = '';
        $query = ' ALTER TABLE `' . $table_name . '` ';

        //Check the database fields
        foreach ($columns as $column_name => $options) {
            if (!$this->mysqlColumnExists($table_name, $column_name)) {
                $query .= ($i > 0 ? ', ' : '') . 'ADD `' . $column_name . '` ' . $options . ($previous_column != '' ? ' AFTER `' . $previous_column . '`' : ' FIRST');
                $i++;
            }
            $previous_column = $column_name;
        }

        if ($i > 0) {
            if (!Db::getInstance()->Execute($query)) {
                return false;
            }
        }

        return true;
    }

    private static function mysqlColumnExists($table_name, $column_name)
    {
        try {
            $result = Db::getInstance()->Execute(
                "SELECT {$column_name} FROM {$table_name} ORDER BY {$column_name} LIMIT 1"
            );
            if ($result) {
                return true;
            }
        } catch (Exception $e) {
            //Nothing to do here
        }
        return false;
    }

    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit' . $this->name)) {
            $epay_merchantnumber = (string)Tools::getValue('EPAY_MERCHANTNUMBER');
            $remote_password = (string)Tools::getValue("EPAY_REMOTE_API_PASSWORD");
            if (!$epay_merchantnumber || empty($epay_merchantnumber) || !Validate::isGenericName(
                    $epay_merchantnumber
                )) {
                $output .= $this->displayError(
                    $this->l(
                        'Merchantnumber is required. If you don\'t have one please contact ePay on support@epay.dk in order to obtain one!'
                    )
                );
            } elseif (Configuration::get(
                    'EPAY_ENABLE_PAYMENTREQUEST'
                ) == 1 && (!Validate::isGenericName(
                        $remote_password
                    ) && empty(Configuration::get('EPAY_REMOTE_API_PASSWORD')))) {
                $output .= $this->displayError(
                    $this->l(
                        'You must set Remote API password to use payment requests.'
                    )
                );
            } else {
                Configuration::updateValue(
                    'EPAY_MERCHANTNUMBER',
                    Tools::getValue('EPAY_MERCHANTNUMBER')
                );
                Configuration::updateValue(
                    'EPAY_WINDOWSTATE',
                    Tools::getValue('EPAY_WINDOWSTATE')
                );
                Configuration::updateValue(
                    'EPAY_WINDOWID',
                    Tools::getValue('EPAY_WINDOWID')
                );
                Configuration::updateValue(
                    'EPAY_ENABLE_REMOTE_API',
                    Tools::getValue('EPAY_ENABLE_REMOTE_API')
                );
                if (!empty($remote_password)) {
                    Configuration::updateValue(
                        'EPAY_REMOTE_API_PASSWORD',
                        Tools::getValue('EPAY_REMOTE_API_PASSWORD')
                    );
                }
                Configuration::updateValue(
                    'EPAY_INSTANTCAPTURE',
                    Tools::getValue('EPAY_INSTANTCAPTURE')
                );
                Configuration::updateValue(
                    'EPAY_GROUP',
                    Tools::getValue('EPAY_GROUP')
                );
                Configuration::updateValue(
                    'EPAY_ADDFEETOSHIPPING',
                    Tools::getValue('EPAY_ADDFEETOSHIPPING')
                );
                Configuration::updateValue(
                    'EPAY_MD5KEY',
                    Tools::getValue('EPAY_MD5KEY')
                );
                Configuration::updateValue(
                    'EPAY_OWNRECEIPT',
                    Tools::getValue('EPAY_OWNRECEIPT')
                );
                Configuration::updateValue(
                    'EPAY_ENABLE_INVOICE',
                    Tools::getValue('EPAY_ENABLE_INVOICE')
                );
                Configuration::updateValue(
                    'EPAY_ENABLE_PAYMENTREQUEST',
                    Tools::getValue('EPAY_ENABLE_PAYMENTREQUEST')
                );
                Configuration::updateValue(
                    'EPAY_ENABLE_PAYMENTLOGOBLOCK',
                    Tools::getValue('EPAY_ENABLE_PAYMENTLOGOBLOCK')
                );
                Configuration::updateValue(
                    'EPAY_ONLYSHOWPAYMENTLOGOESATCHECKOUT',
                    Tools::getValue('EPAY_ONLYSHOWPAYMENTLOGOESATCHECKOUT')
                );
                Configuration::updateValue(
                    'EPAY_DISABLE_MOBILE_PAYMENTWINDOW',
                    Tools::getValue('EPAY_DISABLE_MOBILE_PAYMENTWINDOW')
                );
                Configuration::updateValue(
                    'EPAY_CAPTUREONSTATUSCHANGED',
                    Tools::getValue('EPAY_CAPTUREONSTATUSCHANGED')
                );
                Configuration::updateValue(
                    'EPAY_CAPTURE_ON_STATUS',
                    json_encode(Tools::getValue('EPAY_CAPTURE_ON_STATUS'))
                );
                Configuration::updateValue(
                    'EPAY_AUTOCAPTURE_FAILUREEMAIL',
                    Tools::getValue('EPAY_AUTOCAPTURE_FAILUREEMAIL')
                );
                Configuration::updateValue(
                    'EPAY_TITLE',
                    Tools::getValue('EPAY_TITLE')
                );
                Configuration::updateValue(
                    'EPAY_ROUNDING_MODE',
                    Tools::getValue('EPAY_ROUNDING_MODE')
                );

                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        return $output . $this->displayForm();
    }

    private function displayForm()
    {
        // Get default Language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $switch_options = array(
            array('id' => 'active_on', 'value' => 1, 'label' => 'Yes'),
            array('id' => 'active_off', 'value' => 0, 'label' => 'No'),
        );

        $windowstate_options = array(
            array('type' => 1, 'name' => 'Overlay'),
            array('type' => 3, 'name' => 'Fullscreen'),
        );

        $displayPaymentLogoLocation = array(
            array('id_option' => 'left_column', 'name' => 'Left Column'),
            array('id_option' => 'right_column', 'name' => 'Right Column'),
            array('id_option' => 'footer', 'name' => 'Footer'),
            array('id_option' => 'hide', 'name' => 'Hide'),
        );

        $statuses = OrderState::getOrderStates($this->context->language->id);
        $selectCaptureStatus = array();
        foreach ($statuses as $status) {
            $selectCaptureStatus[] = array(
                'key' => $status['id_order_state'],
                'name' => $status['name']
            );
        }

        $rounding_modes = array(
            array('type' => EpayTools::ROUND_DEFAULT, 'name' => 'Default'),
            array('type' => EpayTools::ROUND_UP, 'name' => 'Always up'),
            array('type' => EpayTools::ROUND_DOWN, 'name' => 'Always down'),
        );

        // Init Fields form array
        $fields_form = array();
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => 'Settings',
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => 'Merchant number',
                    'name' => 'EPAY_MERCHANTNUMBER',
                    'size' => 40,
                    'required' => true,
                ),
                array(
                    'type' => 'select',
                    'label' => 'Window state',
                    'name' => 'EPAY_WINDOWSTATE',
                    'required' => true,
                    'options' => array(
                        'query' => $windowstate_options,
                        'id' => 'type',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'text',
                    'label' => 'Payment Window ID',
                    'name' => 'EPAY_WINDOWID',
                    'size' => 40,
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => 'MD5 Key',
                    'name' => 'EPAY_MD5KEY',
                    'size' => 40,
                    'required' => false,
                ),
                array(
                    'type' => 'password',
                    'label' => 'Remote API password',
                    'name' => 'EPAY_REMOTE_API_PASSWORD',
                    'size' => 40,
                    'required' => false,
                ),
                array(
                    'type' => 'text',
                    'label' => 'Group ID',
                    'name' => 'EPAY_GROUP',
                    'size' => 40,
                    'required' => false,
                ),
                array(
                    'type' => 'text',
                    'label' => 'Payment method title',
                    'name' => 'EPAY_TITLE',
                    'size' => 40,
                    'required' => false,
                ),
                array(
                    'type' => 'switch',
                    'label' => 'Enable Remote API',
                    'name' => 'EPAY_ENABLE_REMOTE_API',
                    'is_bool' => true,
                    'required' => false,
                    'values' => $switch_options,
                ),
                array(
                    'type' => 'switch',
                    'label' => 'Use own receipt',
                    'name' => 'EPAY_OWNRECEIPT',
                    'is_bool' => true,
                    'required' => false,
                    'values' => $switch_options,
                ),
                array(
                    'type' => 'switch',
                    'label' => 'Use instant capture',
                    'name' => 'EPAY_INSTANTCAPTURE',
                    'is_bool' => true,
                    'required' => false,
                    'values' => $switch_options,
                ),
                array(
                    'type' => 'switch',
                    'label' => 'Add transaction fee to shipping',
                    'name' => 'EPAY_ADDFEETOSHIPPING',
                    'is_bool' => true,
                    'required' => false,
                    'values' => $switch_options,
                ),
                array(
                    'type' => 'switch',
                    'label' => 'Enable invoice data',
                    'name' => 'EPAY_ENABLE_INVOICE',
                    'class' => 't',
                    'is_bool' => true,
                    'required' => false,
                    'values' => $switch_options,
                ),
                array(
                    'type' => 'switch',
                    'label' => 'Enable payment request',
                    'name' => 'EPAY_ENABLE_PAYMENTREQUEST',
                    'is_bool' => true,
                    'required' => false,
                    'values' => $switch_options,
                ),
                array(
                    'type' => 'switch',
                    'label' => 'Only show payment logos at checkout',
                    'name' => 'EPAY_ONLYSHOWPAYMENTLOGOESATCHECKOUT',
                    'is_bool' => true,
                    'required' => false,
                    'values' => $switch_options,
                ),
                array(
                    'type' => 'switch',
                    'label' => 'Disable Mobile Payment Window',
                    'name' => 'EPAY_DISABLE_MOBILE_PAYMENTWINDOW',
                    'is_bool' => true,
                    'required' => false,
                    'values' => $switch_options,
                ),
                array(
                    'type' => 'switch',
                    'label' => 'Capture payment on status changed',
                    'name' => 'EPAY_CAPTUREONSTATUSCHANGED',
                    'is_bool' => true,
                    'required' => false,
                    'values' => $switch_options,
                ),
                array(
                    'type' => 'select',
                    'label' => 'Capture on status changed to',
                    'name' => 'EPAY_CAPTURE_ON_STATUS[]',
                    'class' => 'chosen',
                    'multiple' => true,
                    'required' => false,
                    'options' => array(
                        'query' => $selectCaptureStatus,
                        'id' => 'key',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'text',
                    'label' => 'Capture on status changed failure e-mail',
                    'name' => 'EPAY_AUTOCAPTURE_FAILUREEMAIL',
                    'size' => 40,
                    'required' => false,
                ),
                array(
                    'type' => 'select',
                    'label' => 'Display payment logo block',
                    'name' => 'EPAY_ENABLE_PAYMENTLOGOBLOCK',
                    'required' => false,
                    'options' => array(
                        'query' => $displayPaymentLogoLocation,
                        'id' => 'id_option',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => 'Rounding mode',
                    'name' => 'EPAY_ROUNDING_MODE',
                    'required' => false,
                    'options' => array(
                        'query' => $rounding_modes,
                        'id' => 'type',
                        'name' => 'name',
                    ),
                ),
            ),
            'submit' => array(
                'title' => 'Save',
                'class' => 'button',
            ),
        );

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName . ' v' . $this->version;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                    '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite(
                        'AdminModules'
                    ),
                'desc' => $this->l('Back to list'),
            ),
        );

        // Load current value
        $helper->fields_value['EPAY_MERCHANTNUMBER'] = Configuration::get(
            'EPAY_MERCHANTNUMBER'
        );
        $helper->fields_value['EPAY_WINDOWSTATE'] = Configuration::get(
            'EPAY_WINDOWSTATE'
        );
        $helper->fields_value['EPAY_WINDOWID'] = Configuration::get('EPAY_WINDOWID');
        $helper->fields_value['EPAY_ENABLE_REMOTE_API'] = Configuration::get(
            'EPAY_ENABLE_REMOTE_API'
        );
        $helper->fields_value['EPAY_REMOTE_API_PASSWORD'] = Configuration::get(
            'EPAY_REMOTE_API_PASSWORD'
        );
        $helper->fields_value['EPAY_OWNRECEIPT'] = Configuration::get(
            'EPAY_OWNRECEIPT'
        );
        $helper->fields_value['EPAY_INSTANTCAPTURE'] = Configuration::get(
            'EPAY_INSTANTCAPTURE'
        );
        $helper->fields_value['EPAY_ADDFEETOSHIPPING'] = Configuration::get(
            'EPAY_ADDFEETOSHIPPING'
        );
        $helper->fields_value['EPAY_GROUP'] = Configuration::get('EPAY_GROUP');
        $helper->fields_value['EPAY_MD5KEY'] = Configuration::get('EPAY_MD5KEY');
        $helper->fields_value['EPAY_ENABLE_INVOICE'] = Configuration::get(
            'EPAY_ENABLE_INVOICE'
        );
        $helper->fields_value['EPAY_ENABLE_PAYMENTREQUEST'] = Configuration::get(
            'EPAY_ENABLE_PAYMENTREQUEST'
        );
        $helper->fields_value['EPAY_ENABLE_PAYMENTLOGOBLOCK'] = Configuration::get(
            'EPAY_ENABLE_PAYMENTLOGOBLOCK'
        );
        $helper->fields_value['EPAY_ONLYSHOWPAYMENTLOGOESATCHECKOUT'] = Configuration::get(
            'EPAY_ONLYSHOWPAYMENTLOGOESATCHECKOUT'
        );
        $helper->fields_value['EPAY_DISABLE_MOBILE_PAYMENTWINDOW'] = Configuration::get(
            'EPAY_DISABLE_MOBILE_PAYMENTWINDOW'
        );
        $helper->fields_value['EPAY_CAPTUREONSTATUSCHANGED'] = Configuration::get(
            'EPAY_CAPTUREONSTATUSCHANGED'
        );
        $helper->fields_value['EPAY_CAPTURE_ON_STATUS[]'] = json_decode(
            Configuration::get('EPAY_CAPTURE_ON_STATUS'),
            true
        );
        $helper->fields_value['EPAY_AUTOCAPTURE_FAILUREEMAIL'] = Configuration::get(
            'EPAY_AUTOCAPTURE_FAILUREEMAIL'
        );
        $helper->fields_value['EPAY_TITLE'] = Configuration::get('EPAY_TITLE');
        $helper->fields_value['EPAY_ROUNDING_MODE'] = Configuration::get(
            'EPAY_ROUNDING_MODE'
        );

        $html = '<div class="row">
                    <div class="col-xs-12 col-sm-12 col-md-7 col-lg-7 ">'
            . $helper->generateForm($fields_form)
            . '</div>
                    <div class="hidden-xs hidden-sm col-md-5 col-lg-5">'
            . $this->buildHelptextForSettings()
            . '</div>
                 </div>'
            . '<div class="row visible-xs visible-sm">
                   <div class="col-xs-12 col-sm-12">'
            . $this->buildHelptextForSettings()
            . '</div>
                   </div>';
        return $html;
    }

    /**
     * Build Help Text For Settings.
     *
     * @return mixed
     */
    private function buildHelptextForSettings()
    {
        $html = '<div class="panel helpContainer">
                        <H3>Help for settings</H3>
                        <p>Detailed description of these settings are to be found <a href="http://www.prestashopguiden.dk/en/configuration#407" target="_blank">here</a>.</p>
                        <br />
                        <div>
                            <H4>Merchant number</H4>
                            <p>The number identifying your ePay merchant account.</p>
                            <p><b>Note: </b>This field is mandatory to enable payments</p>
                        </div>
                        <br />
                        <div>
                            <h4>Window state</h4>
                            <p>Please select if you want the Payment window shown as an overlay or as full screen</p>
                            <p><b>Note: </b>This field is mandatory</p>
                        </div>
                        <br />
                        <div>
                            <h4>Payment Window ID</h4>
                            <p>Choose which version of the payment window to use</p>
                            <p><b>Note: </b>This field is mandatory to enable payments. The default payment window is 1</p>
                        </div>
                        <br />
                        <div>
                            <h4>MD5 Key</h4>
                            <p>The MD5 key is used to stamp data sent between Prestashop and ePay to prevent it from being tampered with.</p>
                            <p><b>Note: </b>The MD5 key is optional but if used here, must be the same as in the ePay administration.</p>
                        </div>
                        <br />
                        <div>
                            <h4>Remote API password</h4>
                            <p>A password that can be configured in the ePay administration for restricting access to the Remote API</p>
                            <p><b>Note: </b>If this is set in the ePay administration, it must also be set here</p>
                        </div>
                        <br />
                        <div>
                            <h4>Group ID</h4>
                            <p>You can divide payments into different groups and limit your ePay users access to specific groups. A group is a name/string. If you do nott want to use groups, simply leave the field empty (default).</p>
                        </div>
                        <br />
                        <div>
                            <h4>Payment method title</h4>
                            <p>The title of the payment method visible to the customers</p>
                        </div>
                        <br />
                        <div>
                            <h4>Enable Remote API</h4>
                            <p>By activating Remote API, you can capture, credit and delete payments from PrestaShop.</p>
                        </div>
                        <br />
                        <div>
                            <h4>Use own reciept</h4>
                            <p>Return directly to the shop when the payment is completed</p>
                        </div>
                        <br />
                        <div>
                            <h4>Use instant capture</h4>
                            <p>By enabling this, the payment is captured immediately. You can only use this setting if the customers receive their goods immediately as well, e.g. downloads or services.</p>
                        </div>
                        <br />
                        <div>
                            <h4>Add transaction fee to shipping</h4>
                            <p>If you put this setting at yes, the transaction fee will be added to the shipping costs.</p>
                        </div>
                        <br />
                        <div>
                            <h4>Enable invoice data</h4>
                            <p>Put this at Yes if you want to offer invoice payments, e.g. through Klarna.</p>
                        </div>
                        <br />
                        <div>
                            <h4>Enable payment request</h4>
                            <p>Enable this if you want to be able to send payment requests to your customers</p>
                        </div>
                        <br />
                        <div>
                            <h4>Only show payment logos at checkout</h4>
                            <p>Disable this to only display payment logos at checkout</p>
                        </div>
                        <br />
                        <div>
                            <h4>Disable the Mobile Payment Window</h4>
                            <p>Disabling the Mobile Payment Window allows the customer to use Klarna when using a mobile device</p>
                        </div>
                        <br />
                        <div>
                            <h4>Capture payment on status changed</h4>
                            <p>Enable this if you want to be able to capture the payment when the order status is changed</p>
                        </div>
                        <br />
                        <div>
                            <h4>Capture on status changed to</h4>
                            <p>Select the status you want to execute the capture operation when changed to</p>
                            <p><b>Note: </b>You must enable <b>Remote API</b> and <b>Capture payment on status changed</b></p>
                        </div>
                        <br />
                        <div>
                            <h4>Capture on status changed failure e-mail</h4>
                            <p>If the Capture fails on status changed an e-mail will be send to this address</p>
                        </div>
                        <br />
                        <div>
                            <h4>Display payment logo block</h4>
                            <p>Control if and where the ePay payment logo block, with the available payment options, is shown</p>
                        </div>
                        <br />
                        <div>
                            <h4>Rounding mode</h4>
                            <p>Please select how you want the rounding of the amount sent to the payment system</p>
                        </div>
                        <br />
                   </div>';

        return $html;
    }

    //endregion

    //region Database actions

    /**
     * Add the transaction to the database.
     *
     * @param mixed $id_order
     * @param mixed $id_cart
     * @param mixed $transaction_id
     * @param mixed $epay_order_id
     * @param mixed $paymentcard_id
     * @param mixed $cardnopostfix
     * @param mixed $currency
     * @param mixed $amount
     * @param mixed $transfee
     * @param mixed $fraud
     *
     * @return bool
     */
    public function addDbTransaction(
        $id_order,
        $id_cart,
        $transaction_id,
        $epay_order_id,
        $paymentcard_id,
        $cardnopostfix,
        $currency,
        $amount,
        $transfee,
        $fraud
    ) {
        $captured = (Configuration::get('EPAY_INSTANTCAPTURE') ? 1 : 0);

        $query = 'INSERT INTO ' . _DB_PREFIX_ . 'epay_transactions
                (id_order, id_cart, epay_transaction_id, epay_orderid, card_type, cardnopostfix, currency, amount, transfee, fraud, captured, date_add)
                VALUES
                (' . pSQL($id_order) . ', ' . pSQL($id_cart) . ', ' . pSQL(
                $transaction_id
            ) . ', \'' . pSQL($epay_order_id) . '\', ' . pSQL(
                $paymentcard_id
            ) . ', ' . pSQL($cardnopostfix) . ', ' . pSQL($currency) . ', ' . pSQL(
                $amount
            ) . ', ' . pSQL($transfee) . ', ' . pSQL($fraud) . ', ' . pSQL(
                $captured
            ) . ', NOW() )';

        return $this->executeDbQuery($query);
    }

    /**
     * Add an orderid to a recorded transaction.
     *
     * @param mixed $transaction_id
     * @param mixed $id_order
     *
     * @return bool
     */
    public function addDbOrderIdToRecordedTransaction($transaction_id, $id_order)
    {
        if (!$transaction_id || !$id_order) {
            return false;
        }

        $query = 'UPDATE ' . _DB_PREFIX_ . 'epay_transactions SET id_order="' . pSQL(
                $id_order
            ) . '" WHERE epay_transaction_id="' . pSQL($transaction_id) . '"';
        return $this->executeDbQuery($query);
    }

    /**
     * Delete a recorded transaction.
     *
     * @param mixed $transaction_id
     *
     * @return bool
     */
    public function deleteDbRecordedTransaction($transaction_id)
    {
        if (!$transaction_id) {
            return false;
        }

        $query = 'DELETE FROM ' . _DB_PREFIX_ . 'epay_transactions WHERE epay_transaction_id="' . pSQL(
                $transaction_id
            ) . '"';
        return $this->executeDbQuery($query);
    }

    /**
     * Get transactions from the database with order id.
     *
     * @param mixed $id_order
     *
     * @return mixed
     */
    private function getDbTransactionsByOrderId($id_order)
    {
        $query = 'SELECT * FROM ' . _DB_PREFIX_ . 'epay_transactions WHERE id_order = ' . pSQL(
                $id_order
            );
        return $this->getDbTransactions($query);
    }

    /**
     * Get transactions from the database with cart id.
     *
     * @param mixed $id_cart
     *
     * @return mixed
     */
    private function getDbTransactionsByCartId($id_cart)
    {
        $query = 'SELECT * FROM ' . _DB_PREFIX_ . 'epay_transactions WHERE id_cart = ' . pSQL(
                $id_cart
            );
        return $this->getDbTransactions($query);
    }

    /**
     * Get db transaction for query.
     *
     * @param mixed $query
     *
     * @return mixed
     */
    private function getDbTransactions($query)
    {
        $transactions = Db::getInstance()->executeS($query);

        if (!isset($transactions) || count(
                $transactions
            ) === 0 || !isset($transactions[0]['epay_transaction_id'])) {
            return false;
        }

        return $transactions[0];
    }

    /**
     * Update database transaction with captured amount.
     *
     * @param mixed $transaction_id
     * @param mixed $amount
     *
     * @return bool
     */
    private function setDbCaptured($transaction_id, $amount)
    {
        $query = ' UPDATE ' . _DB_PREFIX_ . 'epay_transactions SET captured = 1, amount_captured = amount_captured + ' . pSQL(
                $amount
            ) . ' WHERE epay_transaction_id = ' . pSQL($transaction_id);
        return $this->executeDbQuery($query);
    }

    /**
     * Update database transaction with credited amount.
     *
     * @param mixed $transaction_id
     * @param mixed $amount
     *
     * @return bool
     */
    private function setDbCredited($transaction_id, $amount)
    {
        $query = ' UPDATE ' . _DB_PREFIX_ . 'epay_transactions SET credited = 1, amount_credited = amount_credited + ' . pSQL(
                $amount
            ) . ' WHERE epay_transaction_id = ' . pSQL($transaction_id);

        return $this->executeDbQuery($query);
    }

    /**
     * Delete a transaction.
     *
     * @param mixed $transaction_id
     *
     * @return bool
     */
    private function deleteDbTransaction($transaction_id)
    {
        $query = ' UPDATE ' . _DB_PREFIX_ . 'epay_transactions SET deleted = 1 WHERE epay_transaction_id = ' . pSQL(
                $transaction_id
            );

        return $this->executeDbQuery($query);
    }

    /**
     * Execute database query.
     *
     * @param mixed $query
     *
     * @return bool
     */
    private function executeDbQuery($query)
    {
        try {
            if (!Db::getInstance()->Execute($query)) {
                return false;
            }
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    //endregion

    //region Hooks

    /**
     * Hook payment options for Prestashop before 1.7.
     *
     * @param mixed $params
     *
     * @return mixed
     */
    public function hookPayment($params)
    {
        if (!$this->active) {
            return;
        }

        if (!$this->checkCurrency($this->context->cart)) {
            return;
        }

        $epayPaymentWindowRequest = $this->createPaymentWindowRequest();

        $paymentWindowJsUrl = 'https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/paymentwindow.js';
        $callToActionText = Tools::strlen(
            Configuration::get('EPAY_TITLE')
        ) > 0 ? Configuration::get('EPAY_TITLE') : 'Bambora Online ePay';

        $paymentData = array(
            'epayPaymentWindowJsUrl' => $paymentWindowJsUrl,
            'epayPaymentWindowRequest' => json_encode($epayPaymentWindowRequest),
            'epayMerchant' => $epayPaymentWindowRequest['epay_merchantnumber'],
            'epayPaymentTitle' => $callToActionText,
            'thisPathEpay' => $this->_path,
        );

        $this->context->smarty->assign($paymentData);

        if ($this->getPsVersion() === $this::V16) {
            return $this->display(__FILE__, 'payment16.tpl');
        } else {
            return $this->display(__FILE__, 'payment.tpl');
        }
    }

    /**
     * Hook payment options for Prestashop 1.7.
     *
     * @param mixed $params
     *
     * @return PrestaShop\PrestaShop\Core\Payment\PaymentOption[]
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }
        $cart = $params['cart'];
        if (!$this->checkCurrency($cart)) {
            return;
        }

        $paymentInfoData = array(
            'merchantNumber' => Configuration::get('EPAY_MERCHANTNUMBER'),
            'onlyShowLogoes' => Configuration::get(
                'EPAY_ONLYSHOWPAYMENTLOGOESATCHECKOUT'
            ),
        );
        $this->context->smarty->assign($paymentInfoData);

        $callToActionText = Tools::strlen(
            Configuration::get('EPAY_TITLE')
        ) > 0 ? Configuration::get('EPAY_TITLE') : 'Bambora Online ePay';

        $epayPaymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $epayPaymentOption->setCallToActionText($callToActionText)
            ->setAction(
                $this->context->link->getModuleLink(
                    $this->name,
                    'payment',
                    array(),
                    true
                )
            )
            ->setAdditionalInformation(
                $this->context->smarty->fetch(
                    'module:epay/views/templates/front/paymentinfo.tpl'
                )
            );

        $paymentOptions = array();
        $paymentOptions[] = $epayPaymentOption;

        return $paymentOptions;
    }

    /**
     * Add payment information to the order confirmation page.
     *
     * @param mixed $params
     *
     * @return mixed
     */
    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }

        $order = null;
        if ($this->getPsVersion() === $this::V17) {
            $order = $params['order'];
        } else {
            $order = $params['objOrder'];
        }

        $transaction = $this->getDbTransactionsByOrderId($order->id);

        if (!$transaction) {
            $transaction = $this->getDbTransactionsByCartId($order->id_cart);
            if (!$transaction || !$transaction['epay_transaction_id']) {
                return '';
            }
        }

        $transactionId = $transaction['epay_transaction_id'];
        $cardNoPostFix = $transaction['cardnopostfix'];

        $this->context->smarty->assign(
            'epay_completed_paymentText',
            $this->l('You completed your payment.')
        );
        $this->context->smarty->assign(
            'epay_completed_transactionText',
            $this->l('Your transaction ID for this payment is:')
        );
        $this->context->smarty->assign(
            'epay_completed_transactionValue',
            $transactionId
        );

        $cardNoPostFixFormated = "XXXX XXXX XXXX {$cardNoPostFix}";
        $this->context->smarty->assign(
            'epay_completed_cardNoPostFixText',
            $this->l('The transaction was made with card:')
        );
        $this->context->smarty->assign(
            'epay_completed_cardNoPostFixValue',
            $cardNoPostFixFormated
        );

        $customer = new Customer($order->id_customer);
        $this->context->smarty->assign(
            'epay_completed_emailText',
            !empty($customer->email) ? $this->l(
                'An confirmation email has been sent to:'
            ) : ''
        );
        $this->context->smarty->assign(
            'epay_completed_emailValue',
            !empty($customer->email) ? $customer->email : ''
        );

        return $this->display(__FILE__, 'views/templates/front/payment_return.tpl');
    }

    /**
     * Create ePay transaction overview and actions.
     *
     * @param mixed $params
     *
     * @return string
     */
    public function hookAdminOrder($params)
    {
        $html = '';
        $order = new Order($params['id_order']);
        if (isset($order) && $order->module == $this->name) {
            $html = '<div id="epay_admin_order">';
            $html .= '<script type="text/javascript" src="' . $this->_path . 'views/js/epayScripts.js" charset="UTF-8"></script>';

            if (Configuration::get('EPAY_ENABLE_REMOTE_API') == 1) {
                $epayUiMessage = $this->processRemote();
                if (isset($epayUiMessage)) {
                    $html .= $this->buildOverlayMessage($epayUiMessage);
                }
            }

            $html .= $this->buildTransactionForm($order);
            $html .= '<br>';

            if (Configuration::get(
                    'EPAY_ENABLE_PAYMENTREQUEST'
                ) == 1 && Configuration::get('EPAY_ENABLE_REMOTE_API') == 1) {
                $containPaymentWithTransactionId = false;
                $payments = $order->getOrderPayments();
                foreach ($payments as $payment) {
                    if (!empty($payment->transaction_id)) {
                        $containPaymentWithTransactionId = true;
                        break;
                    }
                }

                if (!$containPaymentWithTransactionId) {
                    $html .= '<div class="card-header"> ';
                    $html .= '<h3 class="card-header">Bambora Online ePay Payment Request</h3>';
                    $html .= '<div class="card-body"> ';
                    if (Tools::isSubmit('sendpaymentrequest')) {
                        $html .= $this->createPaymentRequest($order);
                    }

                    $html .= $this->displayPaymentRequestForm($params) . '<br>';
                    $html .= "</div></div>";
                }
            }
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Adds epayFront.css to the Frontoffice header.
     */
    public function hookDisplayHeader()
    {
        if ($this->context->controller != null) {
            $this->context->controller->addCSS(
                $this->_path . 'views/css/epayFront.css',
                'all'
            );
        }
    }

    /**
     * Adds epayAdmin.css to the Backoffice header.
     *
     * @param mixed $params
     */
    public function hookBackOfficeHeader($params)
    {
        if ($this->context->controller != null) {
            $this->context->controller->addCSS(
                $this->_path . 'views/css/epayAdmin.css',
                'all'
            );
        }
    }

    /**
     * Adds epayAdmin.css to the Backoffice header.
     *
     * @param mixed $params
     */
    public function hookDisplayBackOfficeHeader($params)
    {
        $this->hookBackOfficeHeader($params);
    }

    /**
     * Show epay payment block in the footer.
     *
     * @param mixed $params
     *
     * @return mixed
     */
    public function hookFooter($params)
    {
        $output = '';

        if (Configuration::get('EPAY_ENABLE_PAYMENTLOGOBLOCK') === 'footer') {
            $merchantnumber = Configuration::get('EPAY_MERCHANTNUMBER');

            $this->context->smarty->assign(array('merchantnumber' => $merchantnumber)
            );

            $output .= $this->display(__FILE__, 'blockepaymentlogo.tpl');
        }

        return $output;
    }

    /**
     * Show epay payment block in the left column.
     *
     * @param mixed $params
     *
     * @return mixed
     */
    public function hookLeftColumn($params)
    {
        if (Configuration::get('EPAY_ENABLE_PAYMENTLOGOBLOCK') === 'left_column') {
            $merchantnumber = Configuration::get('EPAY_MERCHANTNUMBER');

            $this->context->smarty->assign(array('merchantnumber' => $merchantnumber)
            );

            return $this->display(__FILE__, 'blockepaymentlogo.tpl');
        }
    }

    /**
     * Show epay payment block in the left column.
     *
     * @param mixed $params
     *
     * @return mixed
     */
    public function hookRightColumn($params)
    {
        if (Configuration::get('EPAY_ENABLE_PAYMENTLOGOBLOCK') === 'right_column') {
            $merchantnumber = Configuration::get('EPAY_MERCHANTNUMBER');

            $this->context->smarty->assign(array('merchantnumber' => $merchantnumber)
            );

            return $this->display(__FILE__, 'blockepaymentlogo.tpl');
        }
    }

    /**
     * Try to capture the payment when the status of the order is changed.
     *
     * @param mixed $params
     *
     * @return void|null
     *
     * @throws Exception
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        if (Configuration::get(
                'EPAY_CAPTUREONSTATUSCHANGED'
            ) == 1 && Configuration::get('EPAY_ENABLE_REMOTE_API') == 1) {
            try {
                $newOrderStatus = $params['newOrderStatus'];
                $order = new Order($params['id_order']);
                $allowedOrderStatuses = json_decode(
                    Configuration::get('EPAY_CAPTURE_ON_STATUS'),
                    true
                );
                if (is_array(
                        $allowedOrderStatuses
                    ) && $order->module == $this->name && in_array(
                        $newOrderStatus->id,
                        $allowedOrderStatuses
                    )) {
                    $transactions = Db::getInstance()->executeS(
                        '
                        SELECT o.`id_order`, o.`module`, e.`id_cart`, e.`epay_transaction_id`,
		                e.`card_type`, e.`cardnopostfix`, e.`currency`, e.`amount`, e.`transfee`,
		                e.`fraud`, e.`captured`, e.`credited`, e.`deleted`,
		                e.`date_add`
                        FROM ' . _DB_PREFIX_ . 'epay_transactions e
                        LEFT JOIN ' . _DB_PREFIX_ . 'orders o ON e.`id_cart` = o.`id_cart`
                        WHERE o.`id_order` = ' . (int)($params['id_order'])
                    );

                    if (!isset($transactions) || count($transactions) === 0) {
                        return '';
                    }
                    $transaction = $transactions[0];
                    $pwd = Configuration::get('EPAY_REMOTE_API_PASSWORD');
                    $api = new EPayApi($pwd);
                    $merchantNumber = Configuration::get('EPAY_MERCHANTNUMBER');

                    $amount = ((string)($transaction['amount'] + $transaction['transfee']));
                    $transactionId = $transaction['epay_transaction_id'];
                    $captureResponse = $api->capture(
                        $merchantNumber,
                        $transactionId,
                        $amount
                    );

                    if (!$captureResponse->captureResult) {
                        $errorMessage = $this->getApiErrorMessage(
                            $api,
                            $merchantNumber,
                            $captureResponse
                        );
                        throw new Exception(
                            $this->l('Capture failed: ') . $errorMessage
                        );
                    }

                    $message = 'Autocapture was successfull';
                    $this->createStatusChangesMessage($params['id_order'], $message);
                }
            } catch (Exception $e) {
                $message = 'Autocapture failed with message: ' . $e->getMessage();
                $this->createStatusChangesMessage($params['id_order'], $message);
                $id_lang = (int)$this->context->language->id;
                $dir_mail = __DIR__ . '/mails/';
                $mailTo = Configuration::get('EPAY_AUTOCAPTURE_FAILUREEMAIL');
                Mail::Send(
                    $id_lang,
                    'autocapturefailed',
                    'Auto capture of ' . $params['id_order'] . ' failed',
                    array('{message}' => $e->getMessage()),
                    $mailTo,
                    null,
                    null,
                    null,
                    null,
                    null,
                    $dir_mail
                );
            }
        }

        return '';
    }

    //endregion

    //region Frontoffice Methodes

    /**
     * Create ePay payment window request.
     *
     * @return array
     */
    public function createPaymentWindowRequest()
    {
        $parameters = array();
        $parameters['epay_encoding'] = 'UTF-8';
        $parameters['epay_merchantnumber'] = Configuration::get(
            'EPAY_MERCHANTNUMBER'
        );
        $parameters['epay_cms'] = EpayTools::getModuleHeaderInfo();
        $parameters['epay_windowstate'] = Configuration::get('EPAY_WINDOWSTATE');

        if (Configuration::get('EPAY_WINDOWID')) {
            $parameters['epay_windowid'] = Configuration::get('EPAY_WINDOWID');
        } else {
            $parameters['epay_windowid'] = 1;
        }

        $parameters['epay_instantcapture'] = Configuration::get(
            'EPAY_INSTANTCAPTURE'
        );
        if (Configuration::get('EPAY_GROUP')) {
            $parameters['epay_group'] = Configuration::get('EPAY_GROUP');
        }
        $currency = $this->context->currency->iso_code;
        $parameters['epay_ownreceipt'] = Configuration::get('EPAY_OWNRECEIPT');
        $parameters['epay_currency'] = $currency;
        $parameters['epay_language'] = EpayTools::getEPayLanguage(
            Language::getIsoById($this->context->language->id)
        );
        $parameters['mobile'] = Configuration::get(
            'EPAY_DISABLE_MOBILE_PAYMENTWINDOW'
        ) === '1' ? 0 : 1;
        $minorunits = EpayTools::getCurrencyMinorunits($currency);
        $amount = $this->context->cart->getOrderTotal();
        $amountInMinorunits = EpayTools::convertPriceToMinorUnits(
            $amount,
            $minorunits,
            Configuration::get('EPAY_ROUNDING_MODE')
        );

        $parameters['epay_amount'] = $amountInMinorunits;
        $parameters['epay_orderid'] = $this->context->cart->id;
        $parameters['epay_accepturl'] = $this->context->link->getModuleLink(
            $this->name,
            'accept',
            array(),
            true
        );
        $parameters['epay_cancelurl'] = $this->context->link->getPageLink(
            'order',
            true,
            null,
            'step=3'
        );
        $parameters['epay_callbackurl'] = $this->context->link->getModuleLink(
            $this->name,
            'callback',
            array(),
            true
        );
        $parameters['instantcallback'] = 0;

        if (Configuration::get('EPAY_ENABLE_INVOICE')) {
            $parameters['epay_invoice'] = $this->createInvoiceData($currency);
        }

        $hash = '';
        foreach ($parameters as $value) {
            $hash .= $value;
        }
        $md5Key = Configuration::get('EPAY_MD5KEY');
        $parameters['epay_hash'] = md5($hash . $md5Key);

        return $parameters;
    }

    /**
     * Collect and create Invoice data.
     *
     * @param mixed $currency
     *
     * @return string
     */
    private function createInvoiceData($currency)
    {
        $cartSummary = $this->context->cart->getSummaryDetails();
        $customer = $this->context->customer;

        $invoice = array();
        $invoice['customer']['email'] = $customer->email;
        $invoice['customer']['name'] = $this->removeSpecialCharacters(
            $cartSummary['invoice']->firstname . ' ' . $cartSummary['invoice']->lastname
        );
        $invoice['customer']['address'] = $this->removeSpecialCharacters(
            $cartSummary['invoice']->address1
        );
        $invoice['customer']['zip'] = (int)$cartSummary['invoice']->postcode;
        $invoice['customer']['city'] = $this->removeSpecialCharacters(
            $cartSummary['invoice']->city
        );
        $invoice['customer']['country'] = $this->removeSpecialCharacters(
            $cartSummary['invoice']->country
        );
        if ($cartSummary['is_virtual_cart'] === 0) {
            $invoice['shippingaddress']['name'] = $this->removeSpecialCharacters(
                $cartSummary['delivery']->firstname . ' ' . $cartSummary['delivery']->lastname
            );
            $invoice['shippingaddress']['address'] = $this->removeSpecialCharacters(
                $cartSummary['delivery']->address1
            );
            $invoice['shippingaddress']['zip'] = (int)$cartSummary['delivery']->postcode;
            $invoice['shippingaddress']['city'] = $this->removeSpecialCharacters(
                $cartSummary['delivery']->city
            );
            $invoice['shippingaddress']['country'] = $this->removeSpecialCharacters(
                $cartSummary['delivery']->country
            );
        }
        $minorunits = EpayTools::getCurrencyMinorunits($currency);
        $roundingMode = Configuration::get('EPAY_ROUNDING_MODE');
        $invoice['lines'] = array();

        //Add Products
        $products = $cartSummary['products'];
        foreach ($products as $product) {
            $invoice['lines'][] = array(
                'id' => $product['id_product'],
                'description' => $this->removeSpecialCharacters($product['name']),
                'quantity' => (int)$product['cart_quantity'],
                'price' => EpayTools::convertPriceToMinorUnits(
                    $product['price'],
                    $minorunits,
                    $roundingMode
                ),
                'vat' => $product['rate'],
            );
        }

        //Gift Wrapping
        $wrappingTotal = $cartSummary['total_wrapping'];
        if ($wrappingTotal > 0) {
            $wrappingTotalWithOutTax = $cartSummary['total_wrapping_tax_exc'];
            $wrappingTotalTax = $wrappingTotal - $wrappingTotalWithOutTax;
            $invoice['lines'][] = array(
                'id' => $this->l('wrapping'),
                'description' => $this->l('Gift wrapping'),
                'quantity' => 1,
                'price' => EpayTools::convertPriceToMinorUnits(
                    $wrappingTotalWithOutTax,
                    $minorunits,
                    $roundingMode
                ),
                'vat' => round($wrappingTotalTax / $wrappingTotalWithOutTax * 100),
            );
        }
        //Add shipping as an orderline
        $shippingCostWithTax = $cartSummary['total_shipping'];
        if ($shippingCostWithTax > 0) {
            $shippingCostWithoutTax = $cartSummary['total_shipping_tax_exc'];
            $carrier = $cartSummary['carrier'];
            $shippingTax = $shippingCostWithTax - $shippingCostWithoutTax;
            $invoice['lines'][] = array(
                'id' => $carrier->id_reference,
                'description' => $this->removeSpecialCharacters(
                    "{$carrier->name} - {$carrier->delay}"
                ),
                'quantity' => 1,
                'price' => EpayTools::convertPriceToMinorUnits(
                    $shippingCostWithoutTax,
                    $minorunits,
                    $roundingMode
                ),
                'vat' => round($shippingTax / $shippingCostWithoutTax * 100),
            );
        }

        //Discount
        $discountTotal = $cartSummary['total_discounts'];
        if ($discountTotal > 0) {
            $discountTotalWithOutTax = $cartSummary['total_discounts_tax_exc'];
            $discountTotalTax = $discountTotal - $discountTotalWithOutTax;
            $invoice['lines'][] = array(
                'id' => $this->l('discount'),
                'description' => $this->l('Discount'),
                'quantity' => 1,
                'price' => EpayTools::convertPriceToMinorUnits(
                        $discountTotalWithOutTax,
                        $minorunits,
                        $roundingMode
                    ) * -1,
                'vat' => round($discountTotalTax / $discountTotalWithOutTax * 100),
            );
        }

        $json_invoice = json_encode($invoice, JSON_UNESCAPED_UNICODE);
        return $json_invoice;
    }

    /**
     * Remove special characters from a string.
     *
     * @param string $value
     *
     * @return mixed
     */
    private function removeSpecialCharacters($value)
    {
        return preg_replace('/[^\p{Latin}\d ]/u', '', $value);
    }

    /**
     * Check if currency is allowed.
     *
     * @param mixed $cart
     *
     * @return bool
     */
    private function checkCurrency($cart)
    {
        $currency_order = new Currency((int)($cart->id_currency));
        $currencies_module = $this->getCurrency((int)$cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    //endregion

    //region Backoffice Methodes

    /**
     * Summary of buildTransactionForm.
     *
     * @param Order $order
     *
     * @return string
     */
    private function buildTransactionForm($order)
    {
        $html = $this->buildTransactionFormStart();
        $html .= $this->buildTransactionFormBody($order);
        $html .= $this->buildTransactionFormEnd();

        return $html;
    }

    /**
     * Build the start html for the TransactionForm.
     *
     * @return string
     */
    private function buildTransactionFormStart()
    {
        $html = '';
        if ($this->getPsVersion() === $this::V15) {
            $html .= '<style type="text/css">.table td{white-space:nowrap;overflow-x:auto;}</style>';
            $html .= '<br /><fieldset><legend><img src="../img/admin/money.gif">Bambora Online ePay</legend>';
        } else {
            $html .= '<div class="row" >';
            $html .= '<div class="col-lg-12">';
            $html .= '<div class="panel epay_widthAllSpace">';
        }

        if ($this->isPsVersionHigherThan177()) {
            $html = '<div class="card mt-2"><div class="card-header"> <h3 class="card-header-title">';

            $html .= "Bambora Online ePay";

            $html .= '</h3></div>';
        } else {
            $html .= '<div class="panel-heading epay_admin_heading">';
            $html .= '<img src="../modules/' . $this->name . '/views/img/logo_small.png" />';
            $html .= '<span>Bambora Online ePay</span></div>';
        }

        return $html;
    }

    /**
     * Build the content html for the TransactionForm.
     *
     * @param mixed $order
     *
     * @return string
     */
    private function buildTransactionFormBody($order)
    {
        $html = '';

        $transaction = $this->getDbTransactionsByOrderId($order->id);

        if (!$transaction) {
            $transaction = $this->getDbTransactionsByCartId($order->id_cart);
        }

        if (!$transaction) {
            $html .= '<div class=card-body"><div class="info-block"> No payment transaction was found</div></div>';
            return $html;
        }
        if ($this->isPsVersionHigherThan177()) {
            $html .= '<div class="card-body"><div>';
        } else {
            $html .= '<div class="row">';
            $html .= '<div class="col-xs-12 col-sm-12 col-md-6 col-lg-4">';
        }

        $html .= $this->buildTransactionFormBodyStart($order, $transaction);

        if (Configuration::get('EPAY_ENABLE_REMOTE_API') == 1) {
            $pwd = Configuration::get('EPAY_REMOTE_API_PASSWORD');
            $api = new EPayApi($pwd);
            $transaction = $api->gettransactionInformation(
                Configuration::get('EPAY_MERCHANTNUMBER'),
                $transaction['epay_transaction_id']
            );

            if (!$transaction) {
                $html .= $this->buildTransactionFormBodyNoApiAccessEnd();
            } else {
                $html .= $this->buildTransactionFormBodyActions(
                    $transaction,
                    $order
                );
            }
        } else {
            $html .= $this->buildTransactionFormBodyNoApiAccessEnd();
        }

        $html .= '<div class="col-lg-3 text-center hidden-xs hidden-sm hidden-md">';
        $html .= $this->buildLogodiv();
        $html .= '</div></div>';

        return $html;
    }

    /**
     * If Remote Api is disabled or if the transaction could not be found in the epay system.
     *
     * @return string
     */
    private function buildTransactionFormBodyNoApiAccessEnd()
    {
        $html = '</table>';
        $html .= '</div>';
        $html .= '<div class="col-lg-4 text-center hidden-xs hidden-sm hidden-md"></div>';
        return $html;
    }

    /**
     * Build the start of the Transaction Form Body.
     *
     * @param mixed $order
     * @param string $transactionId
     *
     * @return string
     */
    private function buildTransactionFormBodyStart($order, $transaction)
    {
        $html = '';

        if ($transaction) {
            $transactionId = $transaction['epay_transaction_id'];
            $ePayOrderId = $transaction['epay_orderid'];
            $fraud = $transaction['fraud'];
            $cardTypeId = $transaction['card_type'];
            $cardNo = $transaction['cardnopostfix'];
            $authorizedAmountInMinorunits = $transaction['amount'] + $transaction['transfee'];

            $html .= '<table class="table" cellspacing="0" cellpadding="0">';
            $html .= $this->transactionInfoTableRow(
                $this->l('ePay Administration'),
                '<a href="https://admin.ditonlinebetalingssystem.dk/admin/login.asp" title="ePay login" target="_blank">' . $this->l(
                    'Open'
                ) . '</a>'
            );
            $html .= $this->transactionInfoTableRow(
                $this->l('ePay Order ID'),
                $ePayOrderId
            );
            $html .= $this->transactionInfoTableRow(
                $this->l('ePay Transaction ID'),
                $transactionId
            );

            if ($fraud) {
                $html .= $this->transactionInfoTableRow(
                    $this->l('Fraud'),
                    '<span class="epay_fraud"><img src="../img/admin/bullet_red.png" />' . $this->l(
                        'Suspicious Payment!'
                    ) . '</span>'
                );
            }

            $cardName = EpayTools::getCardNameById((int)$cardTypeId);
            $paymentTypeNameHtml = '<div>' . $cardName;
            if ($cardNo) {
                if (Tools::strlen($cardNo) === 4) {
                    $paymentTypeNameHtml .= '<br /> ' . str_replace(
                            'X',
                            '&bull;',
                            'XXXX XXXX XXXX '
                        ) . $cardNo;
                } else {
                    $paymentTypeNameHtml .= '<br /> ' . str_replace(
                            'X',
                            '&bull;',
                            $cardNo
                        );
                }
            }
            $paymentTypeNameHtml .= '</div>';
            $paymentTypeIconHtml = '<img src="https://d25dqh6gpkyuw6.cloudfront.net/paymentlogos/external/' . $cardTypeId . '.png" alt="' . $cardName . '" title="' . $cardName . '" />';

            $paymentTypeColumn = '<div class="epay_paymenttype">' . $paymentTypeNameHtml . $paymentTypeIconHtml . '</div>';

            $html .= $this->transactionInfoTableRow(
                $this->l('Payment type'),
                $paymentTypeColumn
            );

            $currency = new Currency($order->id_currency);
            $currencyIsoCode = $currency->iso_code;
            $minorunits = EpayTools::getCurrencyMinorunits($currencyIsoCode);
            $authorizedAmount = EpayTools::convertPriceFromMinorUnits(
                $authorizedAmountInMinorunits,
                $minorunits
            );

            $html .= $this->transactionInfoTableRow(
                $this->l('Authorized amount'),
                Tools::displayPrice($authorizedAmount)
            );
        }

        return $html;
    }

    /**
     * Build transaction action controls.
     *
     * @param mixed $epayTransaction
     *
     * @return string
     */
    private function buildTransactionFormBodyActions($epayTransaction, $order)
    {
        $html = '';
        try {
            $currency = new Currency($order->id_currency);
            $currencyIsoCode = $currency->iso_code;
            $minorunits = EpayTools::getCurrencyMinorunits($currencyIsoCode);

            $capturedAmount = EpayTools::convertPriceFromMinorUnits(
                $epayTransaction->capturedamount,
                $minorunits
            );
            $html .= $this->transactionInfoTableRow(
                $this->l('Captured amount'),
                Tools::displayPrice($capturedAmount)
            );
            $creditedAmount = EpayTools::convertPriceFromMinorUnits(
                $epayTransaction->creditedamount,
                $minorunits
            );
            $html .= $this->transactionInfoTableRow(
                $this->l('Credited amount'),
                Tools::displayPrice($creditedAmount)
            );
            $html .= '</table>';

            $html .= $this->buildButtonsForm($epayTransaction, $currencyIsoCode);

            $html .= '</div>';

            $html .= '<div class="col-xs-12 col-sm-12 col-md-6 col-lg-5">';
            $html .= '<div class="epay_table_title">';

            $html .= '<i class="icon-time"></i><b> ' . $this->l(
                    'Transaction Log'
                ) . '</b></div><br />';
            $html .= '<table class="table epay_table" cellspacing="0" cellpadding="0">';
            $html .= '<thead><tr><th><span class="title_box">' . $this->l(
                    'Date'
                ) . '</span></th>';
            $html .= '<th><span class="title_box">' . $this->l(
                    'Event'
                ) . '</span></th>';
            $html .= '</tr><thead><tbody>';

            $historyArray = $epayTransaction->history->TransactionHistoryInfo;

            if (!array_key_exists(
                0,
                $epayTransaction->history->TransactionHistoryInfo
            )) {
                $historyArray = array($epayTransaction->history->TransactionHistoryInfo);
                // convert to array
            }

            for ($i = 0; $i < count($historyArray); $i++) {
                $html .= '<tr><td>' . str_replace(
                        'T',
                        ' ',
                        $historyArray[$i]->created
                    ) . '</td>';
                $html .= '<td>';
                if (Tools::strlen($historyArray[$i]->username) > 0) {
                    $html .= ($historyArray[$i]->username . ': ');
                }
                $html .= $historyArray[$i]->eventMsg . '</td></tr>';
            }
            $html .= '</tbody></table>';
            $html .= '</div>';
        } catch (Exception $e) {
            $this->displayError($e->getMessage());
        }

        return $html;
    }

    /**
     * Build Buttons Form.
     *
     * @param mixed $transaction
     * @param mixed $currencyCode
     *
     * @return string
     */
    private function buildButtonsForm($transaction, $currencyCode)
    {
        $html = '';
        $form = '';
        if ($transaction->status != 'PAYMENT_DELETED') {
            $minorunits = EpayTools::getCurrencyMinorunits($currencyCode);
            if ($transaction->status == 'PAYMENT_CAPTURED') {
                $epay_amount = EpayTools::convertPriceFromMinorUnits(
                    ($transaction->capturedamount - $transaction->creditedamount),
                    $minorunits
                );
            } else {
                $epay_amount = EpayTools::convertPriceFromMinorUnits(
                    ($transaction->authamount - $transaction->capturedamount),
                    $minorunits
                );
            }

            $form .= '<br />';
            $form .= '<form name="epay_remote" action="' . $_SERVER['REQUEST_URI'] . '" method="post" class="epay_displayInline" id="epay_action" >';
            $form .= '<input type="hidden" name="epay_transaction_id" value="' . $transaction->transactionid . '" />';
            $form .= '<input type="hidden" name="epay_order_id" value="' . $transaction->orderid . '" />';
            $form .= '<input type="hidden" name="epay_currency_code" value="' . $currencyCode . '" />';
            $form .= '<div class="input-group">';
            $form .= '<div class="input-group-addon" style="padding-top: 3px;">' . $currencyCode . '&nbsp;</div>';
            $tooltip = $this->l('Example: 1234.56');
            $form .= '<input type="text" data-toggle="tooltip" title="' . $tooltip . '" id="epay_amount" name="epay_amount" value="' . $epay_amount . '" /></div>';
            $form .= '<div id="epay_format_error" class="alert alert-danger"><strong>' . $this->l(
                    'Warning'
                ) . ' </strong>' . $this->l(
                    'The amount you entered was in the wrong format. Please try again!'
                ) . '</div>';
            $formBody = '';
            if ((!$transaction->capturedamount && $transaction->status != 'PAYMENT_CAPTURED')
                || ($transaction->splitpayment && $transaction->status != 'PAYMENT_CAPTURED' && $transaction->capturedamount != $transaction->authamount)) {
                $formBody .= $this->buildActionControl(
                    'epay_capture',
                    $this->l('Capture')
                );

                if ($transaction->status == 'PAYMENT_NEW' && $transaction->capturedamount === 0) {
                    $confirmText = $this->l('Really want to delete?');
                    $formBody .= $this->buildActionControl(
                        'epay_delete',
                        $this->l('Delete'),
                        $confirmText
                    );
                }

                if ($transaction->splitpayment) {
                    $confirmText = $this->l('Really want to close transaction?');
                    $formBody .= $this->buildActionControl(
                        'epay_move_as_captured',
                        $this->l('Close transaction'),
                        $confirmText
                    );
                }
            }
            if ((($transaction->status == 'PAYMENT_CAPTURED' && $transaction->creditedamount === 0) || $transaction->acquirer == 'EUROLINE')
                && ($transaction->creditedamount < $transaction->capturedamount)) {
                if ($transaction->capturedamount > $transaction->creditedamount) {
                    $confirmText = $this->l(
                            'Do you want to credit:'
                        ) . ' ' . $currencyCode . ' ';
                    $extra = '+getE(\'epay_amount\').value';
                    $formBody .= $this->buildActionControl(
                        'epay_credit',
                        $this->l('Credit'),
                        $confirmText,
                        $extra
                    );
                }
            }

            if (Tools::strlen($formBody) > 0) {
                $form .= $formBody;
                $form .= '</form>';
                $html = $form;
            }
        } else {
            $html .= ($transaction->status == 'PAYMENT_DELETED' ? ' <span class="epay_deleted">' . $this->l(
                    'Deleted'
                ) . '</span>' : '');
        }

        return $html;
    }

    private function buildActionControl(
        $type,
        $text,
        $confirmText = null,
        $extra = ''
    ) {
        $class = 'btn epay_button ';
        switch ($type) {
            case 'epay_capture':
                $class .= 'btn-success';
                break;
            case 'epay_credit':
                $class .= 'btn-warning';
                break;
            case 'epay_delete':
                $class .= 'btn-danger';
                break;
            case 'epay_move_as_captured':
                $class .= 'btn-info';
                break;
            default:
                break;
        }
        $confirmText = null;
        if (isset($confirmText)) {
            $html = '<input id="' . $type . '" class="' . $class . '" name="' . $type . '" type="submit" value="' . $text . '"onclick="return confirm(\'' . $confirmText . '\'' . $extra . ');" />';
        } else {
            $html = '<input id="' . $type . '" class="' . $class . '" name="' . $type . '" type="submit" value="' . $text . '" />';
        }

        return $html;
    }

    /**
     * Build transactionInfoTableRow.
     *
     * @param mixed $name
     * @param mixed $value
     *
     * @return string
     */
    private function transactionInfoTableRow($name, $value)
    {
        $html = '<tr><td>' . $name . '</td><td><b>' . $value . '</b></td></tr>';
        return $html;
    }

    /**
     * Build Logo Div.
     *
     * @return string
     */
    private function buildLogodiv()
    {
        $text = $this->l('Go to Bambora Online ePay Administration');
        $html = '<a href="https://admin.ditonlinebetalingssystem.dk/admin/login.asp" alt="" title="' . $text . '" target="_blank">';
        $html .= '<img class="bambora-logo" src="https://d3r1pwhfz7unl9.cloudfront.net/bambora/bambora-logo.svg" width="150px;" />';
        $html .= '</a>';
        $html .= '<div><a href="https://admin.ditonlinebetalingssystem.dk/admin/login.asp"  alt="" title="' . $text . '" target="_blank">' . $text . '</a></div>';

        return $html;
    }

    /**
     * Build Overlay Message.
     *
     * @param ePayUiMessage $epayUiMessage
     */
    private function buildOverlayMessage($epayUiMessage)
    {
        $html = '<div id="epay_overlay">';
        $html .= '<a id="epay_inline" href="#data"></a>';
        $html .= '<div id="data" class="row epay_overlay_data">';
        $html .= '<div id="epay_message" class="col-lg-12">';

        if ($epayUiMessage->type == 'issue') {
            $html .= '<div class="epay_circle epay_exclamation_circle">';
            $html .= '<div class="epay_exclamation_stem"></div>';
            $html .= '<div class="epay_exclamation_dot"></div>';
            $html .= '</div>';
        } else {
            $html .= '<div class="epay_circle epay_checkmark_circle">';
            $html .= '<div class="epay_checkmark_stem"></div>';
            $html .= '</div>';
        }
        $html .= '<div id="epay_overlay_message_container">';

        if (Tools::strlen($epayUiMessage->message) > 0) {
            $html .= '<p id="epay_overlay_message_title_with_message">' . $epayUiMessage->title . '</p>';
            $html .= '<hr><p id="epay_overlay_message_message">' . $epayUiMessage->message . '</p>';
        } else {
            $html .= '<p id="epay_overlay_message_title">' . $epayUiMessage->title . '</p>';
        }

        $html .= '</div></div></div></div>';

        return $html;
    }

    /**
     * Process post actions.
     *
     * @return ePayUiMessage|null
     */
    public function processRemote()
    {
        $epayUiMessage = null;

        if ((Tools::isSubmit('epay_capture')
                || Tools::isSubmit('epay_move_as_captured')
                || Tools::isSubmit('epay_credit')
                || Tools::isSubmit('epay_delete'))
            && Tools::getIsset('epay_transaction_id')
            && Tools::getIsset('epay_currency_code')) {
            try {
                $pwd = ConfigurationCore::get('EPAY_REMOTE_API_PASSWORD');
                $api = new EPayApi($pwd);
                $merchantNumber = Configuration::get('EPAY_MERCHANTNUMBER');
                $transactionId = Tools::getValue('epay_transaction_id');
                $errorTitle = $this->l(
                    'An issue occurred, and the operation was not performed.'
                );
                $amount = 0;
                $currencyCode = Tools::getValue('epay_currency_code');
                $minorunits = EpayTools::getCurrencyMinorunits($currencyCode);

                if ((Tools::isSubmit('epay_capture') || Tools::isSubmit(
                            'epay_credit'
                        )) && Tools::getIsset('epay_amount')) {
                    $epayAmount = Tools::getValue('epay_amount');
                    $amountSanitized = (float)str_replace(',', '.', $epayAmount);
                    if (is_float($amountSanitized)) {
                        $amount = EpayTools::convertPriceToMinorUnits(
                            $amountSanitized,
                            $minorunits,
                            Configuration::get('EPAY_ROUNDING_MODE')
                        );
                    } else {
                        $epayUiMessage = $this->createEpayUiMessage(
                            'issue',
                            $this->l('Inputfield is not a valid number')
                        );
                        return $epayUiMessage;
                    }
                }
                $logText = "";
                if (Tools::isSubmit('epay_capture')) {
                    $captureResponse = $api->capture(
                        $merchantNumber,
                        $transactionId,
                        $amount
                    );
                    if ($captureResponse->captureResult == 'true') {
                        $this->setDbCaptured($transactionId, $amount);
                        $captureText = $this->l(
                            'The Payment was captured successfully'
                        );
                        $epayUiMessage = $this->createEpayUiMessage(
                            'success',
                            $captureText
                        );
                        $logText = $captureText;
                    } else {
                        $errorMessage = $this->getApiErrorMessage(
                            $api,
                            $merchantNumber,
                            $captureResponse
                        );
                        $epayUiMessage = $this->createEpayUiMessage(
                            'issue',
                            $errorTitle,
                            $errorMessage
                        );
                    }
                } elseif (Tools::isSubmit('epay_credit')) {
                    $creditResponse = $api->credit(
                        $merchantNumber,
                        $transactionId,
                        $amount
                    );
                    if ($creditResponse->creditResult == 'true') {
                        $this->setDbCredited($transactionId, $amount);
                        $creditText = $this->l(
                            'The Payment was credited successfully'
                        );
                        $epayUiMessage = $this->createEpayUiMessage(
                            'success',
                            $creditText
                        );
                        $logText = $creditText;
                    } else {
                        $errorMessage = $this->getApiErrorMessage(
                            $api,
                            $merchantNumber,
                            $creditResponse
                        );
                        $epayUiMessage = $this->createEpayUiMessage(
                            'issue',
                            $errorTitle,
                            $errorMessage
                        );
                    }
                } elseif (Tools::isSubmit('epay_delete')) {
                    $deleteResponse = $api->delete($merchantNumber, $transactionId);
                    if ($deleteResponse->deleteResult == 'true') {
                        $this->deleteDbTransaction($transactionId);
                        $deleteText = $this->l(
                            'The Payment was deleted successfully'
                        );
                        $epayUiMessage = $this->createEpayUiMessage(
                            'success',
                            $deleteText
                        );
                        $logText = $deleteText;
                    } else {
                        $errorMessage = $this->getApiErrorMessage(
                            $api,
                            $merchantNumber,
                            $deleteResponse
                        );
                        $epayUiMessage = $this->createEpayUiMessage(
                            'issue',
                            $errorTitle,
                            $errorMessage
                        );
                    }
                } elseif (Tools::isSubmit('epay_move_as_captured')) {
                    $moveascapturedResponse = $api->moveascaptured(
                        $merchantNumber,
                        $transactionId
                    );
                    if ($moveascapturedResponse->move_as_capturedResult == 'true') {
                        $moveascapturedText = $this->l(
                            'The Payment was moved successfully'
                        );
                        $epayUiMessage = $this->createEpayUiMessage(
                            'success',
                            $moveascapturedText
                        );
                        $logText = $moveascapturedText;
                    } else {
                        $errorMessage = $this->getApiErrorMessage(
                            $api,
                            $merchantNumber,
                            $moveascapturedResponse
                        );
                        $epayUiMessage = $this->createEpayUiMessage(
                            'issue',
                            $errorTitle,
                            $errorMessage
                        );
                    }
                }
                //For Audit log
                $employee = $this->context->employee;
                $orderId = Tools::getValue("epay-order-id");
                $logText .= " :: OrderId: " . $orderId . " TransactionId: " . $transactionId . " Employee: " . $employee->firstname . " " . $employee->lastname . " " . $employee->email;
                $this->writeLogEntry($logText, 1);
            } catch (Exception $e) {
                $this->displayError($e->getMessage());
            }
        }

        return $epayUiMessage;
    }

    /**
     * Get ePay Api error messages (epay and pbs).
     *
     * @param EPayApi $api
     * @param string $merchant
     * @param mixed $epayApiResponse
     *
     * @return string
     */
    private function getApiErrorMessage($api, $merchantNumber, $epayApiResponse)
    {
        $message = '';
        $language = EpayTools::getEPayLanguage(
            Language::getIsoById($this->context->language->id)
        );
        if (isset($epayApiResponse->epayresponse) && $epayApiResponse->epayresponse != -1) {
            $message .= "ePay Error: ({$epayApiResponse->epayresponse}) ";
            if ($epayApiResponse->epayresponse == -1019) {
                $message .= $this->l('Invalid password used for webservice access!');
            } else {
                $message .= $api->getEpayError(
                    $merchantNumber,
                    $epayApiResponse->epayresponse,
                    $language
                );
            }
        }
        if (isset($epayApiResponse->pbsResponse) && $epayApiResponse->pbsResponse != -1) {
            if (Tools::strlen($message) > 0) {
                $message .= '<br />';
            }
            $message .= "PBS Error: ({$epayApiResponse->epayResponse}) ";
            $message .= $api->getPbsError(
                $merchantNumber,
                $epayApiResponse->pbsResponse,
                $language
            );
        }
        return $message;
    }

    /**
     * Create ePay Ui Message.
     *
     * @param string $type
     * @param string $title
     * @param string $message
     *
     * @return ePayUiMessage
     */
    private function createEpayUiMessage($type, $title, $message = '')
    {
        $epayUiMessage = new ePayUiMessage();
        $epayUiMessage->type = $type;
        $epayUiMessage->title = $title;
        $epayUiMessage->message = $message;

        return $epayUiMessage;
    }

    /**
     * Build the end html for the TransactionForm.
     *
     * @return string
     */
    private function buildTransactionFormEnd()
    {
        $html = '';
        if ($this->getPsVersion() === $this::V15) {
            $html .= '</fieldset>';
        } else {
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Create and add a private order message.
     *
     * @param int $orderId
     * @param string $message
     */
    private function createStatusChangesMessage($orderId, $message)
    {
        $msg = new Message();
        $message = strip_tags($message, '<br>');
        if (Validate::isCleanHtml($message)) {
            $msg->name = 'Bambora Online ePay';
            $msg->message = $message;
            $msg->id_order = (int)$orderId;
            $msg->private = 1;
            $msg->add();
        }
    }

    //region payment request

    /**
     * Display the payment request form.
     *
     * @param mixed $params
     *
     * @return mixed
     */
    private function displayPaymentRequestForm($params)
    {
        $order = new Order($params['id_order']);
        $employee = new Employee($this->context->cookie->id_employee);
        $currency = new Currency($order->id_currency);
        $currencyIsoCode = $currency->iso_code;
        // Get default Language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        // Init Fields form array
        $fields_form = array();
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Create payment request'),

            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Requester name'),
                    'name' => 'epay_paymentrequest_requester_name',
                    'size' => 20,
                    'required' => true,
                ),
                array(
                    'type' => 'textarea',
                    'label' => $this->l('Requester comment'),
                    'name' => 'epay_paymentrequest_requester_comment',
                    'rows' => 3,
                    'cols' => 50,
                    'required' => false,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Recipient name'),
                    'name' => 'epay_paymentrequest_recipient_name',
                    'size' => 20,
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Recipient e-mail'),
                    'name' => 'epay_paymentrequest_recipient_email',
                    'size' => 20,
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Reply to name'),
                    'name' => 'epay_paymentrequest_replyto_name',
                    'size' => 20,
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Reply to e-mail'),
                    'name' => 'epay_paymentrequest_replyto_email',
                    'size' => 20,
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Amount'),
                    'name' => 'epay_paymentrequest_amount',
                    'size' => 20,
                    'suffix' => $currencyIsoCode,
                    'required' => true,
                    'readonly' => false,
                ),
            ),
            'submit' => array(
                'title' => $this->l('Send payment request'),
                'id' => 'epay_paymentrequest_submit',
                'class' => 'button',
            ),
        );

        $helper = new HelperForm();

        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminOrders');
        $helper->currentIndex = AdminController::$currentIndex . '&vieworder&id_order=' . $params['id_order'];
        $helper->identifier = 'id_order';
        $helper->id = $params['id_order'];
        $helper->submit_action = 'sendpaymentrequest';

        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->show_toolbar = false;

        // Load current value
        $helper->fields_value['epay_paymentrequest_requester_name'] = Tools::getValue(
            'epay_paymentrequest_requester_name'
        ) ? Tools::getValue(
            'epay_paymentrequest_requester_name'
        ) : Configuration::get('PS_SHOP_NAME');
        $helper->fields_value['epay_paymentrequest_requester_comment'] = '';

        $helper->fields_value['epay_paymentrequest_recipient_name'] = $this->context->customer->firstname . ' ' . $this->context->customer->lastname;
        $helper->fields_value['epay_paymentrequest_recipient_email'] = $this->context->customer->email;

        $helper->fields_value['epay_paymentrequest_replyto_name'] = $employee->firstname . ' ' . $employee->lastname;
        $helper->fields_value['epay_paymentrequest_replyto_email'] = $employee->email;

        $helper->fields_value['epay_paymentrequest_amount'] = number_format(
            $order->total_paid,
            2,
            '.',
            ''
        );

        $html = '<div id="epay_paymentrequest_format_error" class="alert alert-danger"><strong>' . $this->l(
                'Warning'
            ) . ' </strong>' . $this->l(
                'The amount you entered was in the wrong format. Please try again!'
            ) . '</div>';
        $html .= $helper->generateForm($fields_form);
        return $html;
    }

    /**
     * Create payment request.
     *
     * @param Order $order
     *
     * @return mixed
     * @throws Exception
     *
     */
    private function createPaymentRequest($order)
    {
        $html = '';

        try {
            $orderid = $order->id;
            $amount = Tools::getValue('epay_paymentrequest_amount');
            $currency = new Currency($order->id_currency);
            $currencyIsoCode = $currency->iso_code;
            $requester = Tools::getValue('epay_paymentrequest_requester_name');
            $comment = Tools::getValue('epay_paymentrequest_requester_comment');
            $recipient_email = Tools::getValue(
                'epay_paymentrequest_recipient_email'
            );
            $recipient_name = Tools::getValue('epay_paymentrequest_recipient_name');
            $replyto_email = Tools::getValue('epay_paymentrequest_replyto_email');
            $replyto_name = Tools::getValue('epay_paymentrequest_replyto_name');
            $minorunits = EpayTools::getCurrencyMinorunits($currencyIsoCode);
            $languageIso = Language::getIsoById($this->context->language->id);

            //Get ordernumber
            $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'epay_transactions WHERE `id_order` = ' . (int)$orderid;
            $orderPostfix = Db::getInstance()->getValue($sql) + 1;

            $params = array();

            $params['authentication'] = array();
            $params['authentication']['merchantnumber'] = Configuration::get(
                'EPAY_MERCHANTNUMBER'
            );
            $params['authentication']['password'] = Configuration::get(
                'EPAY_REMOTE_API_PASSWORD'
            );

            $params['paymentrequest'] = array();
            $params['paymentrequest']['reference'] = $orderid;
            $params['paymentrequest']['closeafterxpayments'] = 1;

            $params['paymentrequest']['parameters'] = array();
            $amountSanitized = (float)str_replace(',', '.', $amount);
            $params['paymentrequest']['parameters']['amount'] = EpayTools::convertPriceToMinorUnits(
                $amountSanitized,
                $minorunits,
                Configuration::get('EPAY_ROUNDING_MODE')
            );
            $params['paymentrequest']['parameters']['callbackurl'] = $this->context->link->getModuleLink(
                $this->name,
                'paymentrequest',
                array('id_cart' => $order->id_cart),
                true
            );
            $params['paymentrequest']['parameters']['currency'] = $currencyIsoCode;
            $params['paymentrequest']['parameters']['group'] = Configuration::get(
                'EPAY_GROUP'
            );
            $params['paymentrequest']['parameters']['instantcapture'] = Configuration::get(
                'EPAY_INSTANTCAPTURE'
            ) == '1' ? 'automatic' : 'manual';
            $params['paymentrequest']['parameters']['orderid'] = $orderid . 'PAYREQ' . $orderPostfix;
            $params['paymentrequest']['parameters']['windowid'] = Configuration::get(
                'EPAY_WINDOWID'
            );
            $params["paymentrequest"]["parameters"]["language"] = Language::getLocaleByIso(
                $languageIso
            );

            $soapClient = new SoapClient(
                'https://paymentrequest.api.epay.eu/v1/PaymentRequestSOAP.svc?wsdl'
            );
            $createPaymentRequest = $soapClient->createpaymentrequest(
                array('createpaymentrequestrequest' => $params)
            );

            if ($createPaymentRequest->createpaymentrequestResult->result) {
                $sendParams = array();

                $sendParams['authentication'] = $params['authentication'];

                $sendParams['language'] = ($languageIso == 'da' ? 'da-DK' : 'en-US');

                $sendParams['email'] = array();
                $sendParams['email']['comment'] = $comment;
                $sendParams['email']['requester'] = $requester;

                $sendParams['email']['recipient'] = array();
                $sendParams['email']['recipient']['emailaddress'] = $recipient_email;
                $sendParams['email']['recipient']['name'] = $recipient_name;

                $sendParams['email']['replyto'] = array();
                $sendParams['email']['replyto']['emailaddress'] = $replyto_email;
                $sendParams['email']['replyto']['name'] = $replyto_name;

                $sendParams['paymentrequest'] = array();
                $sendParams['paymentrequest']['paymentrequestid'] = $createPaymentRequest->createpaymentrequestResult->paymentrequest->paymentrequestid;

                $sendPaymentRequest = $soapClient->sendpaymentrequest(
                    array('sendpaymentrequestrequest' => $sendParams)
                );

                if ($sendPaymentRequest->sendpaymentrequestResult->result) {
                    $message = 'Payment request (' . $createPaymentRequest->createpaymentrequestResult->paymentrequest->paymentrequestid . ') created and sent to: ' . $recipient_email;

                    $msg = new Message();
                    $message = strip_tags($message, '<br>');
                    if (Validate::isCleanHtml($message)) {
                        $msg->message = $message;
                        $msg->id_order = (int)$orderid;
                        $msg->private = 1;
                        $msg->add();
                    }

                    $html = $this->displayConfirmation(
                        $this->l('Payment request is sent.')
                    );
                } else {
                    throw new Exception(
                        $sendPaymentRequest->sendpaymentrequestResult->message
                    );
                }
            } else {
                throw new Exception(
                    $createPaymentRequest->createpaymentrequestResult->message
                );
            }
        } catch (Exception $e) {
            $html = $this->displayError($e->getMessage());
        }

        return $html;
    }

    //endregion


    //region Common Methods

    /**
     * Get Ps Version.
     *
     * @return string
     */
    public function getPsVersion()
    {
        if (_PS_VERSION_ < '1.6.0.0') {
            return $this::V15;
        } elseif (_PS_VERSION_ >= '1.6.0.0' && _PS_VERSION_ < '1.7.0.0') {
            return $this::V16;
        } else {
            return $this::V17;
        }
    }

    /**
     * Get if Ps Version Higher than 177
     *
     * @return string
     */
    public function isPsVersionHigherThan177()
    {
        if (_PS_VERSION_ < "1.7.7.0") {
            return false;
        } else {
            return true;
        }
    }

    public function writeLogEntry($message, $severity)
    {
        if ($this->getPsVersion() === Bambora::V15) {
            Logger::addLog($message, $severity);
        } else {
            PrestaShopLogger::addLog($message, $severity);
        }
    }
    //endregion
}
