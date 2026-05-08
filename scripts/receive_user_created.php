<?php
declare(strict_types=1);

/**
 * Consumer script: listens for UserCreated events from the Identity Service.
 *
 * The Identity Service publishes to the user.events fanout exchange whenever
 * a new master UUID is assigned. This script binds frontend.user_created to
 * that exchange and stores the master_uuid on the matching local Drupal user.
 *
 * Prerequisites:
 *   docker compose -f docker-compose.yml -f docker-compose.local.yml up -d
 *
 * Usage (run inside the container or locally with correct env vars):
 *   php scripts/receive_user_created.php
 *
 * Stop with Ctrl+C.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_receiver\UserCreatedReceiver;

$host  = getenv('RABBITMQ_HOST') ?: 'localhost';
$port  = (int) (getenv('RABBITMQ_PORT') ?: '5672');
$user  = getenv('RABBITMQ_USER') ?: 'guest';
$pass  = getenv('RABBITMQ_PASS') ?: 'guest';
$vhost = getenv('RABBITMQ_VHOST') ?: '/';

$client = null;

try {
    echo "Connecting to RabbitMQ on {$host}:{$port}...\n";
    $client = new RabbitMQClient($host, $port, $user, $pass, $vhost);

    $receiver = new UserCreatedReceiver($client);

    echo "Waiting for UserCreated events on user.events → frontend.user_created (Ctrl+C to stop)...\n\n";
    $receiver->listen();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Receive failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($client instanceof RabbitMQClient) {
        $client->close();
    }
}
