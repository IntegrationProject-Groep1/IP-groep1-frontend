<?php

/**
 * Drupal site configuration — local & Docker environment.
 */

$databases['default']['default'] = [
  'driver'    => 'mysql',
  'database'  => getenv('DRUPAL_DB_NAME') ?: 'drupal',
  'username'  => getenv('DRUPAL_DB_USER') ?: 'drupal_user',
  'password'  => getenv('DRUPAL_DB_PASSWORD') ?: 'drupal_pass',
  'host'      => getenv('DRUPAL_DB_HOST') ?: 'frontend_db',
  'port'      => '3306',
  'prefix'    => '',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload'  => 'core/modules/mysql/src/Driver/Database/mysql/',
];

$settings['hash_salt'] = 'local-dev-hash-salt-event-platform-2526';

$settings['update_free_access'] = FALSE;

// Only load dev services file if it exists
$dev_services = $app_root . '/sites/development.services.yml';
if (file_exists($dev_services)) {
  $settings['container_yamls'][] = $dev_services;
}

$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];

$settings['entity_update_batch_size'] = 50;

// Trusted hosts (fix voor Docker + CI)
$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^localhost(:[0-9]+)?$',
  '^127\.0\.0\.1(:[0-9]+)?$',
];

// Azure host (optioneel)
$settings['trusted_host_patterns'][] = '^integrationproject-2526s2-dag01\.westeurope\.cloudapp\.azure\.com(:[0-9]+)?$';

$settings['config_sync_directory'] = 'sites/default/files/config_sync';

// Reverse proxy support
if (getenv('DRUPAL_REVERSE_PROXY') === 'true') {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = [$_SERVER['REMOTE_ADDR']];
  $settings['reverse_proxy_proto_header'] = 'X-Forwarded-Proto';
  $settings['reverse_proxy_host_header'] = 'X-Forwarded-Host';
  $settings['reverse_proxy_port_header'] = 'X-Forwarded-Port';

  if ($base_url_env = getenv('DRUPAL_BASE_URL')) {
    $base_url = $base_url_env;
  }
}

// Performance tweak
ini_set('memory_limit', '512M');