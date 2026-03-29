<?php

/**
 * @file
 * Drupal site configuration — local development.
 * DB credentials come from environment variables set in docker-compose.yml.
 */

$databases['default']['default'] = [
  'driver'    => 'mysql',
  'database'  => getenv('DRUPAL_DB_NAME')     ?: 'drupal',
  'username'  => getenv('DRUPAL_DB_USER')     ?: 'drupal_user',
  'password'  => getenv('DRUPAL_DB_PASSWORD') ?: '',
  'host'      => getenv('DRUPAL_DB_HOST')     ?: 'frontend_db',
  'port'      => '3306',
  'prefix'    => '',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload'  => 'core/modules/mysql/src/Driver/Database/mysql/',
];

$settings['hash_salt'] = 'local-dev-hash-salt-event-platform-desideriushogeschool-2526';

$settings['update_free_access'] = FALSE;

$settings['container_yamls'][] = $app_root . '/sites/development.services.yml';

$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];

$settings['entity_update_batch_size'] = 50;

// Allow any hostname on local development.
$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^localhost:30020$',
  '^\d+\.\d+\.\d+\.\d+(:\d+)?$',
];

$settings['config_sync_directory'] = '../config/sync';
