<?php
/**
 * Injects test planning sessions into Drupal State for local testing.
 * Run inside the Drupal container:
 *   docker exec frontend_drupal php /var/www/html/modules/custom/../../../scripts/inject_test_sessions.php
 *
 * Or via: docker exec frontend_drupal php /var/www/html/scripts/inject_test_sessions.php
 */

define('DRUPAL_ROOT', '/opt/drupal/web');
chdir(DRUPAL_ROOT);

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$autoloader = require DRUPAL_ROOT . '/../vendor/autoload.php';
$request    = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
$kernel     = \Drupal\Core\DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$kernel->preHandle($request);

\Drupal::state()->set('planning.sessions', [
    [
        'session_id'        => 'sess-plan-001',
        'title'             => 'Keynote: AI in de zorgsector',
        'start_datetime'    => '2026-05-15T14:00:00Z',
        'end_datetime'      => '2026-05-15T15:00:00Z',
        'location'          => 'Aula A - Campus Jette',
        'session_type'      => 'keynote',
        'status'            => 'published',
        'max_attendees'     => 120,
        'current_attendees' => 0,
    ],
    [
        'session_id'        => 'sess-plan-002',
        'title'             => 'Workshop: Cloud & DevOps',
        'start_datetime'    => '2026-05-15T15:00:00Z',
        'end_datetime'      => '2026-05-15T16:00:00Z',
        'location'          => 'Labo B',
        'session_type'      => 'workshop',
        'status'            => 'published',
        'max_attendees'     => 40,
        'current_attendees' => 0,
    ],
    [
        'session_id'        => 'sess-plan-003',
        'title'             => 'Netwerkreceptie & Drinks',
        'start_datetime'    => '2026-05-15T18:00:00Z',
        'end_datetime'      => '2026-05-15T20:00:00Z',
        'location'          => 'Foyer',
        'session_type'      => 'networking',
        'status'            => 'published',
        'max_attendees'     => 200,
        'current_attendees' => 0,
    ],
]);

echo '✓ 3 planning sessions opgeslagen in Drupal State.' . PHP_EOL;
echo '  Ga naar http://localhost:30020/register om ze te zien.' . PHP_EOL;
