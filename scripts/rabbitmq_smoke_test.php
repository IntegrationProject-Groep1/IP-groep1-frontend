<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Drupal\rabbitmq_sender\RabbitMQClient;

// Resolve broker connection settings from environment with safe local defaults.
$host = getenv('RABBITMQ_HOST') ?: 'localhost';
$port = (int) (getenv('RABBITMQ_PORT') ?: '5672');
$user = getenv('RABBITMQ_USER') ?: 'guest';
$pass = getenv('RABBITMQ_PASS') ?: 'guest';
$vhost = getenv('RABBITMQ_VHOST') ?: '/';
$queue = $argv[1] ?? 'test';

$client = null;

try {
    $client = new RabbitMQClient($host, $port, $user, $pass, $vhost);
    $client->declareQueue($queue);

    // Publish two timestamped messages to prove connectivity and publish capability.
    $payload1 = sprintf('smoke-test message 1 at %s', (new DateTimeImmutable())->format(DATE_ATOM));
    $payload2 = sprintf('smoke-test message 2 at %s', (new DateTimeImmutable())->format(DATE_ATOM));

    $client->publishToQueue($queue, $payload1);
    $client->publishToQueue($queue, $payload2);

    echo "Connected to RabbitMQ on {$host}:{$port} (vhost: {$vhost})\n";
    echo "Declared queue '{$queue}' and published 2 messages.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'RabbitMQ smoke test failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($client instanceof RabbitMQClient) {
        $client->close();
        echo "Connection closed.\n";
    }
}