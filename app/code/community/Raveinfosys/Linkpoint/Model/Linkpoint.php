<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * which is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you are unable to obtain it through the world-wide-web,
 * please send an email to magento@raveinfosys.com
 * so we can send you a copy immediately.
 *
 * @category	Raveinfosys
 * @package		Raveinfosys_Linkpoint
 * @author		RaveInfosys, Inc.
 * @license		http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Raveinfosys_Linkpoint_Model_Linkpoint extends Mage_Payment_Model_Method_Ccsave
{
	
	protected $_code						=	'linkpoint'; //unique internal payment method identifier	
	protected $_isGateway					=	true;	//Is this payment method a gateway (online auth/charge) ?
    protected $_canAuthorize				=	true;	//Can authorize online?
    protected $_canCapture					=	true;	//Can capture funds online?
    protected $_canCapturePartial			=	false;	//Can capture partial amounts online?
    protected $_canRefund					=	true;	//Can refund online?
	protected $_canRefundInvoicePartial		=	true;	//Can refund invoices partially?
    protected $_canVoid						=	true;	//Can void transactions online?
    protected $_canUseInternal				=	true;	//Can use this payment method in administration panel?
    protected $_canUseCheckout				=	true;	//Can show this payment method as an option on checkout payment page?
    protected $_canUseForMultishipping		=	false;	//Is this payment method suitable for multi-shipping checkout?
    protected $_isInitializeNeeded			=	false;
    protected $_canFetchTransactionInfo		=	false;
    protected $_canReviewPayment			=	false;
    protected $_canCreateBillingAgreement	=	false;
    protected $_canManageRecurringProfiles	=	false;
	protected $_canSaveCc					=	true;	//Can save credit card information for future processing?	
	
	/**
     * Fields that should be replaced in debug with '***'
     *
     * @var array
     */
    protected $_debugReplacePrivateDataKeys = array('keyId', 'hmacKey', 'gatewayId', 'gatewayPass');
	
	/**
     * Validate payment method information object
     *
     * @param   Mage_Payment_Model_Info $info
     * @return  Mage_Payment_Model_Abstract
     */
	public function validate()
	{
		$info = $this->getInfoInstance();
		$order_amount = 0;
		if ($info instanceof Mage_Sales_Model_Quote_Payment) {
            $order_amount = (double)$info->getQuote()->getBaseGrandTotal();
        } elseif ($info instanceof Mage_Sales_Model_Order_Payment) {
            $order_amount = (double)$info->getOrder()->getQuoteBaseGrandTotal();
        }
		
		$order_min = $this->getConfigData('min_order_total');
		$order_max = $this->getConfigData('max_order_total');
		if(!empty($order_max) && (double)$order_max<$order_amount) {
			Mage::throwException("Order amount greater than permissible Maximum order amount.");
		}
		if(!empty($order_min) && (double)$order_min>$order_amount) {
			Mage::throwException("Order amount less than required Minimum order amount.");
		}
		/*
        * calling parent validate function
        */
        parent::validate();
	}
	
	/**
     * Send authorize request to gateway
     *
     * @param Varien_Object $payment
     * @param decimal $amount
     * @return Mage_Paygate_Model_Authorizenet
     * @throws Mage_Core_Exception
     */
	public function authorize(Varien_Object $payment, $amount)
	{
		if ($amount <= 0) {
            Mage::throwException(Mage::helper('linkpoint')->__('Invalid amount for transaction.'));
        }		
		$payment->setAmount($amount);		
		$data = $this->_prepareData();
		$data['trans_type']	=	"01";		
		$creditcard = array(
			'cardnumber'	=>	$payment->getCcNumber(),
			'cardexpmonth'	=>	$payment->getCcExpMonth(),
			'ccname'		=>	$payment->getCcOwner(),
			'cardexpyear'	=>	substr($payment->getCcExpYear(),-2),
		);
		if($this->getConfigData('useccv')==1) {
			$creditcard["cvmindicator"]	=	"provided";
			$creditcard["cvmvalue"]		=	$payment->getCcCid();
			$creditcard["cvv2indicator"]=	1;
			$creditcard["cvv2value"]	=	$payment->getCcCid();
		}
		
		$shipping = array();
		$billing = array();
		$order = $payment->getOrder();		
		if (!empty($order)) {
			$BillingAddress	=	$order->getBillingAddress();
			
			$billing['name']	=	$BillingAddress->getFirstname()." ".$BillingAddress->getLastname();
			$billing['company']	=	$BillingAddress->getCompany();
			$billing['address']	=	$BillingAddress->getStreet(1);
			$billing['city']	=	$BillingAddress->getCity();
			$billing['state']	=	$BillingAddress->getRegion();
			$billing['zip']		=	$BillingAddress->getPostcode();
			$billing['country']	=	$BillingAddress->getCountry();
			$billing['email']	=	$order->getCustomerEmail();
			$billing['phone']	=	$BillingAddress->getTelephone();
			$billing['fax']		=	$BillingAddress->getFax();
			
			$ShippingAddress	=	$order->getShippingAddress();
			if (!empty($shipping)) {
				$shipping['sname']		=	$ShippingAddress->getFirstname()." ".$ShippingAddress->getLastname();
				$shipping['saddress1']	=	$ShippingAddress->getStreet(1);
				$shipping['scity']		=	$ShippingAddress->getCity();
				$shipping['sstate']		=	$ShippingAddress->getRegion();
				$shipping['szip']		=	$ShippingAddress->getPostcode();
				$shipping['scountry']	=	$ShippingAddress->getCountry();
			}
		}

		$merchantinfo = array();
		$merchantinfo['gatewayId'] = $data['gatewayId'];
		$merchantinfo['gatewayPass'] = $data['gatewayPass'];		
		$paymentdetails = array();
		$paymentdetails['chargetotal'] = $payment->getAmount();
		
		$data = array_merge($data, $creditcard, $billing, $shipping, $merchantinfo, $paymentdetails);
		
		$result = $this->_postRequest($data);		
		
		if(is_array($result) && count($result)>0) {
		
			if(array_key_exists("Bank_Message",$result)) {
				if ($result["Bank_Message"] != "Approved") {
					$payment->setStatus(self::STATUS_ERROR);
					Mage::throwException("Gateway error : {".(string)$result["EXact_Message"]."}");
				}
				elseif($trxnResult->Transaction_Error){
						Mage::throwException("Returned Error Message: $trxnResult->EXact_Message");
				}
				/*
				elseif($this->getConfigData('useccv') && $trxnResult->CVV2 != "M" ){
							Mage::throwException("Invalid Card Verification Number(".$trxnResult->CVV2.")");
				}*/
				else {
					$payment->setStatus(self::STATUS_APPROVED);
					$payment->setAdditionalInformation('payment_type', $this->getConfigData('payment_action'));
					$payment->setLastTransId((string)$result["Authorization_Num"]);
					$payment->setTransactionTag((string)$result["Transaction_Tag"]);
					if (!$payment->getParentTransactionId() || (string)$result["Authorization_Num"] != $payment->getParentTransactionId()) {
						$payment->setTransactionId((string)$result["Authorization_Num"]);
					}
					$this->_addTransaction(
					$payment,
					$result["Authorization_Num"],
					Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH,
					array('is_transaction_closed' => 0));
					$payment->setSkipTransactionCreation(true);
					return $this;
				}
			}
			else {
				Mage::throwException("No approval found");
			}
		} else {
			Mage::throwException("No response found");
		}
	}
	
	public function capture(Varien_Object $payment, $amount)
	{
		if ($amount <= 0) {
            Mage::throwException(Mage::helper('linkpoint')->__('Invalid amount for transaction.'));
        }

		if($payment->getTransactionTag() != '' && Mage::app()->getStore()->isAdmin()){
			return $this->authorizePayment($payment, number_format($amount, 2, '.', ''));
		}	
			
		
		
		$payment->setAmount($amount);		
		$data = $this->_prepareData();
		$data['trans_type']	=	"00";		
		$creditcard = array(
			'cardnumber'	=>	$payment->getCcNumber(),
			'cardexpmonth'	=>	$payment->getCcExpMonth(),
			'cardexpyear'	=>	substr($payment->getCcExpYear(),-2),
			'ccname'	=>	$payment->getCcOwner()
		);
		if($this->getConfigData('useccv')==1) {
			$creditcard["cvmindicator"]	=	"provided";
			$creditcard["cvmvalue"]		=	$payment->getCcCid();
			$creditcard["cvv2indicator"]=	1;
			$creditcard["cvv2value"]	=	$payment->getCcCid();
		}
		
		$shipping = array();
		$billing = array();
		$order = $payment->getOrder();		
		if (!empty($order)) {
			$BillingAddress	=	$order->getBillingAddress();
			
			$billing['name']	=	$BillingAddress->getFirstname()." ".$BillingAddress->getLastname();
			$billing['company']	=	$BillingAddress->getCompany();
			$billing['address']	=	$BillingAddress->getStreet(1);
			$billing['city']	=	$BillingAddress->getCity();
			$billing['state']	=	$BillingAddress->getRegion();
			$billing['zip']		=	$BillingAddress->getPostcode();
			$billing['country']	=	$BillingAddress->getCountry();
			$billing['email']	=	$order->getCustomerEmail();
			$billing['phone']	=	$BillingAddress->getTelephone();
			$billing['fax']		=	$BillingAddress->getFax();
			
			$ShippingAddress	=	$order->getShippingAddress();
			if (!empty($shipping)) {
				$shipping['sname']		=	$ShippingAddress->getFirstname()." ".$ShippingAddress->getLastname();
				$shipping['saddress1']	=	$ShippingAddress->getStreet(1);
				$shipping['scity']		=	$ShippingAddress->getCity();
				$shipping['sstate']		=	$ShippingAddress->getRegion();
				$shipping['szip']		=	$ShippingAddress->getPostcode();
				$shipping['scountry']	=	$ShippingAddress->getCountry();
			}
		}

		$merchantinfo = array();
		$merchantinfo['gatewayId'] = $data['gatewayId'];
		$merchantinfo['gatewayPass'] = $data['gatewayPass'];		
		$paymentdetails = array();
		$paymentdetails['chargetotal'] = $payment->getAmount();
		
		$data = array_merge($data, $creditcard, $billing, $shipping, $merchantinfo, $paymentdetails);
		
		$result = $this->_postRequest($data);		

		if(is_array($result) && count($result)>0) {
		
			if(array_key_exists("Bank_Message",$result)) {
				if ($result["Bank_Message"] != "Approved") {
					$payment->setStatus(self::STATUS_ERROR);
					Mage::throwException("Gateway error : {".(string)$result["EXact_Message"]."}");
				}
				elseif($trxnResult->Transaction_Error){
						Mage::throwException("Returned Error Message: $trxnResult->EXact_Message");
				}
				/*
				elseif($this->getConfigData('useccv') && $trxnResult->CVV2 != "M" ){
							Mage::throwException("Invalid Card Verification Number(".$trxnResult->CVV2.")");
				}*/
				else {
					$payment->setStatus(self::STATUS_APPROVED);
					$payment->setLastTransId((string)$result["Authorization_Num"]);
					$payment->setTransactionTag((string)$result["Transaction_Tag"]);
					if (!$payment->getParentTransactionId() || (string)$result["Authorization_Num"] != $payment->getParentTransactionId()) {
						$payment->setTransactionId((string)$result["Authorization_Num"]);
					}
					return $this;
				}
			}
			else {
				Mage::throwException("No approval found");
			}
		} else {
			Mage::throwException("No response found");
		}
	}
	
	public function authorizePayment(Varien_Object $payment, $amount) {
	    $payment->setAmount($amount);		
		$data = $this->_prepareData();
		$data['trans_type']	=	"32";
		$data['transaction_tag'] = $payment->getTransactionTag();		
		
		#$data['authorization_num'] = $payment->getLastTransId();
		$data['authorization_num'] = $payment->getParentTransactionId();			
		
		$data['chargetotal'] = $amount;
		
		$result = $this->_postRequest($data);		

		if(is_array($result) && count($result)>0) {
		
			if(array_key_exists("Bank_Message",$result)) {
				if ($result["Bank_Message"] != "Approved") {
					$payment->setStatus(self::STATUS_ERROR);
					Mage::throwException("Gateway error : {".(string)$result["EXact_Message"]."}");
				}
				elseif($trxnResult->Transaction_Error){
						Mage::throwException("Returned Error Message: $trxnResult->EXact_Message");
				}
				/*
				elseif($this->getConfigData('useccv') && $trxnResult->CVV2 != "M" ){
							Mage::throwException("Invalid Card Verification Number(".$trxnResult->CVV2.")");
				}*/
				else {
					$payment->setStatus(self::STATUS_APPROVED);
					$payment->setLastTransId((string)$result["Authorization_Num"]);
					$payment->setTransactionTag((string)$result["Transaction_Tag"]);
					if (!$payment->getParentTransactionId() || (string)$result["Authorization_Num"] != $payment->getParentTransactionId()) {
						$payment->setTransactionId((string)$result["Authorization_Num"]);
					}
					return $this;
				}
			}
			else {
				Mage::throwException("No approval found");
			}
		} else {
			Mage::throwException("No response found");
		}
	}
	
	/**
     * refund the amount with transaction id
     *
     * @param string $payment Varien_Object object
     * @return Mage_Paygate_Model_Authorizenet
     * @throws Mage_Core_Exception
     */
    public function refund(Varien_Object $payment, $amount) {
		if ($payment->getRefundTransactionId() && $amount > 0) {
            $data = $this->_prepareData();
			$data["trans_type"] = '34';			
			$data["oid"] = $payment->getRefundTransactionId();
			$data['transaction_tag'] = $payment->getTransactionTag();
			$data['authorization_num'] = $payment->getParentTransactionId();				
			
			$instance = $payment->getMethodInstance()->getInfoInstance();
			$paymentdetails = array();
			$paymentdetails['chargetotal'] = $amount;
			$paymentdetails['cardnumber'] = $instance->getCcNumber();
			$paymentdetails['ccname'] = $instance->getCcOwner();
			$paymentdetails['cardexpmonth'] = $instance->getCcExpMonth();
			$paymentdetails['cardexpyear'] = substr($instance->getCcExpYear(),-2);			
			$shipping = array();
			$billing = array();
			
			$data = array_merge($data, $paymentdetails);
			$result = $this->_postRequest($data);			
			if(is_array($result) && count($result)>0) {
				if(array_key_exists("Bank_Message",$result)) {
					if ($result["Bank_Message"] != "Approved") {
						Mage::throwException("Gateway error : {".(string)$result["EXact_Message"]."}");
					} else {
						$payment->setStatus(self::STATUS_SUCCESS);
						$payment->setLastTransId((string)$result["Authorization_Num"]);
						if (!$payment->getParentTransactionId() || (string)$result["Authorization_Num"] != $payment->getParentTransactionId()) {
							$payment->setTransactionId((string)$result["Authorization_Num"]);
						}
						return $this;
					}
				} else {
					Mage::throwException("No approval found");
				}
			} else {
				Mage::throwException("No response found");
			}
			
        }
        Mage::throwException(Mage::helper('paygate')->__('Error in refunding the payment.'));
	}
	
	/**
     * Void the payment through gateway
     *
     * @param Varien_Object $payment
     * @return Mage_Paygate_Model_Authorizenet
     * @throws Mage_Core_Exception
     */
    public function void(Varien_Object $payment)
	{
		if ($payment->getParentTransactionId()) {
			$data = $this->_prepareData();			
			$data["trans_type"] = '33';
			$data["oid"]	=	$payment->getParentTransactionId();			
			$data['transaction_tag'] = $payment->getTransactionTag();
			$data['authorization_num'] = $payment->getParentTransactionId();		
			$instance = $payment->getMethodInstance()->getInfoInstance();
			$paymentdetails = array();
			$paymentdetails['cardnumber'] = $instance->getCcNumber();
			$data['ccname'] = $instance->getCcOwner();
			$paymentdetails['cardexpmonth'] = $instance->getCcExpMonth();
			$paymentdetails['cardexpyear'] = substr($instance->getCcExpYear(),-2);	
			$order = $payment->getOrder();		
			$data['chargetotal'] = $order->getGrandTotal();
			$data	=	array_merge($data, $paymentdetails);			
			$result = $this->_postRequest($data);	
			if(is_array($result) && count($result)>0) {
				if(array_key_exists("Bank_Message",$result)) {
					if ($result["Bank_Message"] != "Approved") {
						Mage::throwException("Gateway error : {".(string)$result["EXact_Message"]."}");
					} else {
						$payment->setStatus(self::STATUS_SUCCESS);
						return $this;
					}
				} else {
					Mage::throwException("No approval found");
				}
			} else {
				Mage::throwException("No response found");
			}
        }
        $payment->setStatus(self::STATUS_ERROR);
        Mage::throwException('Invalid transaction ID.');
    }
	
	/**
     * Cancel payment
     *
     * @param   Varien_Object $invoicePayment
     * @return  Mage_Payment_Model_Abstract
     */
    public function cancel(Varien_Object $payment) {
        return $this->void($payment);
    }
	
	/**
     * converts a hash of name-value pairs
     * to the correct array for new API
	 * change on 19-feb-15
	 *
     * @param Array $pdata
     * @return String $xml
     */
	protected function _buildRequest($req)
	{
		$request	=	array(
				"User_Name"			=>	"",
				"Secure_AuthResult"	=>	"",
				"Ecommerce_Flag"	=>	"",
				"XID"				=>	$req["oid"],
				"ExactID"			=>	$req["gatewayId"],
				"CAVV"				=>	"",
				"Password"			=>	$req["gatewayPass"],
				"CAVV_Algorithm"	=>	"",
				"Transaction_Type"	=>	$req["trans_type"],
				"Reference_No"		=>	"",
				"Customer_Ref"		=>	"",
				"Reference_3"		=>	"",
				"Client_IP"			=>	$req["ip"],
				"Client_Email"		=>	$req["email"],
				"Language"			=>	"en",
				"Card_Number"		=>	$req["cardnumber"],
				"Expiry_Date"		=>	sprintf("%02d", $req['cardexpmonth']).$req['cardexpyear'],
				"CardHoldersName"	=>	$req["ccname"],
				"Track1"			=>	"",
				"Track2"			=>	"",
				"Authorization_Num"	=>	$req["authorization_num"],
				"Transaction_Tag"	=>	$req["transaction_tag"],
				"DollarAmount"		=>	$req["chargetotal"],
				"VerificationStr1"	=>	"",
				"VerificationStr2"	=>	$req["cvv2value"],
				"CVD_Presence_Ind"	=>	$req["cvv2indicator"],
				"Secure_AuthRequired"=>	"",
				"Currency"			=>	"",
				"PartialRedemption"	=>	"",			
				"ZipCode"			=>	$req["zip"],
				"Tax1Amount"		=>	"",
				"Tax1Number"		=>	"",
				"Tax2Amount"		=>	"",
				"Tax2Number"		=>	"",
				"SurchargeAmount"	=>	"",
				"PAN"				=>	""
			);

		return $request;
 
	}
	
	/**
     * converts the LSGS response xml string
     * to a hash of name-value pairs
	 *
     * @param String $xml
     * @return Array $retarr
     */
	protected function _readResponse($trxnResult) {
		foreach($trxnResult as $key=>$value){
			$value = nl2br($value);
			$retarr[$key] = $value;
		}
		return $retarr;
	}
	
	/**
     * chnage from: process hash table or xml string table using cURL
	 * change to : process data using SoapClientHMAC for latest version of API
	 * change on 19-feb-15
	 *
     * @param Array $data
     * @return String $xml
     */
	protected function _postRequest($data) {
	
		$debugData = array('request' => $data);
		$trxnProperties = '';
		$trxnProperties = $this->_buildRequest($data);		
		try
		{
			$client = Mage::getModel('linkpoint/soapclienthmac', array(	"url" =>	$data["wsdlUrl"]));
			$response = $client->SendAndCommit($trxnProperties);		
		}catch(Exception $e){
			$debugData['response'] = $e->getMessage();
			$this->_debug($debugData);
			Mage::throwException("Link point authorization failed");
		}

		if (!$response) {
			$debugData['response'] = $response;
			$this->_debug($debugData);
			Mage::throwException(ucwords("error in $response"));
		}
		
		if(@$client->fault){			
			Mage::throwException("FAULT:  Code: {$client->faultcode} <BR /> String: {$client->faultstring} </B>");
			$response["CTR"] = "There was an error while processing. No TRANSACTION DATA IN CTR!";
		}		
		
		$result = $this->_readResponse($response);
		$debugData['response'] = $result;
		/* if($this->getConfigData('debug') == 1) {
			$this->_debug($debugData);
		} */
		$this->_debug($debugData);
		return $result;
	}
	
	protected function _prepareData()
	{
		$_coreHelper = Mage::helper('core');
		
		$data = array(
					'keyId'			=>	$_coreHelper->decrypt($this->getConfigData('key_id')),
					'hmacKey'		=>	$_coreHelper->decrypt($this->getConfigData('hmac_key')),
					'wsdlUrl'       =>  $this->getConfigData('wsdl_url'),
					'gatewayId'		=>  $_coreHelper->decrypt($this->getConfigData('gateway_id')),
					'gatewayPass'	=>  $_coreHelper->decrypt($this->getConfigData('gateway_pass')), 
				);		
		
		if($this->getConfigData('mode')) {
			$data['wsdlUrl'] = "https://api.demo.globalgatewaye4.firstdata.com/transaction/wsdl";
		}
		
		if(empty($data['keyId']) || empty($data['hmacKey']) || empty($data['wsdlUrl']) || empty($data['gatewayId']) || empty($data['gatewayPass'])){
			Mage::throwException("Gateway Parameters Missing");
		}		
		return	$data;
	}
	
	protected function _addTransaction(Mage_Sales_Model_Order_Payment $payment, $transactionId, $transactionType,
        array $transactionDetails = array(), $message = false ) {
		
        $payment->setTransactionId($transactionId);
        $payment->resetTransactionAdditionalInfo();
        foreach ($transactionDetails as $key => $value) {
            $payment->setData($key, $value);
        }
        
        $transaction = $payment->addTransaction($transactionType, null, false , $message);
        foreach ($transactionDetails as $key => $value) {
            $payment->unsetData($key);
        }
        $payment->unsLastTransId();

        $transaction->setMessage($message);

        return $transaction;
    }
}

?>