<?php

/**
 * Maintains and persists the mappings between Salsify attribute IDs and Magento
 * attributes codes.
 */
class Salsify_Connect_Model_AttributeMapping extends Mage_Core_Model_Abstract {

  private function _log($msg) {
    Mage::log('AttributeMapping: ' . $msg, null, 'salsify.log', true);
  }


  // attribute_codes for attributes that store the Salsify IDs within Magento
  // for various object types.
  const SALSIFY_CATEGORY_ID       = 'salsify_category_id';
  const SALSIFY_CATEGORY_ID_NAME  = 'Salsify Category ID';
  const SALSIFY_PRODUCT_ID        = 'salsify_product_id';
  const SALSIFY_PRODUCT_ID_NAME   = 'Salsify Product ID';


  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/attributemapping');
  }


  // roles is an array that follows the structure of roles from a Salsify import
  // document. so there are nested arrays for 'products' roles, 'global' roles,
  // etc.
  public static function getCodeForId($id, $roles) {
    // there are some special attributes that Magento treats differently from
    // and admin and UI perspective, e.g. name, id, etc. right now there are a
    // couple that map directly to salsify roles.
    //
    // TODO have a more broad mapping mapping strategy from salsify attributes
    //      to Magento roles.

    if ($roles) {
      if (array_key_exists('products', $roles)) {
        $product_roles = $roles['products'];
        if (in_array('id', $product_roles)) {
          return 'sku';
        }
        if (in_array('name', $product_roles)) {
          return 'name';
        }
      }
    }

    if ($id === self::SALSIFY_PRODUCT_ID) {
      return self::SALSIFY_PRODUCT_ID;
    } elseif ($id === self::SALSIFY_CATEGORY_ID) {
      return self::SALSIFY_CATEGORY_ID;
    }

    // try to look up in the DB to see if the mapping already exists
    

    // FIXME try to look this up in the DB now

    return null;
  }


  // thanks
  // http://stackoverflow.com/questions/3197239/magento-select-from-database
  public function loadByCode($code) {
    $this->setId(null)->load($code, 'code');
    return $this;
  }

  public function loadBySalsifyId($id) {
    $this->setId(null)->load($id, 'salsify_id');
    return $this;
  }


  public static function createMappingFromSalsifyIdToMagentoCode($id, $code) {
    $mapping = Mage::getModel('modulename/AttributeMapping');
    $mapping->setSalsifyId($id);
    $mapping->setCode($code);
    $mapping->save();
    return $mapping;
  }

}