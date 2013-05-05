<?php

/**
 * @file
 * Mollom client class for Wordpress.
 */

/**
 * Wordpress Mollom client implementation.
 */
class MollomWordpress extends Mollom {
  /**
   * Mapping of configuration names to Wordpress variables.
   *
   * @see Mollom::loadConfiguration()
   */
  public $configuration_map = array(
    'publicKey' => 'mollom_public_key',
    'privateKey' => 'mollom_private_key',
  );

  /**
   * Implements Mollom::loadConfiguration().
   */
  public function loadConfiguration($name) {
    return get_option($this->configuration_map[$name]);
  }

  /**
   * Implements Mollom::saveConfiguration().
   */
  public function saveConfiguration($name, $value) {
    return update_option($this->configuration_map[$name], $value);
  }

  /**
   * Implements Mollom::deleteConfiguration().
   */
  public function deleteConfiguration($name) {
    return delete_option($this->configuration_map[$name]);
  }

  /**
   * Implements Mollom::getClientInformation().
   */
  public function getClientInformation() {
    global $wp_version;

    $data = array(
      'platformName' => 'Wordpress',
      'platformVersion' => $wp_version,
      'clientName' => 'Mollom',
      'clientVersion' => MOLLOM_PLUGIN_VERSION,
    );
    return $data;
  }

  /**
   * Overrides Mollom::writeLog().
   *
   * @todo Implement this
   */
  function writeLog() {
    foreach ($this->log as $key => $entry) {
      // @todo: write log away
    }

    // Purge the logs
    $this->purgeLog();
  }

  /**
   * Implements Mollom::request().
   */
  protected function request($method, $server, $path, $query = NULL, array $headers = array()) {
    $url = $server . '/' . $path;
    $data = array(
      'headers' => $headers,
      'timeout' => $this->requestTimeout,
    );

    if ($method == 'GET') {
      $function = 'wp_remote_get';
      $url .= '?' . $query;
    }
    else {
      $function = 'wp_remote_post';
      $data['body'] = $query;
    }
    $result = $function($url, $data);

    if (is_wp_error($result)) {
      // A WP_Error means a network error by default.
      $code = self::NETWORK_ERROR;
      // Try to extract error code from error message, if any.
      $code_in_message = (int) $result->get_error_message();
      if ($code_in_message > 0) {
        $code = $code_in_message;
      }
      $response = (object) array(
        'code' => $code,
        'message' => $result->get_error_message(),
        'headers' => array(),
        'body' => NULL,
      );
    }
    else {
      $response = (object) array(
        'code' => $result['response']['code'],
        'message' => $result['response']['message'],
        'headers' => $result['headers'],
        'body' => $result['body'],
      );
    }
    return $response;
  }

}

