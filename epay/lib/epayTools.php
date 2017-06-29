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
 */
class EpayTools
{

    /**
     * Get the module header information
     *
     * @return string
     */
    public static function getModuleHeaderInfo()
    {
        $ePayVersion = EPay::MODULE_VERSION;
        $prestashopVersion = _PS_VERSION_;
        $result = 'Prestashop/' . $prestashopVersion . ' Module/' . $ePayVersion . ' PHP/'. phpversion();

        return $result;
    }

    /**
     * Get Ps Version
     *
     * @return string
     */
    public static function getPsVersion()
    {
        if (_PS_VERSION_ < "1.6.0.0") {
            return EPay::V15;
        } elseif (_PS_VERSION_ >= "1.6.0.0" && _PS_VERSION_ < "1.7.0.0") {
            return EPay::V16;
        } else {
            return EPay::V17;
        }
    }

    /**
     * Get ePay language id by string country
     *
     * @param string $strlan
     * @return integer
     */
    public static function getEPayLanguage($strlan)
    {
        switch ($strlan) {
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

    /**
     * Get card name by card id
     *
     * @param mixed $card_id
     * @return string
     */
    public static function getCardNameById($card_id)
    {
        switch ($card_id) {
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

    /**
     * Get Phone Number
     *
     * @param mixed $invoiceAddress
     * @return mixed
     */
    public function getPhoneNumber($address)
    {
        if ($address->phone_mobile != "" || $address->phone != "") {
            return $address->phone_mobile != "" ? $address->phone_mobile : $address->phone;
        } else {
            return "";
        }
    }
}
