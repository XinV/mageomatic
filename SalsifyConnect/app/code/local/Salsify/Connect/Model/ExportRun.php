<?php

/**
 * Represents a single export run for Salsify. This provides all state
 * persistence functionality around a Salsify export.
 *
 * A bunch of information is kept in the database, while temporary data is
 * kept on the filesystem in var/salsify/.
 */
class Salsify_Connect_Model_ExportRun extends Mage_Core_Model_Abstract {

  private static function _log($msg) {
    Mage::log('ExportRun: ' . $msg, null, 'salsify.log', true);
  }


  // cached handles on the helpers
  private $_config;
  private $_salsify_api;


  private $_export_file;


  const STATUS_ERROR                 = -1;
  const STATUS_NOT_STARTED           = 0;
  const STATUS_EXPORTING             = 1;
  const STATUS_EXPORTING_DONE        = 2;
  const STATUS_UPLOADING_TO_SALSIFY  = 3;
  const STATUS_UPLOAD_DONE           = 4;
  const STATUS_SALSIFY_LOADING       = 5;
  const STATUS_DONE                  = 6;
  public function get_status_string() {
    switch ($this->getStatus()) {
      case self::STATUS_ERROR:
        return "Error: Failed";
      case self::STATUS_NOT_STARTED:
        return "Export not started";
      case self::STATUS_EXPORTING:
        return "Magento is preparing the data for Salsify.";
      case self::STATUS_EXPORTING_DONE:
        return "Export file for Salsify generated. Preparing to upload.";
      case self::STATUS_UPLOADING_TO_SALSIFY:
        return "Uploading data to Salsify.";
      case self::STATUS_UPLOAD_DONE:
        return "Upload to Salsify has been completed";
      case self::STATUS_SALSIFY_LOADING:
        return "Waiting for Salsify to finish processing the export.";
      case self::STATUS_DONE:
        return "Export to Salsify has been completed successfully.";
      default:
        throw new Exception("INTERNAL ERROR: unknown status: " . $this->getStatus());
    }
  }

  private function _set_status($code) {
    $this->setStatus($code);
    $this->setStatusMessage($this->get_status_string());
  }


  public function set_error($e) {
    if (is_string($e)) {
      $e = new Exception($e);
    }
    self::_log("Setting export run status to error: " . $e->getMessage());
    $this->setEndTime(date('Y-m-d h:m:s', time()));
    $this->setStatus(self::STATUS_ERROR);
    $this->setStatusMessage('An error occurred export Magento to Salsify: ' . $e->getMessage());
    $this->save();
    throw $e;
  }


  protected function _construct() {
    $this->_init('salsify_connect/exportrun');

    // done implicitly by _get_salsify_api()
    // $this->_get_config();
    $this->_get_salsify_api();
    if (!$this->getStatus()) {
      $this->_set_status(self::STATUS_NOT_STARTED);
    }
  }


  // ensures that the Salsify account confguration is complete.
  // FIXME move somewhere else
  private function _get_config() {
    if (!$this->_config) {
      $this->_config = Mage::getModel('salsify_connect/configuration')
                           ->getInstance();
      if (!$this->_config->getApiKey() || !$this->_config->getUrl()) {
        $this->set_error("you must first configure your Salsify account information.");
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
    }
    return $this->_salsify_api;
  }


  // creates the export document for Salsify.
  public function create_export_file() {
    if ($this->getStatus() !== self::STATUS_NOT_STARTED) {
      $this->set_error("cannot create an export file when the ExportRun is not new.");
    }

    $this->_set_status(self::STATUS_EXPORTING);
    $this->setStartTime(date('Y-m-d h:m:s', time()));
    $this->save();

    try {
      $salsify = Mage::helper('salsify_connect');
      $this->_export_file = $salsify->export_data();
    } catch (Exception $e) {
      $this->set_error($e);
    }

    $this->_set_status(self::STATUS_EXPORTING_DONE);
    $this->save();
  }


  // uploads the prepared export document to Salsify.
  public function upload_to_salsify() {
    if ($this->getStatus() !== self::STATUS_EXPORTING_DONE) {
      $this->set_error("cannot start uploading to Salsify until the file has been exported");
    }

    $this->_set_status(self::STATUS_UPLOADING_TO_SALSIFY);
    $this->save();

    $success = $this->_salsify_api->export_to_salsify($this->_export_file);
    if (!$success) {
      $this->set_error("export of file to Salsify failed: " . $file);
    }

    $this->_set_status(self::STATUS_UPLOAD_DONE);
    $this->save();
  }


  // polls Salsify to see whether it has finished processing the given export.
  public function wait_for_salsify_to_complete() {
    if ($this->getStatus() !== self::STATUS_UPLOAD_DONE) {
      $this->set_error("file not yet uploaded to Salsify. cannot wait for it to complete.");
    }

    $this->_set_status(self::STATUS_SALSIFY_LOADING);
    $this->save();

    // FIXME implement the wait polling

    $this->setEndTime(date('Y-m-d h:m:s', time()));
    $this->_set_status(self::STATUS_DONE);
    $this->save();
  }
}