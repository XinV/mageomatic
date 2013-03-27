<?php

/**
 * ImageMapping that keeps track of which local, Magento images correspond to
 * which images in the Salsify CDN. This enables us to avoid re-downloading
 * images that we already have, discovering new images in Salsify, etc.
 */
class Salsify_Connect_Model_ImageMapping extends Mage_Core_Model_Abstract {

  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }

  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/imagemapping');
  }


  // returns the ImageMapping model instance if it exists for the given sku and
  // url, null otherwise.
  private static function _get_mapping($sku, $url) {
    $mappings = Mage::getModel('salsify_connect/imagemapping')
                    ->getCollection()
                    ->addAttributeToFilter('sku', array('eq' => $sku))
                    ->addAttributeToFilter('url', array('eq' => $url));
    $mapping = $mappings->getFirstItem();
    if (!$mapping || !$mapping->getId()) {
      return null;
    }
    return $mapping;
  }


  // This is a pretty key function. It has to create unique local filename for
  // each digital asset.
  //
  // Ensures that the file doesn't already exist.
  private static function _get_local_filename_for_image($sku, $digital_asset) {
    $import_dir = Mage::getBaseDir('media') . DS . 'import/';
    $pathinfo = pathinfo($digital_asset['url']);
    $filename = $import_dir . $sku . '--' . $pathinfo['basename'];

    if (file_exists($filename)) {
      // this snould only really happen in development if there is something
      // that goes wrong right in the middle of an import that we then retry.
      try {
        unlink($filename);
      } catch (Exception $e) {
        self::_log("WARNING: file that cannot be removed already exists for product " . $sku . ' from ' . $url . ' at ' . $filename . ' so skipping');
        return null;
      }
    }

    return $filename;
  }


  // downloads the image locally so that it's available to add to a product's
  // image gallery.
  private static function _download_image_to_local($salsify, $url, $filename) {
    try {
      $salsify->download_file($url, $filename);
      self::_log('successfully downloaded image for ' . $sku . ' from ' . $url . ' to ' . $filename);
      return true;
    } catch (Exception $e) {
      self::_log("WARNING: could not download digital asset: " . $e->getMessage());
      self::_log("       " . var_export($da, true));

      // in case that there was only a partial download
      if (file_exists($filename)) {
        try {
          unlink($filename);
        } catch (Exception $f) {
          self::_log("WARNING: could not delete file after load failure: " . $filename);
        }
      }

      return false;
    }
  }


  // loads the set of digital assets into Magento.
  //
  // digital_assets format of $digital_assets is basically exactly what you
  // would see in a salsify import, except that the keys of the array are the
  // skus of the product to which the digital asset is associated.
  // e.g. something like (excuse the mixture of json & php syntaxes here):
  // ['12345'=>{"url":"https://salsify-development.s3.amazonaws.com/rgonzalez/uploads/digital_asset/asset/2/2087913-5311.jpg",
  //   "name":"2087913-5311.jpg","is_primary_image":"true"},...]
  //
  // Thanks: http://stackoverflow.com/questions/8456954/magento-programmatically-add-product-image
  //
  // TODO deal with image updates (e.g. get image from salsify, it changes in
  //      salsify, recognize and update the local image).
  //
  // TODO get images for different digital asset roles (thumbnail vs. image,
  //      etc.) from salsify.
  public static function load_digital_assets($digital_assets) {
    if (!$digital_assets || empty($digital_assets)) {
      self::_log("no digital assets passed in to process.");
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

        // FIXME do we already have this puppy?
        // self::_log('local file already exists for product ' . $sku . ' from ' . $url);
        // $existing_mapping = self::_get_mapping($sku, $url);
        // if ($existing_mapping) {
        //   self::_log("IMAGE MAPPING EXISTS: " . var_export($existing_mapping, true));
        //   continue;
        // }

        $filename = self::_get_local_filename_for_image($sku, $da);
        if (!$filename) { continue; }

        $success = self::_download_image_to_local($salsify, $url, $filename);
        if (!$success) { continue; }

        $product->addImageToMediaGallery(
          $filename,
          array('image', 'small_image', 'thumbnail'),
          true,  // whether to move the file
          false  // true hides from product page
        );

        // FIXME finish adding the ImageMapping object
        $image_mapping = Mage::getModel('salsify_connect/imagemapping');
        $image_mapping->setSku($sku);
        $image_mapping->setMagentoId(1); // FIXME
        $image_mapping->setUrl($url);
        $image_mapping->save();


        if (array_key_exists('name', $da)) {
          // set the label metadata in the image.
          //
          // this is terrible. thanks:
          // http://stackoverflow.com/questions/7215105/magento-set-product-image-label-during-import
          $gallery = $product->getData('media_gallery');
          $last_image = array_pop($gallery['images']);
          $last_image['label'] = $da['name'];
          array_push($gallery['images'], $last_image);
          $product->setData('media_gallery', $gallery);
        }

        // need to save the product for any changes to the media gallery to
        // take effect.
        $product->save();
      }
    }
  }
}