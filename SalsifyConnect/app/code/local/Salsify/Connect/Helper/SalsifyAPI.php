<?php

/**
 * Helper class that takes care of communicating with Salsify.
 */
class Salsify_Connect_Helper_SalsifyAPI extends Mage_Core_Helper_Abstract {

  private $_base_url;
  private $_api_key;

  public function set_base_url($baseurl) {
    $this->_base_url = $baseurl;
  }

  public function set_api_key($apikey) {
    $this->_api_key = $apikey;
  }

  // TODO make this configurable with "compressed" vs. not once we figure out
  //      how to deal with GZipped stuff in PHP.
  public function create_export() {
    if (!$this->_base_url || !$this->_api_key) {
      throw new Exception("Base URL and API key must be set to create a new export.");
    }
    $url = $this->_get_create_export_url();
    $req = new HttpRequest($url, HTTP_METH_POST);
    $mes = $req->send();
    return json_decode($mes->getBody());
  }

  private function _get_create_export_url() {
    return $this->_base_url . '/api/exports?format=json&auth_token=' . $this->_api_key;
  }

  public function get_export($id) {
    if (!$this->_base_url || !$this->_api_key) {
      throw new Exception("Base URL and API key must be set to create a new export.");
    }
    $url = $this->_get_export_url($id);
    $req = new HttpRequest($url, HTTP_METH_GET);
    $mes = $req->send();
    return json_decode($mes->getBody());
  }

  private function _get_export_url($id) {
    return $this->_base_url . '/api/exports/'.$id.'?format=json&auth_token=' . $this->_api_key;
  }

}