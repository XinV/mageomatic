<?php

/**
 * Represents a single import run for Salsify. This provides all state
 * persistence functionality around a Salsify import.
 *
 * A bunch of information is kept in the database, while temporary data is
 * kept on the filesystem in var/salsify/.
 */
class Salsify_Connect_Model_ImportRun
      extends Salsify_Connect_Model_SyncRun
{

  // parent class has other statuses.
  const STATUS_SALSIFY_PREPARING      = 1;
  const STATUS_DOWNLOADING            = 2;
  const STATUS_LOADING                = 3;
  const STATUS_LOADING_DIGITAL_ASSETS = 4;

  // overrides abstract parent method.
  public function get_status_string() {
    switch ($this->getStatus()) {
      case self::STATUS_ERROR:
        return "Error: Failed";
      case self::STATUS_NOT_STARTED:
        return "Import not started";
      case self::STATUS_SALSIFY_PREPARING:
        return "Salsify is preparing the data.";
      case self::STATUS_DOWNLOADING:
        return "Magento is downloading the data from Salsify.";
      case self::STATUS_LOADING:
        return "Magento is loading the local Salsify data.";
      case self::STATUS_LOADING_DIGITAL_ASSETS:
        return "Downloading and loading digital assets into Magento.";
      case self::STATUS_DONE:
        return "Import from Salsify to Magento completed successfully.";
      default:
        throw new Exception("INTERNAL ERROR: unknown status: " . $this->getStatus());
    }
  }


  protected function _construct() {
    $this->_init('salsify_connect/importrun');
    parent::_construct();
  }


  // does the entire import
  public function perform() {
    $this->_ensure_complete_salsify_configuration();
    if (!$this->getId()) {
      $this->set_error("must initialize ImportRun before running in a background job.");
    }

    # get handles on the helpers that we're going to need.
    $salsify = Mage::helper('salsify_connect');
    $salsify_api = $this->_get_salsify_api();

    try {
      // 0) create the export in salsify.
      self::_log("waiting for Salsify to prepare export document.");
      $this->_set_status(self::STATUS_SALSIFY_PREPARING);
      $this->_set_start_time();
      $this->save();
      $token = $salsify_api->create_import();
      $this->setToken($token);
      $this->save();
      $url = $salsify_api->wait_for_salsify_to_finish_preparing_export($token);

      // 1) fetch data from salsify
      self::_log("downloading export document from Salsify.");
      $this->_set_status(self::STATUS_DOWNLOADING);
      $this->save();
      $filename = $salsify->get_temp_file('import','json');
      $filename = $salsify->download_file($url, $filename);

      // 2) parse file and load into Magento
      self::_log("loading Salsify export document into Magento.");
      $this->_set_status(self::STATUS_LOADING);
      $this->save();
      $salsify->import_data($filename);

      // 3) download and load digital assets
      self::_log("downloading digital assets from Salsify into Magento.");
      $this->_set_status(self::STATUS_LOADING_DIGITAL_ASSETS);
      $this->save();
      $importer = $salsify->get_importer();
      $image_mapper = Mage::getModel('salsify_connect/imagemapping');
      $image_mapper::load_digital_assets($importer->get_digital_assets());

      // DONE!
      $this->_set_done();
    } catch (Exception $e) {
      $this->set_error($e);
    }
  }
}