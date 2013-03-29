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


}
