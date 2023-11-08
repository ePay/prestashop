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
include 'baseaction.php';

class EPayAcceptModuleFrontController extends BaseAction
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $message = '';
        $responseCode = '400';
        $cart = null;
        if ($this->validateAction(false, $message, $cart)) {
            $message = $this->processAction($cart, false, $responseCode);
            if ($responseCode != 200) {
                $this->handleError($message, $cart);
            }
            $this->redirectToAccept($cart);
        } else {
            $message = empty($message) ? $this->l('Unknown error') : $message;
            $this->handleError($message, $cart);
        }
    }

    /**
     * Redirect To Accept.
     *
     * @param mixed $cart
     */
    private function redirectToAccept($cart)
    {
        Tools::redirectLink(
            __PS_BASE_URI__ . 'order-confirmation.php?key=' . $cart->secure_key . '&id_cart=' . (int)$cart->id . '&id_module=' . (int)$this->module->id . '&id_order=' . (int)Order::getOrderByCartId(
                $cart->id
            )
        );
    }

    private function handleError($message, $cart)
    {
        $this->createLogMessage($message, 3, $cart);
        Context::getContext()->smarty->assign('paymenterror', $message);
        if ($this->module->getPsVersion() === EPay::V17) {
            $this->setTemplate(
                'module:epay/views/templates/front/paymenterror17.tpl'
            );
        } else {
            $this->setTemplate('paymenterror.tpl');
        }
    }
}
