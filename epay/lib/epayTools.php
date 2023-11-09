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
class EpayTools
{
    const ROUND_UP = 'round_up';

    const ROUND_DOWN = 'round_down';

    const ROUND_DEFAULT = 'round_default';

    /**
     * Get the module header information.
     *
     * @return string
     */
    public static function getModuleHeaderInfo()
    {
        $ePayVersion = EPay::MODULE_VERSION;
        $prestashopVersion = _PS_VERSION_;
        $result = 'Prestashop/' . $prestashopVersion . ' Module/' . $ePayVersion . ' PHP/' . PHP_VERSION;
        return $result;
    }

    /**
     * Get Ps Version.
     *
     * @return string
     */
    public static function getPsVersion()
    {
        if (_PS_VERSION_ < '1.6.0.0') {
            return EPay::V15;
        } elseif (_PS_VERSION_ >= '1.6.0.0' && _PS_VERSION_ < '1.7.0.0') {
            return EPay::V16;
        } else {
            return EPay::V17;
        }
    }

    /**
     * Get ePay language id by string country.
     *
     * @param string $strlan
     *
     * @return int
     */
    public static function getEPayLanguage($strlan)
    {
        switch ($strlan) {
            case 'dan':
            case 'da':
                return 1;
            case 'eng':
            case 'en':
                return 2;
            case 'swe':
            case 'sv':
                return 3;
            case 'nob':
            case 'nb':
            case 'nno':
            case 'nn':
            case 'nor':
            case 'no':
                return 4;
            case 'kal':
            case 'kl':
            case 'gl':
                return 5;
            case 'isl':
            case 'is':
                return 6;
            case 'deu':
            case 'de':
                return 7;
            case 'fin':
            case 'fi':
                return 8;
            case 'spa':
            case 'es':
                return 9;
            case 'fra':
            case 'fr':
                return 10;
            case 'pol':
            case 'pl':
                return 11;
            case 'ita':
            case 'it':
                return 12;
            case 'nld':
            case 'nl':
                return 13;
        }

        return 0;
    }

    /**
     * Get card name by card id.
     *
     * @param mixed $card_id
     *
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
     * Get Phone Number.
     *
     * @param mixed $invoiceAddress
     *
     * @return mixed
     */
    public function getPhoneNumber($address)
    {
        if ($address->phone_mobile != '' || $address->phone != '') {
            return $address->phone_mobile != '' ? $address->phone_mobile : $address->phone;
        } else {
            return '';
        }
    }

    /**
     * Convert Price To MinorUnits.
     *
     * @param mixed $amount
     * @param mixed $minorunits
     * @param mixed $defaultMinorUnits
     *
     * @return float|int
     */
    public static function convertPriceToMinorUnits($amount, $minorunits, $rounding)
    {
        if ($amount == '' || $amount == null) {
            return 0;
        }

        switch ($rounding) {
            case EpayTools::ROUND_UP:
                $amount = ceil($amount * pow(10, $minorunits));
                break;
            case EpayTools::ROUND_DOWN:
                $amount = floor($amount * pow(10, $minorunits));
                break;
            default:
                $amount = round($amount * pow(10, $minorunits));
                break;
        }

        return $amount;
    }

    /**
     * Convert Price From MinorUnits.
     *
     * @param mixed $amount
     * @param mixed $minorunits
     *
     * @return float
     */
    public static function convertPriceFromMinorUnits($amount, $minorunits)
    {
        if (!isset($amount)) {
            return 0;
        }

        return ($amount / pow(10, $minorunits));
    }

    /**
     * Get Currency MinorUnits.
     *
     * @param mixed $currencyCode
     *
     * @return int
     */
    public static function getCurrencyMinorunits($currencyCode)
    {
        $currencyArray = array(
            'TTD' => 0,
            'KMF' => 0,
            'ADP' => 0,
            'TPE' => 0,
            'BIF' => 0,
            'DJF' => 0,
            'MGF' => 0,
            'XPF' => 0,
            'GNF' => 0,
            'BYR' => 0,
            'PYG' => 0,
            'JPY' => 0,
            'CLP' => 0,
            'XAF' => 0,
            'TRL' => 0,
            'VUV' => 0,
            'CLF' => 0,
            'KRW' => 0,
            'XOF' => 0,
            'RWF' => 0,
            'IQD' => 3,
            'TND' => 3,
            'BHD' => 3,
            'JOD' => 3,
            'OMR' => 3,
            'KWD' => 3,
            'LYD' => 3,
        );

        return array_key_exists(
            $currencyCode,
            $currencyArray
        ) ? $currencyArray[$currencyCode] : 2;
    }
}
