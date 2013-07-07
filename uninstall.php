<?php

/**
 * @file
 * Uninstallation functionality.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
  header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
  exit;
}

// @todo Delete meta data.

// Drop database tables.
require_once dirname(__FILE__) . '/includes/Schema.php';
MollomSchema::uninstall();

delete_option('mollom_schema_version');

delete_option('mollom_public_key');
delete_option('mollom_private_key');

delete_option('mollom_checks');
delete_option('mollom_bypass_roles');
delete_option('mollom_fallback_mode');
delete_option('mollom_privacy_link');

delete_option('mollom_reverse_proxy_addresses');
delete_option('mollom_testing_mode');
delete_option('mollom_testing_create_keys');
delete_option('mollom_testing_public_key');
delete_option('mollom_testing_private_key');

delete_option('mollom_moderation_nonces');
