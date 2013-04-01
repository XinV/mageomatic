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


  // returns a mapping that matches the full set of criteria, or null if it does
  //         not exist.
  private static function _get_mapping(
    $salsify_category_id,
    $salsify_category_value,
    $trigger_sku,
    $target_sku,
    $relation_type
  ) {
    $mappings = self::_get_mappings_collection_for_trigger_target(
                                     $trigger_sku, $target_sku, $relation_type);
    $mappings->addFieldToFilter('salsify_category_id', array('eq' => $salsify_category_id))
             ->addFieldToFilter('salsify_category_value', array('eq' => $salsify_category_value));
    $mapping = $mappings->getFirstItem();
    if (!$mapping || !$mapping->getId()) {
      return null;
    }
    return $mapping;
  }


  // returns ALL the mappings that match the given trigger and target skus.
  // if no mappings are found then it creates a new mapping and returns the new
  // collection (which will have only a single member).
  public static function getOrCreateMappings(
    $trigger_sku, $target_sku, $default_category, $relation_type
  ) {
    $mappings = self::_get_mappings_collection_for_trigger_target($trigger_sku, $target_sku, $relation_type);
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
  //
  // throws (or rather doesn't bother to catch) exceptions that occur when doing
  //        DB operations.
  public static function bulkLoadMappings($accessories) {
    if (empty($accessories)) {
      return 0;
    }

    $db = Mage::getSingleton('core/resource')
              ->getConnection('core_write');

    $sql = array(); 
    foreach($accessories as $relationship) {
      if (!array_key_exists('magento_relation_type', $relationship)) {
        // default to cross-sell
        // TODO make this configurable
        $relationship['magento_relation_type'] = self::CROSS_SELL;
      }

      $salsify_category_id = $relationship['salsify_category_id'];
      $salsify_category_value = $relationship['salsify_category_value'];
      $trigger_sku = $relationship['trigger_sku'];
      $target_sku  = $relationship['target_sku'];
      $magento_relation_type = $relationship['magento_relation_type'];

      // OPTIMIZE we should be able to exclude all the ones at once instead of
      //          doing this one-at-a-time
      $mapping = self::_get_mapping($salsify_category_id,
                                    $salsify_category_value,
                                    $trigger_sku,
                                    $target_sku,
                                    $magento_relation_type);

      if (!$mapping) {
        $sql[] = '('
               . $db->quote($salsify_category_id)
               . ', '
               . $db->quote($salsify_category_value)
               . ', '
               . $magento_relation_type
               . ', '
               . $db->quote($trigger_sku)
               . ', '
               . $db->quote($target_sku)
               . ')';
      }
    }

    if (empty($sql)) {
      return 0;
    }

    $query = 'INSERT INTO salsify_connect_accessory_mapping (
                                salsify_category_id,
                                salsify_category_value,
                                magento_relation_type,
                                trigger_sku,
                                target_sku
                              )
              VALUES ' . implode(',', $sql);

    $count = count($sql);
    self::_log("Inserting " . $count . " rows into salsify_connect_accessory_mapping...");
    $db->query($query);
    self::_log("Done inserting " . $count . " rows into salsify_connect_accessory_mapping...");
    return $count;
  }
}