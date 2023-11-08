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
class EpayPaymentModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $cart = $this->context->cart;
        if ($cart->id_customer == 0 ||
            $cart->id_address_delivery == 0 ||
            $cart->id_address_invoice == 0 ||
            !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'epay') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->module->l('This payment method is not available.', 'epay'));
        }

        //create ePay payment window request
        $epayPaymentWindowRequest = $this->module->createPaymentWindowRequest($cart);

        if (!isset($epayPaymentWindowRequest)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $paymentWindowJsUrl = 'https://ssl.ditonlinebetalingssystem.dk/integration/ewindow/paymentwindow.js';

        $paymentData = array(
            'epayPaymentWindowJsUrl' => $paymentWindowJsUrl,
            'epayPaymentWindowRequest' => json_encode($epayPaymentWindowRequest),
            'epayCancelUrl' => $epayPaymentWindowRequest['epay_cancelurl'],
            'epayWindowState' => $epayPaymentWindowRequest['epay_windowstate'],
        );

        $this->context->smarty->assign($paymentData);

        $this->setTemplate('module:epay/views/templates/front/payment17.tpl');
    }
}
