<?php

/**
 * Salsify provides a very rich cross-sell capability, enabling merchants to
 * categorize their cross-sells by label so that they might place different
 * cross-sells in different positions on the site.
 *
 * Magento is much more limited, providing only product relations, cross-sells,
 * and up-sells.
 *
 * So we have to maintain a mapping from the source accessory categories that
 * come from Salsify and those in Magento. On the Magento side of the mapping
 * there are only 3 values, but on the Salsify side there are many.
 *
 * This is easiest if the data originates in Magento, in which case cross-sell,
 * up-sell, and product relation will be the only 3 accessory labels in salsify
 * so that we are dealing with an easy 1-to-1 situation.
 *
 * TODO set a default Salsify label for each Magento type for new relations that
 *      are created in Magento (or explicitly reject new relations created in
 *      Magento for those using Salsify as the system of record).
 */
class Salsify_Connect_Model_AccessorycategoryMapping
      extends Mage_Core_Model_Abstract
{
  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/accessorycategorymapping');
  }

  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }


  private static function _get_accessory_mapper() {
    return Mage::getModel('salsify_connect/accessorymapping');
  }


  private static function _get_mapping(
    $salsify_category_id,
    $salsify_category_value,
    $magento_relation_type
  ) {
    $mappings = Mage::getModel('salsify_connect/accessorycategorymapping')
                    ->getCollection()
                    ->addFieldToFilter('salsify_category_id', array('eq' => $salsify_category_id))
                    ->addFieldToFilter('salsify_category_value', array('eq' => $salsify_category_value))
                    ->addFieldToFilter('magento_relation_type', array('eq' => $magento_relation_type));
    $mapping = $mappings->getFirstItem();
    if (!$mapping || !$mapping->getId()) {
      return null;
    }
    return $mapping;
  }


  // returns the Salsify attribute ID
  public static function getSalsifyAttributeId() {
    $db = Mage::getSingleton('core/resource')
              ->getConnection('core_read');
    $query = "SELECT salsify_category_id
              FROM salsify_connect_accessorycategory_mapping
              GROUP BY salsify_category_id
              LIMIT 1";
    $results = $db->fetchCol($query);
    if (empty($results)) {
      return null;
    }
    return $results[0];
  }


  // Returns a list of all the Salsify attribute values that we've seen which
  // are accessory categories.
  public static function getSalsifyAttributeValues() {
    $db = Mage::getSingleton('core/resource')
              ->getConnection('core_read');
    $query = "SELECT salsify_category_value
              FROM salsify_connect_accessorycategory_mapping
              GROUP BY salsify_category_value";
    return $db->fetchCol($query);
  }


  public static function getOrCreateMapping(
    $salsify_category_id,
    $salsify_category_value,
    $magento_relation_type
  ) {
    $mapper = self::_get_accessory_mapper();
    if (!$magento_relation_type) {
      // default to cross-sell
      $accessory_mapper = Mage::getModel('salsify_connect/accessorymapping');
      $magento_relation_type = $accessory_mapper::CROSS_SELL;
    }
    $mapping = self::_get_mapping($salsify_category_id, $salsify_category_value, $magento_relation_type);
    
    if (!$mapping) {
      $mapping = Mage::getModel('salsify_connect/accessorycategorymapping');
      $mapping->setSalsifyCategoryId($salsify_category_id);
      $mapping->setSalsifyCategoryValue($salsify_category_value);
      $mapping->setMagentoRelationType($magento_relation_type);
      $mapping->save();
    }

    return $mapping;
  }
}