<?php
declare(strict_types=1);

/**
 * Worker script: listens for calendar.invite.confirmed messages from Planning.
 *
 * Planning publishes a confirmation (or failure) after processing a calendar.invite.
 * This script binds frontend.planning.calendar.invite.confirmed to planning.exchange
 * and logs the result.
 *
 * Prerequisites:
 *   docker compose -f docker-compose.yml -f docker-compose.local.yml up -d
 *
 * Usage (run inside the container or locally with correct env vars):
 *   php scripts/receive_calendar_invite_confirmed.php
 *
 * Stop with Ctrl+C.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_receiver\CalendarInviteConfirmedReceiver;

$host  = getenv('RABBITMQ_HOST') ?: 'localhost';
$port  = (int) (getenv('RABBITMQ_PORT') ?: '5672');
$user  = getenv('RABBITMQ_USER') ?: 'guest';
$pass  = getenv('RABBITMQ_PASS') ?: 'guest';
$vhost = getenv('RABBITMQ_VHOST') ?: '/';

$client = null;

try {
    echo "Connecting to RabbitMQ on {$host}:{$port}...\n";
    $client = new RabbitMQClient($host, $port, $user, $pass, $vhost);

    $receiver = new CalendarInviteConfirmedReceiver($client);

    echo "Waiting for planning.calendar.invite.confirmed messages (Ctrl+C to stop)...\n\n";
    $receiver->listen();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Receive failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($client instanceof RabbitMQClient) {
        $client->close();
    }
}
