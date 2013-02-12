<?php
class Salsify_Connect_Model_ImportRun extends Mage_Core_Model_Abstract {

  private $_config;
  private $_downloader;

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
      $downloader = $this->_get_downloader();
      $export = $downloader->create_export();
    } catch (Exception $e) {
      $this->set_error();
    }
    $this->setToken($export->id);
    $this->save();
  }

  // Return whether the status was advanced to downloading state.
  public function start_download_if_ready() {
    $status = (int)$this->getStatus();

    if ($status === self::STATUS_SALSIFY_PREPARING) {
      // we were waiting for a public URL signally that Salsify has prepared the
      // download.

      $downloader = $this->_get_downloader();
      $export = $downloader->get_export($this->getToken());
      if ($export->processing) { return false; }
      $url = $export->url;
      if (!$url) {
        $this->set_error(new Exception("Processing done but no public URL. Check for errors with Salsify administrator. Export job ID: " . $this.getToken()));
      }
      $this->setStatus(self::STATUS_DOWNLOAD_JOB_IN_QUEUE);
      $this->save();
      $downloader->async_download($this->getId(), $url);

      return true;
    } else {
      return false;
    }
  }

  public function set_download_started() {
    $this->setStatus(self::STATUS_DOWNLOADING);
    $this->save();
  }

  public function set_download_complete($filename) {
    if ($this->getStatus() !== self::STATUS_DOWNLOADING) {
      throw new Exception("Cannot set_download_complete unless you are downloading.");
    }

    $this->setStatus(self::STATUS_LOADING);
    $this->save();
    $salsify = Mage::helper('salsify_connect');

    // FIXME load data asynchronously
    $salsify->load_data($filename);

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

  private function _get_downloader() {
    if (!$this->_downloader) {
      $config = $this->_get_config();
      $this->_downloader = Mage::helper('salsify_connect/downloader');
      $this->_downloader->set_base_url($config->getUrl());
      $this->_downloader->set_api_key($config->getApiKey());
      $token = $this->getToken();
    }
    return $this->_downloader;
  }

}