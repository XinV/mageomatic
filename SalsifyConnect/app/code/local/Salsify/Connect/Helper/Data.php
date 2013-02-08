<?php

require_once Mage::getBaseDir('lib').DS.'JsonDataStreamer'.DS.'Parser.php';

/**
 * Helper class for Salsify Connect that does the heavy lifting, including
 * orchestrating downloading of Salsify Data, parsing the downloaded documents,
 * and saving to the database. In a real way, this "helper" is the heart of the
 * Salsify Connect module.
 */
class Salsify_Connect_Helper_Data extends Mage_Core_Helper_Abstract {

  private $_config;

  
  /**
   * @param $configuration Salsify Connect Configuration model instance.
   */
  public function __construct($configuration) {
    $this->_config = $configuration;
  }




}