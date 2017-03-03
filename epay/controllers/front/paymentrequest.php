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
class EPayPaymentRequestModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $amount = number_format(Tools::getValue('amount') / 100, 2, ".", "");
        $id_order = Tools::getValue('id_order');
        $id_cart = Tools::getValue('id_cart');
        $currency = Tools::getValue('currency');
        $currencyid = Currency::getIdByIsoCodeNum($currency);
        $cardid = Tools::getValue('paymenttype');
        $cardnopostfix = Tools::getIsset('cardno') ? Tools::substr(Tools::getValue('cardno'), - 4) : 0;
        $transfee = Tools::getValue('txnfee', 0);
        $fraud = Tools::getValue('fraud', 0);
        $params = Tools::getAllValues();
        $var = "";
        $txnId = Tools::getValue('txnid');

        foreach ($params as $key => $value) {
            if ($key != "hash") {
                $var .= $value;
            } else {
                break;
            }
        }

        if (Tools::strlen(Configuration::get('EPAY_MD5KEY')) > 0) {
            $genstamp = md5($var . Configuration::get('EPAY_MD5KEY'));

            if ($genstamp != Tools::getValue('hash')) {
                die(Tools::displayError('Error in MD5 data! Please review your passwords in both your ePay and Prestashop administration!'));
            }
        }

        $this->module->recordTransaction($id_order, $id_cart, $txnId, $cardid, $cardnopostfix, $currency, Tools::getValue('amount'), $transfee, $fraud);

        $order = new Order($id_order);

        $message = "ePay Transaction ID: " . $txnId;

        $msg = new Message();
        $message = strip_tags($message, '<br>');
        if (Validate::isCleanHtml($message)) {
            $msg->message = $message;
            $msg->id_order = (int)$order->id;
            $msg->private = 1;
            $msg->add();
        }

        $invoice = new OrderInvoice($order->invoice_number);
        $order->addOrderPayment($amount, $this->module->displayName, $txnId, new Currency($currencyid), null, $invoice);

        $payment = $order->getOrderPayments();
        $payment[0]->transaction_id = $txnId;
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
    }

    public function initContent()
    {
        parent::initContent();
    }
}
