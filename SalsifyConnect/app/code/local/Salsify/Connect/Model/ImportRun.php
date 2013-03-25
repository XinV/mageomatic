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

  private function _log($msg) {
    Mage::log('ImportRun: ' . $msg, null, 'salsify.log', true);
  }


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
        return "Salsify is preparing the data.";
      case self::STATUS_DOWNLOAD_JOB_IN_QUEUE:
        return "Download job is in the queue waiting to start.";
      case self::STATUS_DOWNLOADING:
        return "Magento is downloading the data from Salsify.";
      case self::STATUS_LOADING:
        return "Magento is loading the local Salsify data.";
      case self::STATUS_DONE:
        return "Import from Salsify has been successfully loaded into Magento.";
      default:
        throw new Exception("INTERNAL ERROR: unknown status: " . $this->getStatus());
    }
  }

  public function set_error($e) {
    $this->_log("Setting import run status to error: " . $e->getMessage());
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
      $import = $salsify_api->create_import();
    } catch (Exception $e) {
      $this->set_error($e);
    }

    $this->setToken($import['id']);
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

      $import = $this->_get_salsify_api()
                     ->get_import($this->getToken());
      if ($import['processing']) { return false; }
      $url = $import['url'];
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
                           ->load($this->getInstance());
      if (!$this->_config->getId()) {
        throw new Exception("you must first configure your Salsify account information.");
      }
    }
    return $this->_config;
  }

  private function _get_salsify_api() {
    if (!$this->_salsify_api) {
      $config = $this->_get_config();
      // FIXME remove this since we're working as a singleton now
      $this->_salsify_api = Mage::helper('salsify_connect/salsifyapi');
      $this->_salsify_api->set_base_url($config->getUrl());
      $this->_salsify_api->set_api_key($config->getApiKey());
      $token = $this->getToken();
    }
    return $this->_salsify_api;
  }

  private function async_download($import_run_id, $url) {
    $file = Mage::helper('salsify_connect')
                ->get_temp_file('import','json');

    $job = Mage::getModel('salsify_connect/importjob');
    $job->setName('Download for Import Job ' . $import_run_id)
        ->setImportRunId($import_run_id)
        ->setUrl($url)
        ->setFilename($file)
        ->enqueue();
  }

}