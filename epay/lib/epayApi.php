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
class EPayApi
{
    private $pwd = '';

    private $client;

    const PAYMENT_WSDL = 'https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL';

    public function __construct($pwd)
    {
        $this->pwd = $pwd;
        $this->client = new SoapClient($this::PAYMENT_WSDL);
    }

    /**
     * Capture the payment.
     *
     * @param mixed $merchantnumber
     * @param mixed $transactionid
     * @param mixed $amount
     *
     * @return mixed
     */
    public function capture($merchantnumber, $transactionid, $amount)
    {
        $epay_params = array();
        $epay_params['merchantnumber'] = $merchantnumber;
        $epay_params['transactionid'] = $transactionid;
        $epay_params['amount'] = (string)$amount;
        $epay_params['pwd'] = (string)$this->pwd;
        $epay_params['pbsResponse'] = '-1';
        $epay_params['epayresponse'] = '-1';

        $result = $this->client->capture($epay_params);
        return $result;
    }

    /**
     * Move the payment as captured.
     *
     * @param mixed $merchantnumber
     * @param mixed $transactionid
     *
     * @return mixed
     */
    public function moveascaptured($merchantnumber, $transactionid)
    {
        $epay_params = array();
        $epay_params['merchantnumber'] = $merchantnumber;
        $epay_params['transactionid'] = $transactionid;
        $epay_params['epayresponse'] = '-1';
        $epay_params['pwd'] = (string)$this->pwd;

        $result = $this->client->move_as_captured($epay_params);
        return $result;
    }

    /**
     * Credit the payment.
     *
     * @param mixed $merchantnumber
     * @param mixed $transactionid
     * @param mixed $amount
     *
     * @return mixed
     */
    public function credit($merchantnumber, $transactionid, $amount)
    {
        $epay_params = array();
        $epay_params['merchantnumber'] = $merchantnumber;
        $epay_params['transactionid'] = $transactionid;
        $epay_params['amount'] = (string)$amount;
        $epay_params['pwd'] = (string)$this->pwd;
        $epay_params['epayresponse'] = '-1';
        $epay_params['pbsresponse'] = '-1';

        $result = $this->client->credit($epay_params);
        return $result;
    }

    /**
     * Delete the payment.
     *
     * @param mixed $merchantnumber
     * @param mixed $transactionid
     *
     * @return mixed
     */
    public function delete($merchantnumber, $transactionid)
    {
        $epay_params = array();
        $epay_params['merchantnumber'] = $merchantnumber;
        $epay_params['transactionid'] = $transactionid;
        $epay_params['pwd'] = (string)$this->pwd;
        $epay_params['epayresponse'] = '-1';

        $result = $this->client->delete($epay_params);
        return $result;
    }

    /**
     * Get ePay error message.
     *
     * @param mixed $merchantnumber
     * @param mixed $epay_response_code
     * @param mixed $language
     *
     * @return mixed
     */
    public function getEpayError($merchantnumber, $epay_response_code, $language)
    {
        $epay_params = array();
        $epay_params['merchantnumber'] = $merchantnumber;
        $epay_params['language'] = $language;
        $epay_params['pwd'] = (string)$this->pwd;
        $epay_params['epayresponsecode'] = $epay_response_code;
        $epay_params['epayresponse'] = '-1';

        $result = $this->client->getEpayError($epay_params);
        if ($result->getEpayErrorResult) {
            return $result->epayresponsestring;
        } else {
            return '';
        }
    }

    /**
     * Get PBS error message.
     *
     * @param mixed $merchantnumber
     * @param mixed $pbs_response_code
     * @param mixed $language
     *
     * @return mixed
     */
    public function getPbsError($merchantnumber, $pbs_response_code, $language)
    {
        $epay_params = array();
        $epay_params['merchantnumber'] = $merchantnumber;
        $epay_params['language'] = $language;
        $epay_params['pbsresponsecode'] = $pbs_response_code;
        $epay_params['pwd'] = (string)$this->pwd;
        $epay_params['epayresponse'] = '-1';

        $result = $this->client->getPbsError($epay_params);

        if ($result->getPbsErrorResult) {
            return $result->pbsresponsestring;
        } else {
            return '';
        }
    }

    /**
     * Get a transaction.
     *
     * @param mixed $merchantnumber
     * @param mixed $transactionid
     *
     * @return mixed
     */
    public function gettransaction($merchantnumber, $transactionid)
    {
        $epay_params = array();
        $epay_params['merchantnumber'] = $merchantnumber;
        $epay_params['transactionid'] = $transactionid;
        $epay_params['pwd'] = (string)$this->pwd;
        $epay_params['epayresponse'] = '-1';

        $result = $this->client->gettransaction($epay_params);
        return $result;
    }

    /**
     * Get information about a transaction.
     *
     * @param mixed $merchantnumber
     * @param mixed $transactionid
     *
     * @return mixed
     */
    public function gettransactionInformation($merchantnumber, $transactionid)
    {
        $epay_params = array();
        $epay_params['merchantnumber'] = $merchantnumber;
        $epay_params['transactionid'] = $transactionid;
        $epay_params['pwd'] = (string)$this->pwd;
        $epay_params['epayresponse'] = '-1';

        $result = $this->client->gettransaction($epay_params);

        if ($result->gettransactionResult) {
            return $result->transactionInformation;
        } else {
            return false;
        }
    }

    /**
     * Get card information.
     *
     * @param mixed $merchantnumber
     * @param mixed $cardno_prefix
     * @param mixed $amount
     * @param mixed $currency
     * @param mixed $acquirer
     *
     * @return mixed
     */
    public function getcardinfo(
        $merchantnumber,
        $cardno_prefix,
        $amount,
        $currency,
        $acquirer
    ) {
        $epay_params = array();
        $epay_params['merchantnumber'] = $merchantnumber;
        $epay_params['cardno_prefix'] = $cardno_prefix;
        $epay_params['amount'] = $amount;
        $epay_params['currency'] = $currency;
        $epay_params['acquirer'] = $acquirer;
        $epay_params['pwd'] = (string)$this->pwd;
        $epay_params['epayresponse'] = '-1';

        $result = $this->client->getcardinfo($epay_params);

        return $result;
    }
}
