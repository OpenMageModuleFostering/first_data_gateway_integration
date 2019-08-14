<?php

class Raveinfosys_Linkpoint_Model_Soapclienthmac extends SoapClient
{

    public function __construct($wsdl, $options = NULL)
    {
        global $context;
        $context = stream_context_create();
        $options['stream_context'] = $context;
        return parent::SoapClient($wsdl['url'], $options);
    }

    public function __doRequest($request, $location, $action, $version, $one_way = NULL)
    {
        global $context;
        $hmacKey = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/linkpoint/hmac_key'));
        $keyId = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/linkpoint/key_id'));
        $hashtime = date("c");
        $hashstr = "POST\ntext/xml; charset=utf-8\n" . sha1($request) . "\n" . $hashtime . "\n" . parse_url($location, PHP_URL_PATH);
        $authstr = base64_encode(hash_hmac("sha1", $hashstr, $hmacKey, TRUE));
        if (version_compare(PHP_VERSION, '5.3.11') == -1) {
            ini_set("user_agent", "PHP-SOAP/" . PHP_VERSION . "\r\nAuthorization: GGE4_API " . $keyId . ":" . $authstr . "\r\nx-gge4-date: " . $hashtime . "\r\nx-gge4-content-sha1: " . sha1($request));
        } else {
            stream_context_set_option($context, array("http" => array("header" => "authorization: GGE4_API " . $keyId . ":" . $authstr . "\r\nx-gge4-date: " . $hashtime . "\r\nx-gge4-content-sha1: " . sha1($request))));
        }
        return parent::__doRequest($request, $location, $action, $version, $one_way);
    }

}
