<?php

// TODO clear out temp directory
// TODO save file location in data model?

/**
 * Helper class that takes care of downloading a data dump from Salsify to a
 * local Magento directory.
 */
class Salsify_Connect_Helper_Downloader extends Mage_Core_Helper_Abstract {

  public function __construct() {
    // FIXME accept an API token
  }

  /**
   * Returns the path to the locally downloaded file.
   */
  public function download() {
    // FIXME
    return $this->_get_temp_file('json');
  }

  /**
   * Returns the name of a temp file that does not exist and so can be used for
   * storing data.
   */
  private function _get_temp_file($extension) {
    $dir = $this->_get_temp_directory();
    $file = $dir . '/data-' . date('Y-m-d') . '-' . round(microtime(true)) . '.' . $extension;
    return $file;
  }

  /**
   * Ensures that the Salsify temp directory exists in var/
   */
  private function _get_temp_directory() {
    // thanks http://stackoverflow.com/questions/8708718/whats-the-best-place-to-put-additional-non-xml-files-within-the-module-file-str/8709462#8709462
    $dir = Mage::getBaseDir('var') . '/salsify';
    if (!file_exists($dir)) {
      mkdir($dir);
      chmod($dir, 0777);
    } elseif (!is_dir($dir)) {
      throw new Exception($dir . " already exists and is not a directory. Cannot proceed.");
    }
    return $dir;
  }

}