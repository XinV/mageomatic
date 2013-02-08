<?php

// TODO clear out temp directory
// TODO save file location in data model?

/**
 * Helper class that takes care of downloading a data dump from Salsify to a
 * local Magento directory.
 */
class Salsify_Connect_Helper_Downloader {


  public function __construct() {
    // FIXME accept an API token
  }

  /**
   * Returns the path to the locally downloaded file.
   */
  public function download() {
    // FIXME
    return $this->_ensure_temp_directory();
  }

  private function _ensure_temp_directory() {
    $magento_root = dirname(dirname(__FILE__)).'/../../../../../../'
    return $magento_root;
  }


}