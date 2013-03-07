<?php
require_once BP.DS.'lib'.DS.'JsonStreamingParser'.DS.'Parser.php';

/**
 * Helper class for Salsify Connect that does the heavy lifting, including
 * orchestrating downloading of Salsify Data, parsing the downloaded documents,
 * and saving to the database. In a real way, this "helper" is the heart of the
 * Salsify Connect module.
 */
class Salsify_Connect_Helper_Data extends Mage_Core_Helper_Abstract {
  private static function _log($msg) {
    Mage::log('Data: ' . $msg, null, 'salsify.log', true);
  }


  // Salsify Configuration model instance. contains all the URL and login data
  // required for communicating with the Salsify server.
  private $_config;


  // cached version of the importer. we have to keep this around since classes
  // relying on this helper will need access to digital assets.
  private $_importer;


  /**
   * @param $configuration Salsify Connect Configuration model instance.
   */
  public function set_configuration($configuration) {
    $this->_config = $configuration;
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


  /**
   * Ensures that the Salsify temp directory exists in var/ and returns it.
   */
  private function _get_temp_directory() {
    // thanks http://stackoverflow.com/questions/8708718/whats-the-best-place-to-put-additional-non-xml-files-within-the-module-file-str/8709462#8709462
    $dir = Mage::getBaseDir('var') . DS . 'salsify';
    if (!file_exists($dir)) {
      mkdir($dir);
      chmod($dir, 0777);
    } elseif (!is_dir($dir)) {
      throw new Exception($dir . " already exists and is not a directory. Cannot proceed.");
    }
    return $dir;
  }


  /**
   * Returns the name of a temp file that does not exist and so can be used for
   * storing data.
   */
  public function get_temp_file($prefix, $extension) {
    $dir = $this->_get_temp_directory();
    $file = $dir . DS . $prefix . '-' . date('Y-m-d') . '-' . round(microtime(true)) . '.' . $extension;
    return $file;
  }


  // loads the data from the given file, which should be a valid Salsify json
  // document.
  public function import_data($file) {
    $this->_ensure_import_dir();

    $stream = fopen($file, 'r');
    try {
      $this->_importer = Mage::helper('salsify_connect/importer');
      $parser = new \JsonStreamingParser\Parser($stream, $this->_importer);
      $parser->parse();
    } catch (Exception $e) {
      fclose($stream);
      throw $e;
    }
    fclose($stream);

    // TODO return some stats about the amount of data loaded.
  }


  // returns the Salsify importer used to load data. only really used to get a
  // handle on digital assets that were seen but not loaded during the parsing
  // of the document.
  public function get_importer() {
    if (!$this->_importer) {
      throw new Exception("ERROR: cannot get_importer until import_data is called.");
    }
    return $this->_importer;
  }


  // dumps all the data in Magento in a Salsify json document. returns the
  // filename of the document created.
  //
  // TODO move some/all of this to a background job
  public function export_data($salsify_url, $salsify_api_key) {
    self::_log("exporting Magento data into file for loading into Salsify.");

    $file = $this->get_temp_file('export','json');
    $stream = fopen($file,'w');
    try {
      $exporter = Mage::helper('salsify_connect/exporter');
      $exporter->export($stream);
    } catch (Exception $e) {
      fclose($stream);
      throw $e;
    }
    fclose($stream);

    self::_log("Exporting to file complete. Now need to send to Salsify: " . $file);
    $salsify = Mage::helper('salsify_connect/salsifyapi');
    $salsify->set_base_url($salsify_url);
    $salsify->set_api_key($salsify_api_key);
    return $salsify->export_to_salsify($file);
  }


  // returns an instance of the attribute mapping model, which is the primary
  // interface between this loader and the Magento attribute database structure.
  public function get_attribute_mapper() {
    return Mage::getModel('salsify_connect/attributemapping');
  }

}