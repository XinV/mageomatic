<?php
class Salsify_Connect_Model_ImportRun extends Mage_Core_Model_Abstract {

  private $_config;
  private $_downloader;

  const STATUS_ERROR       = -1;
  const STATUS_NOT_STARTED = 0;
  const STATUS_PREPARING   = 1;
  const STATUS_DOWNLOADING = 2;
  const STATUS_LOADING     = 3;
  const STATUS_DONE        = 4;
  public function get_status_string() {
    switch ($this->getStatus()) {
      case self::STATUS_ERROR:
        return "Error: Failed";
      case self::STATUS_NOT_STARTED:
        return "Export not started";
      case self::STATUS_PREPARING:
        return "Salsify is preparing the export.";
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

  private function _set_error($e) {
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
    $this->setStatus(self::STATUS_PREPARING);
    $this->setStartTime(date('Y-m-d h:m:s', time()));
    $this->save();
    
    try {
      $downloader = $this->_get_downloader();
      $export = $downloader->create_export();
    } catch (Exception $e) {
      $this->_set_error();
    }
    $this->setToken($export->id);
    $this->setStatus(self::STATUS_PREPARING);
    $this->save();
  }

  // Return whether the status was advanced.
  public function update_status_if_ready() {
    $status = $this->getStatus();
    if ($status === self::STATUS_NOT_STARTED ||
        $status === self::STATUS_DOWNLOADING || // TODO remove when async
        $status === self::STATUS_LOADING || // TODO remove when async
        $status === self::STATUS_ERROR ||
        $status === self::STATUS_DONE) {
      return false;
    }

    $filename = null;
    try {
      $downloader = $this->_get_downloader();
      $export = $downloader->get_export($this->getToken());

      if ($status === self::STATUS_PREPARING) {
        // we were waiting for a public URL
        echo "HERE";
        if ($export->processing) { echo "THERE"; return false; }
        $url = $export->url;
        if (!$url) {
          $this->_set_error(new Exception("Processing done but no public URL. Check for errors with Salsify administrator. Export job ID: " . $this.getToken()));
        }

        // we have the URL, time to start downloading
        // TODO can we do this asynchronously?

        // FIXME this is ghetto and should be removed
        echo '<br/>Download is ready. Downloading...<br/>';

        $this->setStatus(self::STATUS_DOWNLOADING);
        $this->save();
        $filename = $downloader->download($url);

        // FIXME this is ghetto and should be removed
        echo '<br/>Download successful. Loading data...<br/>';

        $this->setStatus(self::STATUS_LOADING);
        $this->save();
        $salsify = Mage::helper('salsify_connect');
        $salsify->load_data($file);

        echo '<br/>Done!</br>';
        $this->setStatus(self::STATUS_DONE);
        $this->save();
      }

    } catch (Exception $e) {
      if ($filename && file_exists($filename)) {
        unlink($filename);
      }
      $this->_set_error();
    }
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