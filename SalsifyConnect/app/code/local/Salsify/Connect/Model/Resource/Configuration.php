<?php
class Salsify_Connect_Model_Resource_Configuration extends Mage_Core_Model_Resource_Db_Abstract
{

  protected function _construct()
  {
    $this->_init('salsify_connect/configuration', 'id');
  }
  
}