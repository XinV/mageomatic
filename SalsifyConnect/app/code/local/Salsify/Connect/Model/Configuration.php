<?php
/**
 * Salsify configuration, such as API key used to access Salsify.
 *
 * At the moment, this is a singleton, so does not support multi-store sites
 * if those sites have multiple Salsify accounts for data segmentation.
 */
class Salsify_Connect_Model_Configuration extends Mage_Core_Model_Abstract {

  private static function _log($msg) {
    Mage::log('Configuration: ' . $msg, null, 'salsify.log', true);
  }

  protected function _construct() {
    $this->_init('salsify_connect/configuration');
  }

  /**
   * @return the singleton instance of the configuration, creating if necessary.
   */
  public function getInstance() {
    $configurations = $this->getCollection();
    $config = $configurations->getFirstItem();
    if ($config->getId()) {
      return $config;
    } else {
      $salsify = Mage::helper('salsify_connect/salsifyapi');
      $config->setUrl($salsify::DEFAULT_SALSIFY_URL);
      $config->save();
      return $this;
    }
  }

}