<?php
declare(strict_types=1);

/**
 * Local test script: sends a calendar.invite message to Planning via RabbitMQ.
 *
 * Prerequisites:
 *   1. docker-compose -f docker-compose.local.yml up -d
 *   2. php scripts/setup_planning_exchanges.php
 *
 * Usage:
 *   php scripts/test_planning_send.php
 *   php scripts/test_planning_send.php "My custom session title"
 *
 * The sent message will appear in the planning.calendar.invite queue on
 * the RabbitMQ management UI: http://localhost:15672  (guest / guest)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_sender\CalendarInviteSender;

$host  = getenv('RABBITMQ_HOST') ?: 'localhost';
$port  = (int) (getenv('RABBITMQ_PORT') ?: '5672');
$user  = getenv('RABBITMQ_USER') ?: 'guest';
$pass  = getenv('RABBITMQ_PASS') ?: 'guest';
$vhost = getenv('RABBITMQ_VHOST') ?: '/';

$title = $argv[1] ?? 'Keynote: AI in de zorgsector (test)';

$data = [
    'session_id'     => sprintf('test-sess-%s', date('YmdHis')),
    'title'          => $title,
    'start_datetime' => '2026-05-15T14:00:00Z',
    'end_datetime'   => '2026-05-15T15:00:00Z',
    'location'       => 'Aula A - Campus Jette (lokaal)',
    'identity_uuid'  => '550e8400-e29b-41d4-a716-446655440000',
    'attendee_email' => 'test@example.com',
];

$client = null;

try {
    echo "Connecting to RabbitMQ on {$host}:{$port}...\n";
    $client = new RabbitMQClient($host, $port, $user, $pass, $vhost);
    $sender = new CalendarInviteSender($client);

    echo "Sending calendar.invite → calendar.exchange (routing: calendar.invite)\n";
    echo "  session_id    : {$data['session_id']}\n";
    echo "  title         : {$data['title']}\n";
    echo "  start         : {$data['start_datetime']}\n";
    echo "  end           : {$data['end_datetime']}\n";
    echo "  location      : {$data['location']}\n";
    echo "  identity_uuid : {$data['identity_uuid']}\n";
    echo "  attendee_email: {$data['attendee_email']}\n\n";

    $sender->send($data);

    echo "✓ Message published successfully.\n";
    echo "  Check the queue 'planning.calendar.invite' on http://localhost:15672\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Send failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($client instanceof RabbitMQClient) {
        $client->close();
    }
}
