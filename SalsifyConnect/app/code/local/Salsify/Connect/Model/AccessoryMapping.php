<?php

/**
 * For each relationship that originated in Salsify, this maintains the original
 * accessory category ID for lossless roundtrip updating.
 */
class Salsify_Connect_Model_AccessoryMapping
      extends Mage_Core_Model_Abstract
{
  // required by Magento
  protected function _construct() {
    $this->_init('salsify_connect/accessorymapping');
  }

  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }


  // relation_type enum for the types of product relationships in Magento
  const CROSS_SELL      = 1;
  const UP_SELL         = 2;
  const RELATED_PRODUCT = 3;


  public static function getAccessoryLabelForMagentoType($relation_type) {
    if ($relation_type === self::CROSS_SELL) {
      return 'cross-sell';
    } elseif ($relation_type === self::UP_SELL) {
      return 'up-sell';
    } else {
      return 'related product';
    }
  }


  private static function _get_mappings_collection_for_trigger_target(
    $trigger_sku, $target_sku, $relation_type
  ) {
    return Mage::getModel('salsify_connect/accessorymapping')
               ->getCollection()
               ->addFieldToFilter('trigger_sku', array('eq' => $trigger_sku))
               ->addFieldToFilter('target_sku', array('eq' => $target_sku))
               ->addFieldToFilter('magento_relation_type', array('eq' => $relation_type));
  }


  // returns ALL the mappings that match the given trigger and target skus.
  // if no mappings are found then it creates a new mapping and returns the new
  // collection (which will have only a single member).
  public static function getOrCreateMappings(
    $trigger_sku, $target_sku, $default_category, $relation_type
  ) {
    $mappings = self::_get_mappings_collection_for_trigger_target($trigger_sku, $target_sku);
    $mapping = $mappings->getFirstItem();
    if ($mapping && $mapping->getId()) {
      return $mappings;
    }

    // no mappings, need to create a new one.
    $mapping = Mage::getModel('salsify_connect/accessorymapping');
    $mapping->setTriggerSku($trigger_sku);
    $mapping->setTargetSku($target_sku);
    $mapping->setSalsifyCategoryId($default_category);
    $mapping->setMagentoRelationType($relation_type);
    $mapping->setSalsifyCategoryValue(self::getAccessoryLabelForMagentoType($relation_type));
    $mapping->save();

    return self::_get_mappings_collection_for_trigger_target($trigger_sku, $target_sku, $relation_type);
  }


  // This takes an array whose items contain all the information for a mapping.
  public static function bulkLoadMappings($accessories) {
    if (empty($accessories)) {
      return 0;
    }

    $db = Mage::getSingleton('core/resource')
              ->getConnection('core_write');

    // thanks http://stackoverflow.com/questions/779986/insert-multiple-rows-via-a-php-array-into-mysql
    $sql = array(); 
    foreach($accessories as $relationship) {
      if (!array_key_exists('magento_relation_type', $relationship)) {
        // default to cross-sell
        // TODO make this configurable
        $relationship['magento_relation_type'] = self::CROSS_SELL;
      }
      $sql[] = '('
             . $db->quote($relationship['salsify_category_id'])
             . ', '
             . $db->quote($relationship['salsify_category_value'])
             . ', '
             . $relationship['magento_relation_type']
             . ', '
             . $db->quote($relationship['trigger_sku'])
             . ', '
             . $db->quote($relationship['target_sku'])
             . ')';
    }

    $query = 'INSERT INTO salsify_connect_accessory_mapping (
                                salsify_category_id,
                                salsify_category_value,
                                magento_relation_type,
                                trigger_sku,
                                target_sku
                              )
              VALUES ' . implode(',', $sql);
    self::_log("QUERY: " . $query);

    // FIXME need unique constraints on the mapping table so that I'm not
    //       re-inserting the same ones over an over...

    try {
      $count = count($sql);
      self::_log("Inserting " . $count . " rows into salsify_connect_accessory_mapping...");
      $db->query($query);
      self::_log("Done inserting " . $count . " rows into salsify_connect_accessory_mapping...");
      return $count;
    } catch (Exception $e) {
      // FIXME do something
    }
  }
}