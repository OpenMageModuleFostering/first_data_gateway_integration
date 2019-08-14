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
class Raveinfosys_Linkpoint_Model_Observer {
	public function disableMethod(Varien_Event_Observer $observer){
		$moduleName="Raveinfosys_Linkpoint";
		if('linkpoint'==$observer->getMethodInstance()->getCode()){
			if(!Mage::getStoreConfigFlag('advanced/modules_disable_output/'.$moduleName)) {
				//nothing here, as module is ENABLE
			} else {
				$observer->getResult()->isAvailable=false;
			}
			
		}
	}
}
?>