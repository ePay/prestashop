<?php
/**
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
 */
class EPayValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_column_left = false;
    private $isCallback = false;

    public function postProcess()
    {
        $this->isCallback = Tools::getValue('callback') == "1";

        $id_cart = Tools::getValue('orderid');
        $cart = new Cart($id_cart);

        if (!$this->isCallback) {
            /* Wait for callback */
            for ($i = 0; $i < 10; $i++) {
                if ($cart->orderExists()) {
                    $this->redirectToAccept($cart);
                    return;
                }
                sleep(1);
            }
        }

        $amount = number_format(Tools::getValue('amount') / 100, 2, ".", "");
        $currency = Tools::getValue('currency', null);
        $currencyid = isset($currency) ? Currency::getIdByIsoCodeNum($currency) : null;

        $cardid = Tools::getValue('paymenttype');
        $cardnopostfix = Tools::getIsset('cardno') ? Tools::substr(Tools::getValue('cardno'), - 4) : 0;
        $transfee = Tools::getValue('txnfee', 0);
        $fraud = Tools::getValue('fraud', 0);
        $txnid = Tools::getValue('txnid');
        $mailVars = array();

        $params = Tools::getAllValues();
        $var = "";

        foreach ($params as $key => $value) {
            if ($key != "hash") {
                $mailVars['{epay_' . $key . '}'] = $value;
                $var .= $value;
            } else {
                break;
            }
        }

        if (Tools::strlen(Configuration::get('EPAY_MD5KEY')) > 0) {
            $genstamp = md5($var . Configuration::get('EPAY_MD5KEY'));

            if ($genstamp != Tools::getValue('hash')) {
                die(Tools::displayError('Error in MD5 data! Please review your passwords in both ePay and your Prestashop admin!'));
            }
        }

        $message = "";
        $responseCode = "";
        if ($cart->OrderExists() == 0) {
            $paymentMethod = $this->module->displayName . ' ('. $this->module->getCardNameById($cardid) .')';

            if ($this->module->validateOrder((int)$id_cart, Configuration::get('PS_OS_PAYMENT'), $amount, $paymentMethod, null, $mailVars, $currencyid, false, $cart->secure_key)) {
                $order = new Order($this->module->currentOrder);

                $this->module->recordTransaction($order->id, $id_cart, $txnid, Tools::getValue('orderid'), $cardid, $cardnopostfix, $currency, Tools::getValue('amount'), $transfee, $fraud);

                $payment = $order->getOrderPayments();
                $payment[0]->transaction_id = $txnid;
                $payment[0]->amount = $amount;
                $payment[0]->card_number = 'XXXX XXXX XXXX ' . $cardnopostfix;
                $payment[0]->card_brand = $this->module->getCardnameById($cardid);

                if ($transfee > 0) {
                    $payment[0]->amount = $payment[0]->amount + number_format($transfee / 100, 2, ".", "");

                    if (Configuration::get('EPAY_ADDFEETOSHIPPING')) {
                        $order->total_paid = $order->total_paid + number_format($transfee / 100, 2, ".", "");
                        $order->total_paid_tax_incl = $order->total_paid_tax_incl + number_format($transfee / 100, 2, ".", "");
                        $order->total_paid_tax_excl = $order->total_paid_tax_excl + number_format($transfee / 100, 2, ".", "");
                        $order->total_paid_real = $order->total_paid_real + number_format($transfee / 100, 2, ".", "");
                        $order->total_shipping = $order->total_shipping + number_format($transfee / 100, 2, ".", "");
                        $order->total_shipping_tax_incl = $order->total_shipping_tax_incl + number_format($transfee / 100, 2, ".", "");
                        $order->total_shipping_tax_excl = $order->total_shipping_tax_excl + number_format($transfee / 100, 2, ".", "");
                        $order->save();

                        if ($invoice = $payment[0]->getOrderInvoice($this->module->currentOrder)) {
                            $invoice->total_paid_tax_incl = $invoice->total_paid_tax_incl + number_format($transfee / 100, 2, ".", "");
                            $invoice->total_paid_tax_excl = $invoice->total_paid_tax_excl + number_format($transfee / 100, 2, ".", "");
                            $invoice->total_shipping_tax_incl = $invoice->total_shipping_tax_incl + number_format($transfee / 100, 2, ".", "");
                            $invoice->total_shipping_tax_excl = $invoice->total_shipping_tax_excl + number_format($transfee / 100, 2, ".", "");

                            $invoice->save();
                        }
                    }
                }
                $payment[0]->save();
                $message = "Order created";
                $responseCode = 200;
            } else {
                $message = "Prestashop could not validate order";
                $responseCode = 500;
            }
        } else {
            $message = "Order already created";
            $responseCode = 200;
        }

        if (!$this->isCallback) {
            $this->redirectToAccept($cart);
        } else {
            $header = "X-EPay-System: ". $this->module->getModuleHeaderInfo();
            header($header, true, $responseCode);
            die($message);
        }
    }

    private function redirectToAccept($cart)
    {
        Tools::redirectLink(__PS_BASE_URI__.'order-confirmation.php?key='.$cart->secure_key.'&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.(int)Order::getOrderByCartId($cart->id));
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
