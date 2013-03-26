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
class Salsify_Connect_Model_ImportRun extends Salsify_Connect_Model_SyncRun {

  const STATUS_SALSIFY_PREPARING      = 1;
  const STATUS_DOWNLOADING            = 2;
  const STATUS_LOADING                = 3;
  const STATUS_LOADING_DIGITAL_ASSETS = 4;

  public function get_status_string() {
    switch ($this->getStatus()) {
      case self::STATUS_ERROR:
        return "Error: Failed";
      case self::STATUS_NOT_STARTED:
        return "Export not started";
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


  // called by DJJob worker
  public function perform() {
    self::_log("background import job started.");

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
      $digital_assets = $this->_load_digital_assets($importer->get_digital_assets());

      // DONE!
      $this->_set_status(self::STATUS_DONE);
      $this->save();
    } catch (Exception $e) {
      $this->set_error($e);
    }
  }


  // note: format of $digital_assets is basically exactly what you would expect
  // in a salsify import, except that the keys of the array are the skus of the
  // product to which the digital asset is associated.
  // e.g. something like (excuse the mixture of json & php syntaxes here):
  // ['12345'=>{"url":"https://salsify-development.s3.amazonaws.com/rgonzalez/uploads/digital_asset/asset/2/2087913-5311.jpg",
  //   "name":"2087913-5311.jpg","is_primary_image":"true"},...]
  //
  // Thanks http://stackoverflow.com/questions/8456954/magento-programmatically-add-product-image
  //
  // TODO images coming from salsify should have an external ID or something to
  //      uniquely identify them between runs. what if someone updates the name
  //      and then we do another import?
  //
  // TODO get images for different digital asset roles (thumbnail vs. image,
  //      etc.) from salsify.
  private function _load_digital_assets($digital_assets) {
    if (!$digital_assets || empty($digital_assets)) {
      self::_log("no digital assets passed into process.");
      return null;
    }

    // our salsify data helper has the download capability.
    $salsify = Mage::helper('salsify_connect');

    foreach ($digital_assets as $sku => $das) {
      $id = Mage::getModel('catalog/product')
                ->loadByAttribute('sku', $sku)
                ->getId();
      // this second load is necessary to have access to the media gallery
      $product = Mage::getModel('catalog/product')
                     ->load($id);

      foreach ($das as $da) {
        $url = $da['url'];
        $filename = $this->_get_local_filename_for_image($sku, $da);
        try {
          if (file_exists($filename)) {
            self::_log('local file already exists for product ' . $sku . ' from ' . $url);
          } else {
            $salsify->download_file($url, $filename);
            self::_log('successfully downloaded image for ' . $sku . ' from ' . $url . ' to ' . $filename);
          }


          // TODO once we get a unique identifier from Salsify, we need to
          //      create a new attribute for the image gallery to keep track
          //      of whether or not we've imported the product already.
          //
          // TODO should see the code that's currently in the controller on how
          //      to cycle through a product's images to check this.


          // http://docs.magentocommerce.com/Mage_Catalog/Mage_Catalog_Model_Product.html#addImageToMediaGallery
          // TODO the second argument should be set specifically to thumbnail or
          //      small_image if that data comes from Salsify. also we should
          //      only set 'image' for those that have 'profile_image' set to true
          $product->addImageToMediaGallery($filename, array('image'), true, false);

          if (array_key_exists('name', $da)) {
            // this is terrible. thanks:
            // http://stackoverflow.com/questions/7215105/magento-set-product-image-label-during-import
            $gallery = $product->getData('media_gallery');
            $last_image = array_pop($gallery['images']);
            $last_image['label'] = $da['name'];
            array_push($gallery['images'], $last_image);
            $product->setData('media_gallery', $gallery);
          }

          $product->save();
        } catch (Exception $e) {
          self::_log("WARNING: could not load digital asset. skipping: " . $e->getMessage());
          self::_log("       " . var_export($da, true));
          if (file_exists($filename)) {
            try {
              unlink($filename);
            } catch (Exception $f) {
              self::_log("WARNING: could not delete file after load failure: " . $filename);
            }
          }
        }
      }
    }
  }


  // This is a pretty key function. It has to create unique local filename for
  // each digital asset.
  private function _get_local_filename_for_image($sku, $digital_asset) {
    $import_dir = Mage::getBaseDir('media') . DS . 'import/';
    $pathinfo = pathinfo($digital_asset['url']);
    return $import_dir . $sku . '--' . $pathinfo['basename'];
  }
}