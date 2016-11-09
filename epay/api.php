<?php
class EPayApi extends PaymentModule
{
	public function _findTranslation()
	{
	}

	public function capture($merchantnumber, $transactionid, $amount)
	{
		$epay_params = array();
		$epay_params['merchantnumber'] = $merchantnumber;
		$epay_params['transactionid'] = $transactionid;
		$epay_params['amount'] = (string)$amount;
		$epay_params['pwd'] = strval(Configuration::get('EPAY_REMOTE_API_PASSWORD'));
		$epay_params['pbsResponse'] = "-1";
		$epay_params['epayresponse'] = "-1";

		$result = $this->_soapcall()->capture($epay_params);

		return $result;
	}

	public function moveascaptured($merchantnumber, $transactionid)
	{
		$epay_params = array();
		$epay_params['merchantnumber'] = $merchantnumber;
		$epay_params['transactionid'] = $transactionid;
		$epay_params['epayresponse'] = "-1";
		$epay_params['pwd'] = strval(Configuration::get('EPAY_REMOTE_API_PASSWORD'));

		$result = $this->_soapcall()->move_as_captured($epay_params);

		return $result;
	}

	public function credit($merchantnumber, $transactionid, $amount)
	{
		$epay_params = array();
		$epay_params['merchantnumber'] = $merchantnumber;
		$epay_params['transactionid'] = $transactionid;
		$epay_params['amount'] = (string)$amount;
		$epay_params['pwd'] = strval(Configuration::get('EPAY_REMOTE_API_PASSWORD'));
		$epay_params['epayresponse'] = "-1";
		$epay_params['pbsresponse'] = "-1";

		$result = $this->_soapcall()->credit($epay_params);

		return $result;
	}

	public function delete($merchantnumber, $transactionid)
	{
		$epay_params = array();
		$epay_params['merchantnumber'] = $merchantnumber;
		$epay_params['transactionid'] = $transactionid;
		$epay_params['pwd'] = strval(Configuration::get('EPAY_REMOTE_API_PASSWORD'));
		$epay_params['epayresponse'] = "-1";

		$result = $this->_soapcall()->delete($epay_params);

		return $result;
	}

	public function getEpayError($merchantnumber, $epay_response_code)
	{
		$epay_params = array();
		$epay_params['merchantnumber'] = $merchantnumber;
		$epay_params['language'] = 2;
		$epay_params['epayresponsecode'] = $epay_response_code;
		$epay_params['epayresponse'] = "-1";

		$result = $this->_soapcall()->getEpayError($epay_params);

		if ($result->getEpayErrorResult == "true")
			echo '<script>alert("'.PaymentModule::l('Failure:').' '.Tools::iconv('ISO-8859-15', 'UTF-8', $result->epayresponsestring).'");</script>';

		return $result;
	}

	public function getPbsError($merchantnumber, $pbs_response_code)
	{
		$epay_params = array();
		$epay_params['merchantnumber'] = $merchantnumber;
		$epay_params['language'] = 2;
		$epay_params['pbsresponsecode'] = $pbs_response_code;
		$epay_params['pwd'] = strval(Configuration::get('EPAY_REMOTE_API_PASSWORD'));
		$epay_params['epayresponse'] = "-1";

		$result = $this->_soapcall()->getPbsError($epay_params);

		if ($result->getPbsErrorResult == "true")
			echo '<script>alert("'.PaymentModule::l('Failure:').' '.Tools::iconv('ISO-8859-15', 'UTF-8', $result->pbsresponsestring).'");</script>';

		return $result;
	}

	public function gettransaction($merchantnumber, $transactionid)
	{
		$epay_params = array();
		$epay_params['merchantnumber'] = $merchantnumber;
		$epay_params['transactionid'] = $transactionid;
		$epay_params['pwd'] = strval(Configuration::get('EPAY_REMOTE_API_PASSWORD'));
		$epay_params["epayresponse"] = "-1";

		$result = $this->_soapcall()->gettransaction($epay_params);

		return $result;
	}

	public function gettransactionInformation($merchantnumber, $transactionid)
	{
		$epay_params = array();
		$epay_params['merchantnumber'] = $merchantnumber;
		$epay_params['transactionid'] = $transactionid;
		$epay_params['pwd'] = strval(Configuration::get('EPAY_REMOTE_API_PASSWORD'));
		$epay_params["epayresponse"] = "-1";

		$result = $this->_soapcall()->gettransaction($epay_params);

		if ($result->gettransactionResult == true)
			return $result->transactionInformation;
		else
			return false;
	}

	public function getcardinfo($merchantnumber, $cardno_prefix, $amount, $currency, $acquirer)
	{
		$epay_params = array();
		$epay_params['merchantnumber'] = $merchantnumber;
		$epay_params['cardno_prefix'] = $cardno_prefix;
		$epay_params['amount'] = $amount;
		$epay_params['currency'] = $currency;
		$epay_params['acquirer'] = $acquirer;
		$epay_params['pwd'] = strval(Configuration::get('EPAY_REMOTE_API_PASSWORD'));
		$epay_params["epayresponse"] = "-1";

		$result = $this->_soapcall()->getcardinfo($epay_params);

		return $result;
	}

	private function _soapcall()
	{
		$client = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL');

		return $client;
	}
}
?>