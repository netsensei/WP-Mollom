<?php

/**
 * @file
 * Mollom Content Moderation Platform support code.
 */

/**
 * Handles inbound HTTP requests.
 */
class MollomModeration {

  protected static $contentId;
  protected static $action;
  protected static $entityType;
  protected static $entityId;

  public static $log = array();

  /**
   * Handles an inbound Mollom moderation request.
   *
   * @param string $contentId
   *   The Mollom content ID to moderate.
   * @param string $action
   *   The moderation action to perform.
   *
   * @return bool
   *   TRUE on success, FALSE on failure. An HTTP status header is issued on any
   *   failure.
   */
  public static function handleRequest($contentId, $action) {
    self::$contentId = $contentId;
    self::$action = $action;
    // Verify the action.
    if (!in_array($action, array('approve', 'spam', 'delete'))) {
      header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
      return FALSE;
    }
    // Retrieve entity mapping.
    if (!self::getEntity($contentId)) {
      header($_SERVER['SERVER_PROTOCOL'] . ' 410 Gone');
      self::log(array(
        'message' => __('Not Found', MOLLOM_L10N),
      ));
      return FALSE;
    }
    // Validate OAuth Authorization header.
    if (!self::validateAuth()) {
      header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
      return FALSE;
    }
    do_action('mollom_moderate_' . self::$entityType, self::$entityId, $action);
    return TRUE;
  }

  /**
   * Maps a given Mollom content ID to a local entity.
   *
   * @param string $contentId
   *   The Mollom content ID to look up.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  protected static function getEntity($contentId) {
    global $wpdb;
    $table = $wpdb->prefix . 'mollom';
    $map = $wpdb->get_row($wpdb->prepare("SELECT entity_type, entity_id FROM $table WHERE content_id = '%s'", $contentId));
    if ($map) {
      self::$entityType = $map->entity_type;
      self::$entityId = $map->entity_id;
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Validates OAuth protocol parameters of an inbound HTTP request.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  protected static function validateAuth() {
    // For inbound moderation requests, only the production API keys are valid.
    // The testing mode API keys cannot be trusted. Therefore, this validation
    // is based on the the stored options only.
    $publicKey = get_option('mollom_public_key', '');
    $privateKey = get_option('mollom_private_key', '');
    if ($publicKey === '' || $privateKey === '') {
      self::log(array(
        'message' => __('Missing module configuration', MOLLOM_L10N),
      ));
      return FALSE;
    }

    mollom();
    $data = MollomWordpress::getServerParameters();
    $header = MollomWordpress::getServerAuthentication();

    // Validate protocol parameters.
    if (!isset($header['oauth_consumer_key'], $header['oauth_nonce'], $header['oauth_timestamp'], $header['oauth_signature_method'], $header['oauth_signature'])) {
      self::log(array(
        'message' => __('Missing protocol parameters', MOLLOM_L10N),
        'Request:' => $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'],
        'Request headers:' => $header,
      ));
      return FALSE;
    }

    $sent_signature = $header['oauth_signature'];
    unset($header['oauth_signature']);

    $allowed_timeframe = 900;

    // Validate consumer key.
    if ($header['oauth_consumer_key'] !== $publicKey) {
      self::log(array(
        'message' => __('Invalid public/consumer key', MOLLOM_L10N),
        'Request:' => $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'],
        'Request headers:' => $header,
        'My public key:' => $publicKey,
      ));
      return FALSE;
    }

    // Validate timestamp.
    if ($header['oauth_timestamp'] <= time() - $allowed_timeframe) {
      $diff = $header['oauth_timestamp'] - time();
      $diff_sign = ($diff < 0 ? '-' : '+');
      if ($diff < 0) {
        $diff *= -1;
      }
      self::log(array(
        'message' => __('Outdated authentication timestamp', MOLLOM_L10N),
        'Request:' => $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'],
        'Request headers:' => $header,
        'Time difference:' => $diff_sign . format_interval($diff),
      ));
      return FALSE;
    }

    // Validate nonce.
    if (empty($header['oauth_nonce'])) {
      self::log(array(
        'message' => __('Missing authentication nonce', MOLLOM_L10N),
        'Request:' => $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'],
        'Request headers:' => $header,
      ));
      return FALSE;
    }
    // Do not autoload the option containing seen OAuth nonces.
    // Mind the WPWTF: No, not FALSE, but "no".
    add_option('mollom_moderation_nonces', array(), '', 'no');

    $nonces = get_option('mollom_moderation_nonces', array());
    if (isset($nonces[$header['oauth_nonce']])) {
      self::log(array(
        'message' => 'Replay attack',
        'Request:' => $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'],
        'Request headers:' => $header,
      ));
      return FALSE;
    }
    foreach ($nonces as $nonce => $created) {
      if ($created < time() - $allowed_timeframe) {
        unset($nonces[$nonce]);
      }
    }
    $nonces[$header['oauth_nonce']] = time();
    update_option('mollom_moderation_nonces', $nonces);

    // Validate signature.
    $base_string = implode('&', array(
      $_SERVER['REQUEST_METHOD'],
      Mollom::rawurlencode(site_url() . $_SERVER['REQUEST_URI']),
      Mollom::rawurlencode(Mollom::httpBuildQuery($data + $header)),
    ));
    $key = Mollom::rawurlencode($privateKey) . '&' . '';
    $signature = rawurlencode(base64_encode(hash_hmac('sha1', $base_string, $key, TRUE)));

    $valid = ($signature === $sent_signature);
    if (!$valid) {
      self::log(array(
        'message' => __('Invalid authentication signature', MOLLOM_L10N),
        'Request:' => $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'],
        'Request headers:' => $header + array('oauth_signature' => $sent_signature),
        'Base string:' => $base_string,
        'Expected signature:' => $signature,
        //'Expected key:' => $key,
      ));
    }
    return $valid;
  }

  /**
   * Adds a log message.
   *
   * The log is not used currently; for debugging purposes only.
   */
  public static function log($args) {
    self::$log[] = $args;
  }

}