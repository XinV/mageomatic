<?php
require_once BP.DS.'lib'.DS.'JsonStreamingParser'.DS.'Parser.php';

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


  // TODO need more complete API?


  public function load_data($file) {
    $this->_ensure_import_dir();

    $stream = fopen($file, 'r');
    try {
      $importer = Mage::helper('salsify_connect/importer');
      $parser = new \JsonStreamingParser\Parser($stream, $importer);
      $parser->parse();
    } catch (Exception $e) {
      fclose($stream);
      throw $e;
    }

    // TODO return some stats about the amount of data loaded.
  }


  // It is so silly that Magento doesn't create this itself when it's core
  // Import/Export library requires it...
  private function _ensure_import_dir() {
    $importdir = BP.DS.'media'.DS.'import';
    if (!file_exists($importdir)) {
      mkdir($importdir);
      chmod($importdir, 0777);
    }
  }

}