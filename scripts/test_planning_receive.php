<?php
declare(strict_types=1);

/**
 * Local test script: listens for session.created messages from Planning.
 *
 * Prerequisites:
 *   1. docker-compose -f docker-compose.local.yml up -d
 *   2. php scripts/setup_planning_exchanges.php
 *   3. (In a separate terminal, simulate Planning sending:)
 *        php scripts/test_planning_simulate_producer.php
 *
 * Usage:
 *   php scripts/test_planning_receive.php
 *
 * Stop with Ctrl+C. Each received message is printed to stdout.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_receiver\SessionCreatedReceiver;

$host  = getenv('RABBITMQ_HOST') ?: 'localhost';
$port  = (int) (getenv('RABBITMQ_PORT') ?: '5672');
$user  = getenv('RABBITMQ_USER') ?: 'guest';
$pass  = getenv('RABBITMQ_PASS') ?: 'guest';
$vhost = getenv('RABBITMQ_VHOST') ?: '/';

$client = null;

try {
    echo "Connecting to RabbitMQ on {$host}:{$port}...\n";
    $client = new RabbitMQClient($host, $port, $user, $pass, $vhost);

    $receiver = new SessionCreatedReceiver($client);

    echo "Waiting for planning.session.created messages (Ctrl+C to stop)...\n\n";
    $receiver->listen();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Receive failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($client instanceof RabbitMQClient) {
        $client->close();
    }
}
