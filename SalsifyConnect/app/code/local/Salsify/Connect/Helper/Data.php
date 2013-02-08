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
  public function set_configuration($configuration) {
    $this->_config = $configuration;
  }


  // FIXME need more complete API


  public function load_data($file) {
    $stream = fopen($file, 'r');
    try {
      $loader = Mage::helper('salsify_connect/loader');
      $parser = new \JsonDataStreamer\Parser($stream, $loader);
      $parser->parse();
    } catch (Exception $e) {
      fclose($file);
      throw $e;
    }

    // TODO return some stats about the amount of data loaded.
  }
}