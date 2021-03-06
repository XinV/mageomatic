<?php
/**
 * Salsify configuration, such as API key used to access Salsify.
 *
 * At the moment, this is a singleton, so does not support multi-store sites
 * if those sites have multiple Salsify accounts for data segmentation.
 *
 * TODO move to Mage::getSingleton for all references to this class
 */
class Salsify_Connect_Model_Configuration
      extends Mage_Core_Model_Abstract
{
  private static function _log($msg) {
    Mage::log("Salsify_Connect_Model_Configuration" . ': ' . $msg, null, 'salsify.log', true);
  }


  // The default URL location of the Salsify app.
  const DEFAULT_SALSIFY_URL = 'https://app.salsify.com/';


  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/configuration');
  }


  /**
   * @return the singleton instance of the configuration, creating if necessary.
   */
  public function getInstance() {
    $config = $this->getCollection()
                   ->getFirstItem();

    if ($config->getId()) {
      return $config;
    }

    // need to create the singleton
    $config->setUrl(self::DEFAULT_SALSIFY_URL);
    $config->save();
    return $config;
  }

}