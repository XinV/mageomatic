<?php

/**
 * Represents a single import run for Salsify. This provides all state
 * persistence functionality around a Salsify import.
 *
 * A bunch of information is kept in the database, while temporary data is
 * kept on the filesystem in var/salsify/.
 *
 * TODO clean out the temp directory every so often (maybe once it has, say, 3
 *      files in it? could make that configurable).
 */
class Salsify_Connect_Model_ImportRun extends Mage_Core_Model_Abstract {

  private $_config;
  private $_salsify_api;

  const STATUS_ERROR                 = -1;
  const STATUS_NOT_STARTED           = 0;
  const STATUS_SALSIFY_PREPARING     = 1;
  const STATUS_DOWNLOAD_JOB_IN_QUEUE = 2;
  const STATUS_DOWNLOADING           = 3;
  const STATUS_LOADING               = 4;
  const STATUS_DONE                  = 5;
  public function get_status_string() {
    switch ($this->getStatus()) {
      case self::STATUS_ERROR:
        return "Error: Failed";
      case self::STATUS_NOT_STARTED:
        return "Export not started";
      case self::STATUS_SALSIFY_PREPARING:
        return "Salsify is preparing the export.";
      case self::STATUS_DOWNLOAD_JOB_IN_QUEUE:
        return "Download job is in the queue waiting to start.";
      case self::STATUS_DOWNLOADING:
        return "Magento is downloading the export.";
      case self::STATUS_LOADING:
        return "Magento is loading the exported data.";
      case self::STATUS_DONE:
        return "Salsify export has been successfully loaded into Magento.";
      default:
        throw new Exception("INTERNAL ERROR: unknown status: " . $this->getStatus());
    }
  }

  public function set_error($e) {
    $this->setStatus(self::STATUS_ERROR);
    $this->save();
    throw $e;
  }

  protected function _construct() {
    if (!$this->getStatus()) {
      $this->setStatus(self::STATUS_NOT_STARTED);
    }
    $this->_init('salsify_connect/importrun');
  }

  public function start_import() {
    $this->setStatus(self::STATUS_SALSIFY_PREPARING);
    $this->setStartTime(date('Y-m-d h:m:s', time()));
    try {
      $salsify_api = $this->_get_salsify_api();
      $export = $salsify_api->create_export();
    } catch (Exception $e) {
      $this->set_error($e);
    }
    var_dump($export);
    // $this->setToken($export['id']);
    $this->save();
  }

  public function is_done() {
    return ((int)$this->getStatus() === self::STATUS_DONE);
  }

  public function is_waiting_on_salsify() {
    return ((int)$this->getStatus() === self::STATUS_SALSIFY_PREPARING);
  }

  public function is_waiting_on_worker() {
    return ((int)$this->getStatus() === self::STATUS_DOWNLOAD_JOB_IN_QUEUE);
  }

  // Return whether the status was advanced to downloading state.
  public function start_download_if_ready() {
    $status = (int)$this->getStatus();

    if ($status === self::STATUS_SALSIFY_PREPARING) {
      // we were waiting for a public URL signally that Salsify has prepared the
      // download.

      $export = $this->_get_salsify_api()->get_export($this->getToken());
      if ($export->processing) { return false; }
      $url = $export->url;
      if (!$url) {
        $this->set_error(new Exception("Processing done but no public URL. Check for errors with Salsify administrator. Export job ID: " . $this.getToken()));
      }
      
      $this->async_download($this->getId(), $url);
      $this->setStatus(self::STATUS_DOWNLOAD_JOB_IN_QUEUE);
      $this->save();

      return true;
    } else {
      return false;
    }
  }

  public function set_download_started() {
    $this->setStatus(self::STATUS_DOWNLOADING);
    $this->save();
  }

  public function set_download_complete() {
    if ($this->getStatus() !== self::STATUS_DOWNLOADING) {
      throw new Exception("Cannot set_download_complete unless you are downloading.");
    }

    $this->setStatus(self::STATUS_LOADING);
    $this->save();
  }

  public function set_loading_complete() {
        if ($this->getStatus() !== self::STATUS_LOADING) {
      throw new Exception("Cannot set_loading_complete unless you are downloading.");
    }

    $this->setStatus(self::STATUS_DONE);
    $this->save();
  }

  private function _get_config() {
    if (!$this->_config) {
      $this->_config = Mage::getModel('salsify_connect/configuration')
                           ->load($this->getConfigurationId());
      if (!$this->_config->getId()) {
        throw new Exception("Must first specify a valid import configuration.");
      }
    }
    return $this->_config;
  }

  private function _get_salsify_api() {
    if (!$this->_salsify_api) {
      $config = $this->_get_config();
      $this->_salsify_api = Mage::helper('salsify_connect/salsifyapi');
      $this->_salsify_api->set_base_url($config->getUrl());
      $this->_salsify_api->set_api_key($config->getApiKey());
      $token = $this->getToken();
    }
    return $this->_salsify_api;
  }

  private function async_download($import_run_id, $url) {
    $job = Mage::getModel('salsify_connect/importjob');
    $job->setName('Download for Import Job ' . $import_run_id)
        ->setImportRunId($import_run_id)
        ->setUrl($url)
        ->setFilename($this->_get_temp_file('json'))
        ->enqueue();
  }

  /**
   * Returns the name of a temp file that does not exist and so can be used for
   * storing data.
   */
  private function _get_temp_file($extension) {
    $dir = $this->_get_temp_directory();
    $file = $dir . DS . 'data-' . date('Y-m-d') . '-' . round(microtime(true)) . '.' . $extension;
    return $file;
  }

  /**
   * Ensures that the Salsify temp directory exists in var/
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

}