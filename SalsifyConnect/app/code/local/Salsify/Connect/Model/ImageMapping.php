<?php

/**
 * FIXME
 */
class Salsify_Connect_Model_ImageMapping extends Mage_Core_Model_Abstract {

  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }

  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/imagemapping');
  }


  // This is a pretty key function. It has to create unique local filename for
  // each digital asset.
  private static function _get_local_filename_for_image($sku, $digital_asset) {
    $import_dir = Mage::getBaseDir('media') . DS . 'import/';
    $pathinfo = pathinfo($digital_asset['url']);
    return $import_dir . $sku . '--' . $pathinfo['basename'];
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
        $filename = self::_get_local_filename_for_image($sku, $da);
        try {
          if (file_exists($filename)) {
            self::_log('local file already exists for product ' . $sku . ' from ' . $url);
          } else {
            $salsify->download_file($url, $filename);
            self::_log('successfully downloaded image for ' . $sku . ' from ' . $url . ' to ' . $filename);
          }

          // FIXME don't re-add the image to the gallery if it's already been
          //       downloaded

          $product->addImageToMediaGallery(
            $filename,
            array('image', 'small_image', 'thumbnail'),
            true,  // whether to move the file
            false  // true hides from product page
          );

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
}