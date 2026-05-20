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

if (file_exists($app_root . '/sites/development.services.yml')) {
  $settings['container_yamls'][] = $app_root . '/sites/development.services.yml';
}

// Load per-site services.yml (disables Twig cache, enables auto_reload).
if (file_exists($app_root . '/sites/default/services.yml')) {
  $settings['container_yamls'][] = $app_root . '/sites/default/services.yml';
}

$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];

$settings['entity_update_batch_size'] = 50;

// Disable CSS/JS aggregation so style.css is served directly (no stale bundle).
$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess']  = FALSE;

// Trusted hosts (fix voor Docker + CI)
$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^localhost(:[0-9]+)?$',
  '^127\.0\.0\.1(:[0-9]+)?$',
  '^(.+\.)?desiderius\.me$',
  '^integrationproject-2526s2-dag01\.westeurope\.cloudapp\.azure\.com(:[0-9]+)?$',
];

$settings['config_sync_directory'] = 'sites/default/files/config_sync';

// Reverse proxy support
if (getenv('DRUPAL_REVERSE_PROXY') === 'true') {
  $settings['reverse_proxy'] = TRUE;
  
  // In K8s/Cloudflare, we often need to trust all internal IPs.
  // This can be overridden by the DRUPAL_REVERSE_PROXY_ADDRESSES env var.
  $proxy_ips = getenv('DRUPAL_REVERSE_PROXY_ADDRESSES');
  if ($proxy_ips) {
    $settings['reverse_proxy_addresses'] = explode(',', $proxy_ips);
  } else {
    // Fallback: trust the immediate sender if nothing else is specified.
    $settings['reverse_proxy_addresses'] = isset($_SERVER['REMOTE_ADDR']) ? [$_SERVER['REMOTE_ADDR']] : [];
  }

  $settings['reverse_proxy_proto_header'] = 'X-Forwarded-Proto';
  $settings['reverse_proxy_host_header'] = 'X-Forwarded-Host';
  $settings['reverse_proxy_port_header'] = 'X-Forwarded-Port';

  // Force HTTPS if the proxy says so.
  if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
  }
}

// Always override $base_url for desiderius.me to prevent stale port redirection (e.g. :8080).
// This is critical because Apache inside the container runs on 8080 as non-root.
if (isset($_SERVER['HTTP_HOST']) && str_contains($_SERVER['HTTP_HOST'], 'desiderius.me')) {
  $base_url = 'https://desiderius.me';
} elseif ($env_base_url = getenv('DRUPAL_BASE_URL')) {
  $base_url = $env_base_url;
}

// Disable all page and render caching locally.
// Remove these lines before deploying to staging or production.
$settings['cache']['bins']['render'] = 'cache.backend.null';
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';
$settings['cache']['bins']['page'] = 'cache.backend.null';
