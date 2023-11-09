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
abstract class BaseAction extends ModuleFrontController
{
    /**
     * Validate the callback.
     *
     * @param bool $isPaymentRequest
     * @param string $message
     * @param mixed $cart
     *
     * @return bool
     */
    protected function validateAction($isPaymentRequest, &$message, &$cart)
    {
        if (!Tools::getIsset('txnid')) {
            $message = 'No GET(txnid) was supplied to the system!';
            return false;
        }

        $id_cart = null;

        if (!$isPaymentRequest) {
            if (!Tools::getIsset('orderid')) {
                $message = 'No GET(orderid) was supplied to the system!';
                return false;
            }
            $id_cart = Tools::getValue('orderid');
        } else {
            if (!Tools::getIsset('id_cart')) {
                $message = 'No Cart id was supplied on the paymentrequest callback';
                return false;
            }
            $id_cart = Tools::getValue('id_cart');
        }

        $cart = new Cart($id_cart);

        if (!isset($cart->id)) {
            $message = 'Please provide a valid orderid or cartid';
            return false;
        }

        $storeMd5 = Configuration::get('EPAY_MD5KEY');
        if (!empty($storeMd5)) {
            $accept_params = Tools::getAllValues();
            $var = '';
            foreach ($accept_params as $key => $value) {
                if ($key == 'hash') {
                    break;
                }
                $var .= $value;
            }

            $storeHash = md5($var . $storeMd5);
            if ($storeHash != Tools::getValue('hash')) {
                $message = 'Hash validation failed - Please check your MD5 key';
                return false;
            }
        }

        return true;
    }

    /**
     * Process Action.
     *
     * @param mixed $cart
     * @param bool $isPaymentRequest
     * @param mixed $responseCode
     *
     * @return mixed
     */
    protected function processAction($cart, $isPaymentRequest, &$responseCode)
    {
        $message = '';
        try {
            if (!$cart->orderExists() || $isPaymentRequest) {
                $id_cart = $cart->id;
                $transaction_Id = Tools::getValue('txnid');
                $epayOrderId = Tools::getValue('orderid');
                $cardId = Tools::getValue('paymenttype');
                $cardnopostfix = Tools::getIsset('cardno') ? Tools::substr(
                    Tools::getValue('cardno'),
                    -4
                ) : 0;
                $epayCurrency = Tools::getValue('currency', null);
                $currency = new Currency($cart->id_currency);
                $amountInMinorunits = Tools::getValue('amount');
                $minorunits = EpayTools::getCurrencyMinorunits($currency->iso_code);
                $amount = EpayTools::convertPriceFromMinorUnits(
                    $amountInMinorunits,
                    $minorunits
                );
                $transfeeInMinorunits = Tools::getValue('txnfee', 0);
                $fraud = Tools::getValue('fraud', 0);

                if ($this->module->addDbTransaction(
                    0,
                    $id_cart,
                    $transaction_Id,
                    $epayOrderId,
                    $cardId,
                    $cardnopostfix,
                    $epayCurrency,
                    $amountInMinorunits,
                    $transfeeInMinorunits,
                    $fraud
                )) {
                    $cardName = EpayTools::getCardNameById($cardId);
                    $paymentMethod = "{$this->module->displayName} ({$cardName})";
                    $truncatedCard = "XXXX XXXX XXXX {$cardnopostfix}";

                    $mailVars = array(
                        'TransactionId' => $transaction_Id,
                        'PaymentType' => $paymentMethod,
                        'CardNumber' => $truncatedCard,
                    );

                    if (!$isPaymentRequest) {
                        try {
                            $this->module->validateOrder(
                                (int)$id_cart,
                                Configuration::get('PS_OS_PAYMENT'),
                                $amount,
                                $paymentMethod,
                                null,
                                $mailVars,
                                null,
                                false,
                                $cart->secure_key
                            );
                        } catch (Exception $ex) {
                            $message = 'Prestashop threw an exception on validateOrder: ' . $ex->getMessage();
                            $responseCode = 500;
                            $this->module->deleteDbRecordedTransaction(
                                $transaction_Id
                            );
                            return $message;
                        }
                    }

                    $id_order = Order::getOrderByCartId($id_cart);
                    $this->module->addDbOrderIdToRecordedTransaction(
                        $transaction_Id,
                        $id_order
                    );
                    $order = new Order($id_order);

                    if ($isPaymentRequest) {
                        $message = 'Payment request succeded with ePay Transaction ID: ' . $transaction_Id;
                        $msg = new Message();
                        $message = strip_tags($message, '<br>');
                        if (Validate::isCleanHtml($message)) {
                            $msg->message = $message;
                            $msg->id_order = (int)$order->id;
                            $msg->private = 1;
                            $msg->add();
                        }

                        $order->setCurrentState(Configuration::get('PS_OS_PAYMENT'));
                    }

                    $payment = $order->getOrderPayments();
                    $payment[0]->transaction_id = $transaction_Id;
                    $payment[0]->amount = $amount;
                    $payment[0]->card_number = $cardnopostfix;
                    $payment[0]->card_brand = EpayTools::getCardnameById($cardId);
                    $payment[0]->payment_method = $paymentMethod;

                    if ($transfeeInMinorunits > 0) {
                        $transFee = EpayTools::convertPriceFromMinorUnits(
                            $transfeeInMinorunits,
                            $minorunits
                        );
                        $payment[0]->amount = $payment[0]->amount + $transFee;

                        if (Configuration::get('EPAY_ADDFEETOSHIPPING')) {
                            $order->total_paid = $order->total_paid + $transFee;
                            $order->total_paid_tax_incl = $order->total_paid_tax_incl + $transFee;
                            $order->total_paid_tax_excl = $order->total_paid_tax_excl + $transFee;
                            $order->total_paid_real = $order->total_paid_real + $transFee;
                            $order->total_shipping = $order->total_shipping + $transFee;
                            $order->total_shipping_tax_incl = $order->total_shipping_tax_incl + $transFee;
                            $order->total_shipping_tax_excl = $order->total_shipping_tax_excl + $transFee;
                            $order->save();

                            $invoice = new OrderInvoice($order->invoice_number);
                            if (isset($invoice->id)) {
                                $invoice->total_paid_tax_incl = $invoice->total_paid_tax_incl + $transFee;
                                $invoice->total_paid_tax_excl = $invoice->total_paid_tax_excl + $transFee;
                                $invoice->total_shipping_tax_incl = $invoice->total_shipping_tax_incl + $transFee;
                                $invoice->total_shipping_tax_excl = $invoice->total_shipping_tax_excl + $transFee;
                                $invoice->save();
                            }
                        }
                    }
                    $payment[0]->save();
                    $message = 'Order created';
                    $responseCode = 200;
                } else {
                    $message = 'Order is beeing created or have been created by another process';
                    $responseCode = 200;
                }
            } else {
                $message = 'Order was already Created';
                $responseCode = 200;
            }
        } catch (Exception $e) {
            $responseCode = 500;
            $message = 'Process order failed with an exception: ' . $e->getMessage();
        }

        return $message;
    }

    /**
     * Create error log Message.
     *
     * @param mixed $message
     * @param mixed $cart
     *
     */
    protected function createLogMessage($message, $severity = 3, $cart = null)
    {
        $result = '';
        if (isset($cart)) {
            $invoiceAddress = new Address((int)$cart->id_address_invoice);
            $customer = new Customer((int)$cart->id_customer);
            $phoneNumber = EpayTools::getPhoneNumber($invoiceAddress);
            $personString = "Name: {$invoiceAddress->firstname}{$invoiceAddress->lastname} Phone: {$phoneNumber} Mail: {$customer->email} - ";
            $result = $personString;
        }
        $result .= 'An payment error occurred: ' . $message;
        if ($this->module->getPsVersion() === Epay::V15) {
            Logger::addLog($result, $severity);
        } else {
            PrestaShopLogger::addLog($result, $severity);
        }
    }
}
