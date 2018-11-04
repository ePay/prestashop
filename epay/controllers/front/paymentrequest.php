<?php
/**
 * Copyright (c) 2018. All rights reserved ePay A/S (a Bambora Company).
 *
 * This program is free software. You are allowed to use the software but NOT allowed to modify the software.
 * It is also not legal to do any changes to the software and distribute it in your own name / brand.
 *
 * All use of the payment modules happens at your own risk. We offer a free test account that you can use to test the module.
 *
 * @author    ePay A/S (a Bambora Company)
 * @copyright Bambora (https://bambora.com) (http://www.epay.dk)
 * @license   ePay A/S (a Bambora Company)
 */
include 'baseaction.php';

class EPayPaymentRequestModuleFrontController extends BaseAction
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $message = '';
        $responseCode = 400;
        $cart = null;
        if ($this->validateAction(true, $message, $cart)) {
            $message = $this->processAction($cart, true, $responseCode);
        } else {
            $message = empty($message) ? 'Unknown error' : $message;
            $this->createLogMessage($message, 3, $cart);
        }

        $header = 'X-EPay-System: '.EpayTools::getModuleHeaderInfo();
        header($header, true, $responseCode);
        die($message);
    }
}
