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
class Raveinfosys_Linkpoint_Model_Linkpoint extends Mage_Payment_Model_Method_Cc {
	
	protected $_code						=	'linkpoint';	//unique internal payment method identifier
	
	protected $_isGateway					=	true;			//Is this payment method a gateway (online auth/charge) ?
    protected $_canAuthorize				=	true;			//Can authorize online?
    protected $_canCapture					=	true;			//Can capture funds online?
    protected $_canCapturePartial			=	false;			//Can capture partial amounts online?
    protected $_canRefund					=	true;			//Can refund online?
	protected $_canRefundInvoicePartial		=	true;			//Can refund invoices partially?
    protected $_canVoid						=	true;			//Can void transactions online?
    protected $_canUseInternal				=	true;			//Can use this payment method in administration panel?
    protected $_canUseCheckout				=	true;			//Can show this payment method as an option on checkout payment page?
    protected $_canUseForMultishipping		=	false;			//Is this payment method suitable for multi-shipping checkout?
    protected $_isInitializeNeeded			=	false;
    protected $_canFetchTransactionInfo		=	false;
    protected $_canReviewPayment			=	false;
    protected $_canCreateBillingAgreement	=	false;
    protected $_canManageRecurringProfiles	=	false;
	protected $_canSaveCc					=	false;			//Can save credit card information for future processing?
	
	/**
     * Fields that should be replaced in debug with '***'
     *
     * @var array
     */
    protected $_debugReplacePrivateDataKeys = array('cvmvalue', 'keyfile', 'cardnumber', 'cardexpmonth', 'cardexpyear');
	
	/**
     * Validate payment method information object
     *
     * @param   Mage_Payment_Model_Info $info
     * @return  Mage_Payment_Model_Abstract
     */
	public function validate() {
		$info = $this->getInfoInstance();
		$order_amount=0;
		if ($info instanceof Mage_Sales_Model_Quote_Payment) {
            $order_amount=(double)$info->getQuote()->getBaseGrandTotal();
        } elseif ($info instanceof Mage_Sales_Model_Order_Payment) {
            $order_amount=(double)$info->getOrder()->getQuoteBaseGrandTotal();
        }
		
		$order_min=$this->getConfigData('min_order_total');
		$order_max=$this->getConfigData('max_order_total');
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
     * Send capture request to gateway
     *
     * @param Varien_Object $payment
     * @param decimal $amount
     * @return Mage_Paygate_Model_Authorizenet
     * @throws Mage_Core_Exception
     */
	public function capture(Varien_Object $payment, $amount) {
		if ($amount <= 0) {
            Mage::throwException(Mage::helper('linkpoint')->__('Invalid amount for transaction.'));
        }
		
		$payment->setAmount($amount);
		
		$data=$this->_prepareData();
		
		$data['ordertype']	=	"SALE";
		
		$creditcard=array(
			'cardnumber'	=>	$payment->getCcNumber(),
			'cardexpmonth'	=>	$payment->getCcExpMonth(),
			'cardexpyear'	=>	substr($payment->getCcExpYear(),-2),
		);
		if($this->getConfigData('useccv')==1) {
			$creditcard["cvmindicator"]	=	"provided";
			$creditcard["cvmvalue"]		=	$payment->getCcCid();
		}
		
		$shipping=array();
		$billing=array();
		
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
		$transactiondetails=array();
		
		$merchantinfo=array();
		$merchantinfo['configfile']	=	$data['storenumber'];
		$merchantinfo['keyfile']	=	$data['key'];
		
		$paymentdetails=array();
		$paymentdetails['chargetotal']=$payment->getAmount();
		
		$data=array_merge($data, $creditcard, $billing, $shipping, $transactiondetails, $merchantinfo, $paymentdetails);
		
		$result = $this->_postRequest($data);
		if(is_array($result) && count($result)>0) {
			if(array_key_exists("r_approved",$result)) {
				if ($result["r_approved"] != "APPROVED") {
					$payment->setStatus(self::STATUS_ERROR);
					Mage::throwException("Gateway error : {".(string)$result["r_error"]."}");
				} else {
					$payment->setStatus(self::STATUS_APPROVED);
					$payment->setLastTransId((string)$result["r_ordernum"]);
					if (!$payment->getParentTransactionId() || (string)$result["r_ordernum"] != $payment->getParentTransactionId()) {
						$payment->setTransactionId((string)$result["r_ordernum"]);
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
	
	/**
     * refund the amount with transaction id
     *
     * @param string $payment Varien_Object object
     * @return Mage_Paygate_Model_Authorizenet
     * @throws Mage_Core_Exception
     */
    public function refund(Varien_Object $payment, $amount) {
		if ($payment->getRefundTransactionId() && $amount > 0) {
            $data=$this->_prepareData();
			$data['ordertype']	=	"CREDIT";
			$data["oid"]		=	$payment->getRefundTransactionId();
			
			$merchantinfo=array();
			$merchantinfo['configfile']	=	$data['storenumber'];
			$merchantinfo['keyfile']	=	$data['key'];
			
			$paymentdetails=array();
			$paymentdetails['chargetotal']=$amount;
			
			$data=array_merge($data, $merchantinfo, $paymentdetails);
			
			$result = $this->_postRequest($data);
			
			if(is_array($result) && count($result)>0) {
				if(array_key_exists("r_approved",$result)) {
					if ($result["r_approved"] != "APPROVED") {
						Mage::throwException("Gateway error : {".(string)$result["r_error"]."}");
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
        Mage::throwException(Mage::helper('paygate')->__('Error in refunding the payment.'));
	}
	
	/**
     * Void the payment through gateway
     *
     * @param Varien_Object $payment
     * @return Mage_Paygate_Model_Authorizenet
     * @throws Mage_Core_Exception
     */
    public function void(Varien_Object $payment) {
        if ($payment->getParentTransactionId()) {
            $data=$this->_prepareData();
			$data['ordertype']	=	"VOID";
			$data["oid"]		=	$payment->getParentTransactionId();
			
			$merchantinfo=array();
			$merchantinfo['configfile']	=	$data['storenumber'];
			$merchantinfo['keyfile']	=	$data['key'];
			
			$data=array_merge($data, $merchantinfo);
			
			$result = $this->_postRequest($data);
			
			if(is_array($result) && count($result)>0) {
				if(array_key_exists("r_approved",$result)) {
					if ($result["r_approved"] != "APPROVED") {
						Mage::throwException("Gateway error : {".(string)$result["r_error"]."}");
					} else {
						$payment->setStatus(self::STATUS_SUCCESS );
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
     * to the correct XML format for LSGS
	 *
     * @param Array $pdata
     * @return String $xml
     */
	protected function _buildRequest($pdata) {
		$xml = "<order>";
		### ORDEROPTIONS NODE ###
		$xml .= "<orderoptions>";
		if (isset($pdata["ordertype"]))
			$xml .= "<ordertype>" . $pdata["ordertype"] . "</ordertype>";
		
		if (isset($pdata["result"]))
			$xml .= "<result>" . $pdata["result"] . "</result>";
			
		$xml .= "</orderoptions>";
		### ORDEROPTIONS NODE ###
		
		### CREDITCARD NODE ###
		$xml .= "<creditcard>";

		if (isset($pdata["cardnumber"]))
			$xml .= "<cardnumber>" . $pdata["cardnumber"] . "</cardnumber>";

		if (isset($pdata["cardexpmonth"]))
			$xml .= "<cardexpmonth>" . $pdata["cardexpmonth"] . "</cardexpmonth>";

		if (isset($pdata["cardexpyear"]))
			$xml .= "<cardexpyear>" . $pdata["cardexpyear"] . "</cardexpyear>";

		if (isset($pdata["cvmvalue"]))
			$xml .= "<cvmvalue>" . $pdata["cvmvalue"] . "</cvmvalue>";

		if (isset($pdata["cvmindicator"]))
			$xml .= "<cvmindicator>" . $pdata["cvmindicator"] . "</cvmindicator>";

		if (isset($pdata["track"]))
			$xml .= "<track>" . $pdata["track"] . "</track>";

		$xml .= "</creditcard>";
		### CREDITCARD NODE ###
		
		### BILLING NODE ###
		$xml .= "<billing>";

		if (isset($pdata["name"]))
			$xml .= "<name>" . $pdata["name"] . "</name>";

		if (isset($pdata["company"]))
			$xml .= "<company>" . $pdata["company"] . "</company>";

		if (isset($pdata["address1"]))
			$xml .= "<address1>" . $pdata["address1"] . "</address1>";
		elseif (isset($pdata["address"]))
			$xml .= "<address1>" . $pdata["address"] . "</address1>";

		if (isset($pdata["address2"]))
			$xml .= "<address2>" . $pdata["address2"] . "</address2>";

		if (isset($pdata["city"]))
			$xml .= "<city>" . $pdata["city"] . "</city>";
			
		if (isset($pdata["state"]))
			$xml .= "<state>" . $pdata["state"] . "</state>";
			
		if (isset($pdata["zip"]))
			$xml .= "<zip>" . $pdata["zip"] . "</zip>";

		if (isset($pdata["country"]))
			$xml .= "<country>" . $pdata["country"] . "</country>";

		if (isset($pdata["userid"]))
			$xml .= "<userid>" . $pdata["userid"] . "</userid>";

		if (isset($pdata["email"]))
			$xml .= "<email>" . $pdata["email"] . "</email>";

		if (isset($pdata["phone"]))
			$xml .= "<phone>" . $pdata["phone"] . "</phone>";

		if (isset($pdata["fax"]))
			$xml .= "<fax>" . $pdata["fax"] . "</fax>";

		if (isset($pdata["addrnum"]))
			$xml .= "<addrnum>" . $pdata["addrnum"] . "</addrnum>";

		$xml .= "</billing>";
		### BILLING NODE ###
		
		## SHIPPING NODE ##
		$xml .= "<shipping>";

		if (isset($pdata["sname"]))
			$xml .= "<name>" . $pdata["sname"] . "</name>";

		if (isset($pdata["saddress1"]))
			$xml .= "<address1>" . $pdata["saddress1"] . "</address1>";

		if (isset($pdata["saddress2"]))
			$xml .= "<address2>" . $pdata["saddress2"] . "</address2>";

		if (isset($pdata["scity"]))
			$xml .= "<city>" . $pdata["scity"] . "</city>";

		if (isset($pdata["sstate"]))
			$xml .= "<state>" . $pdata["sstate"] . "</state>";

		if (isset($pdata["szip"]))
			$xml .= "<zip>" . $pdata["szip"] . "</zip>";

		if (isset($pdata["scountry"]))
			$xml .= "<country>" . $pdata["scountry"] . "</country>";

		if (isset($pdata["scarrier"]))
			$xml .= "<carrier>" . $pdata["scarrier"] . "</carrier>";

		if (isset($pdata["sitems"]))
			$xml .= "<items>" . $pdata["sitems"] . "</items>";

		if (isset($pdata["sweight"]))
			$xml .= "<weight>" . $pdata["sweight"] . "</weight>";

		if (isset($pdata["stotal"]))
			$xml .= "<total>" . $pdata["stotal"] . "</total>";

		$xml .= "</shipping>";
		## SHIPPING NODE ##
		
		### TRANSACTIONDETAILS NODE ###
		$xml .= "<transactiondetails>";

		if (isset($pdata["oid"]))
			$xml .= "<oid>" . $pdata["oid"] . "</oid>";

		if (isset($pdata["ponumber"]))
			$xml .= "<ponumber>" . $pdata["ponumber"] . "</ponumber>";

		if (isset($pdata["recurring"]))
			$xml .= "<recurring>" . $pdata["recurring"] . "</recurring>";

		if (isset($pdata["taxexempt"]))
			$xml .= "<taxexempt>" . $pdata["taxexempt"] . "</taxexempt>";

		if (isset($pdata["terminaltype"]))
			$xml .= "<terminaltype>" . $pdata["terminaltype"] . "</terminaltype>";

		if (isset($pdata["ip"]))
			$xml .= "<ip>" . $pdata["ip"] . "</ip>";

		if (isset($pdata["reference_number"]))
			$xml .= "<reference_number>" . $pdata["reference_number"] . "</reference_number>";

		if (isset($pdata["transactionorigin"]))
			$xml .= "<transactionorigin>" . $pdata["transactionorigin"] . "</transactionorigin>";

		if (isset($pdata["tdate"]))
			$xml .= "<tdate>" . $pdata["tdate"] . "</tdate>";

		$xml .= "</transactiondetails>";
		### TRANSACTIONDETAILS NODE ###
		
		### MERCHANTINFO NODE ###
		$xml .= "<merchantinfo>";

		if (isset($pdata["configfile"]))
			$xml .= "<configfile>" . $pdata["configfile"] . "</configfile>";

		if (isset($pdata["keyfile"]))
			$xml .= "<keyfile>" . $pdata["keyfile"] . "</keyfile>";

		if (isset($pdata["host"]))
			$xml .= "<host>" . $pdata["host"] . "</host>";

		if (isset($pdata["port"]))
			$xml .= "<port>" . $pdata["port"] . "</port>";

		if (isset($pdata["appname"]))
			$xml .= "<appname>" . $pdata["appname"] . "</appname>";

		$xml .= "</merchantinfo>";
		### MERCHANTINFO NODE ###
		
		### PAYMENT NODE ###
		$xml .= "<payment>";

		if (isset($pdata["chargetotal"]))
			$xml .= "<chargetotal>" . $pdata["chargetotal"] . "</chargetotal>";

		if (isset($pdata["tax"]))
			$xml .= "<tax>" . $pdata["tax"] . "</tax>";

		if (isset($pdata["vattax"]))
			$xml .= "<vattax>" . $pdata["vattax"] . "</vattax>";

		if (isset($pdata["shipping"]))
			$xml .= "<shipping>" . $pdata["shipping"] . "</shipping>";

		if (isset($pdata["subtotal"]))
			$xml .= "<subtotal>" . $pdata["subtotal"] . "</subtotal>";

		$xml .= "</payment>";
		### PAYMENT NODE ###
		
		$xml .= "</order>";
		
		return $xml;
	}
	
	/**
     * converts the LSGS response xml string
     * to a hash of name-value pairs
	 *
     * @param String $xml
     * @return Array $retarr
     */
	protected function _readResponse($xml) {
		preg_match_all ("/<(.*?)>(.*?)\</", $xml, $out, PREG_SET_ORDER);
		
		$n = 0;
		while (isset($out[$n])) {
			$retarr[$out[$n][1]] = strip_tags($out[$n][0]);
			$n++; 
		}
		
		return $retarr;
	}
	
	/**
     * process hash table or xml string table using cURL
	 *
     * @param Array $data
     * @return String $xml
     */
	protected function _postRequest($data) {
		$debugData = array('request' => $data);
		
		$xml='';
		
		// convert incoming hash to XML string
		$xml = $this->_buildRequest($data);
		
		//get Store Number from core config table
		
		$key			=	$data["keyfile"];
		$host			=	$data["host"];
		$port 			=	$data["port"];
		
		$host = "https://".$host.":".$port."/";
		
		$ch = curl_init ();
		curl_setopt ($ch, CURLOPT_URL,$host);
		curl_setopt ($ch, CURLOPT_VERBOSE, 1);
		curl_setopt ($ch, CURLOPT_POST, 1); 
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $xml);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt ($ch, CURLOPT_SSLCERT, $key);
		curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);
		
		$result = curl_exec ($ch);
		
		
		if (!$result) {
			Mage::throwException(ucwords(curl_error($ch)));
		}
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($httpcode && substr($httpcode, 0, 2) != "20") { //Unsuccessful post request...
			Mage::throwException("Returned HTTP CODE: " . $httpcode . " for this URL: " . $host);
		}
		curl_close($ch);
		
		#convert xml response to hash
		$retarr = $this->_readResponse($result);
		
		# log details
		$debugData['response']=$retarr;
		if($this->getConfigData('debug')==1) {
			$this->_debug($debugData);
		}
		
		# and send it back
		return ($retarr);
	}
	
	protected function _prepareData() {
		$data=array(
			'key'			=>	$this->getConfigData('pem_path_test'),
			'storenumber'	=>	$this->getConfigData('store_number_test'),
			'host'			=>	$this->getConfigData('host_test'),
			'port'			=>	$this->getConfigData('port_test')
		);
		
		if($this->getConfigData('test')==0) {
			$data['key']			=	$this->getConfigData('pem_path_live');
			$data['storenumber']	=	$this->getConfigData('store_number_live');
			$data['host']			=	$this->getConfigData('host_live');
			$data['port']			=	$this->getConfigData('port_live');
		}
		if(empty($data['key']) || empty($data['storenumber']) || empty($data['host']) || empty($data['port'])){
			Mage::throwException("Gateway Parameters Missing");
		}
		$data['terminaltype']	=	"UNSPECIFIED";
		return $data;
	}
}
?>