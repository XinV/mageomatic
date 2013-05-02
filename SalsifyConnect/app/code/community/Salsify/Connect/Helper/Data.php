<?php
require_once BP.DS.'lib'.DS.'salsify'.DS.'JsonStreamingParser'.DS.'Parser.php';

set_include_path(get_include_path().PS.Mage::getBaseDir('lib').DS.'salsify'.DS.'DJJob');
require_once('DJJob.php');

/**
 * Helper class for Salsify Connect that does the heavy lifting, including
 * orchestrating downloading of Salsify Data, parsing the downloaded documents,
 * and saving to the database. In a real way, this "helper" is the heart of the
 * Salsify Connect module.
 */
class Salsify_Connect_Helper_Data
      extends Mage_Core_Helper_Abstract
{

  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }


  // Salsify Configuration model instance. contains all the URL and login data
  // required for communicating with the Salsify server.
  private $_config;


  // cached version of the importer. we have to keep this around since classes
  // relying on this helper will need access to digital assets.
  private $_importer;


  // It is so silly that Magento doesn't create this itself when it's core
  // Import/Export library requires it...
  private function _ensure_import_dir() {
    $importdir = BP.DS.'media'.DS.'import';
    if (!file_exists($importdir)) {
      mkdir($importdir);
      chmod($importdir, 0777);
    }
  }


  // Ensures that the Salsify temp directory exists in var/ and returns it.
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
   * @return the name of a temp file that does not exist and so can be used for
   *         storing data.
   */
  public function get_temp_file($prefix, $extension) {
    $dir = $this->_get_temp_directory();
    $file = $dir . DS . $prefix . '-' . date('Y-m-d') . '-' . round(microtime(true)) . '.' . $extension;
    return $file;
  }


  private function _setup_delayed_jobs() {
    $config  = Mage::getConfig()->getResourceConnectionConfig("default_setup");
    DJJob::configure("mysql:host=" . $config->host . ";dbname=" . $config->dbname . ";port=" . $config->port,
                     array('mysql_user' => $config->username, 'mysql_pass' => $config->password));
  }


  public function start_worker() {
    $this->_setup_delayed_jobs();

    $options = array();
    $options['queue'] = 'salsify';
    $options['count'] = 1; // this worker will quit after doing one job
    $worker = new DJWorker($options);
    $worker->start();
  }


  public function enqueue_job($job) {
    $this->_setup_delayed_jobs();
    DJJob::enqueue($job, 'salsify');
  }


  // loads the data from the given file, which should be a valid Salsify json
  // document.
  public function import_data($file) {
    $this->_ensure_import_dir();

    $stream = fopen($file, 'r');
    try {
      $this->_importer = Mage::helper('salsify_connect/importer');
      // Removing since PHP 5.2 doesn't support namespaces...
      // $parser = new \JsonStreamingParser\Parser($stream, $this->_importer);
      $parser = new Parser($stream, $this->_importer);
      $parser->parse();
    } catch (Exception $e) {
      fclose($stream);
      throw $e;
    }
    fclose($stream);
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
  public function export_data() {
    self::_log("exporting Magento data into file for loading into Salsify.");

    $config = Mage::getModel('salsify_connect/configuration')->getInstance();
    $salsify_api_key = $config->getApiKey();
    $salsify_url = $config->getUrl();

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

    self::_log("Exporting to file complete: " . $file);
    return $file;
  }


  // returns an instance of the attribute mapping model, which is the primary
  // interface between this loader and the Magento attribute database structure.
  private $_attribute_mapper;
  public function get_attribute_mapper() {
    if (!$this->_attribute_mapper) {
      $this->_attribute_mapper = Mage::getModel('salsify_connect/attributemapping');
    }
    return $this->_attribute_mapper;
  }


  // returns an instance of the accessory mapping model.
  private $_accessory_mapper;
  public function get_accessory_mapper() {
    if (!$this->_accessory_mapper) {
      $this->_accessory_mapper = Mage::getModel('salsify_connect/accessorymapping');
    }
    return $this->_accessory_mapper;
  }


  // useful helper function for downloading the file at the given URL to the
  // specified local file location.
  public function download_file($url, $filename) {
    self::_log("starting download from " . $url . " to: " . $filename);

    $file = null;
    try {
      $ch = curl_init($url);
      $file = fopen($filename, "wb");
      curl_setopt($ch, CURLOPT_FILE, $file);
      curl_setopt($ch, CURLOPT_HEADER, 0);
      curl_exec($ch);
      curl_close($ch);
      fclose($file);
    } catch (Exception $e) {
      if ($file) { fclose($file); }
      unlink($filenmae);
      throw $e;
    }

    self::_log("download successful. Local file: " . $filename);
    return $filename;
  }
}