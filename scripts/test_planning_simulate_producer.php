<?php
declare(strict_types=1);

/**
 * Local test script: simulates Planning publishing a session.created event.
 *
 * Use this together with test_planning_receive.php to verify the full round-trip
 * locally without needing the actual Planning service running.
 *
 * Prerequisites:
 *   1. docker-compose -f docker-compose.local.yml up -d
 *   2. php scripts/setup_planning_exchanges.php
 *   3. In terminal A: php scripts/test_planning_receive.php
 *   4. In terminal B: php scripts/test_planning_simulate_producer.php
 *
 * Usage:
 *   php scripts/test_planning_simulate_producer.php
 *   php scripts/test_planning_simulate_producer.php "Mijn Sessie" keynote 50
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$host  = getenv('RABBITMQ_HOST') ?: 'localhost';
$port  = (int) (getenv('RABBITMQ_PORT') ?: '5672');
$user  = getenv('RABBITMQ_USER') ?: 'guest';
$pass  = getenv('RABBITMQ_PASS') ?: 'guest';
$vhost = getenv('RABBITMQ_VHOST') ?: '/';

$title        = $argv[1] ?? 'Keynote: AI in de zorgsector';
$sessionType  = $argv[2] ?? 'keynote';
$maxAttendees = (int) ($argv[3] ?? 120);

$sessionId = sprintf('sim-sess-%s', date('YmdHis'));
$messageId = sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff), mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);
$timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

// Build a Planning-compatible session_created XML message.
$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<message xmlns="urn:integration:planning:v1">
  <header>
    <message_id>{$messageId}</message_id>
    <timestamp>{$timestamp}</timestamp>
    <source>planning</source>
    <type>session_created</type>
    <version>1.0</version>
    <correlation_id>{$messageId}</correlation_id>
  </header>
  <body>
    <session_id>{$sessionId}</session_id>
    <title>{$title}</title>
    <start_datetime>2026-05-15T14:00:00Z</start_datetime>
    <end_datetime>2026-05-15T15:00:00Z</end_datetime>
    <location>Aula A - Campus Jette</location>
    <session_type>{$sessionType}</session_type>
    <status>published</status>
    <max_attendees>{$maxAttendees}</max_attendees>
    <current_attendees>0</current_attendees>
  </body>
</message>
XML;

$connection = null;

try {
    echo "Connecting to RabbitMQ on {$host}:{$port}...\n";
    $connection = new AMQPStreamConnection($host, $port, $user, $pass, $vhost);
    $channel    = $connection->channel();

    // Declare exchange idempotently — safe even if already declared by setup script.
    $channel->exchange_declare('planning.exchange', 'topic', false, true, false);

    $msg = new AMQPMessage($xml, [
        'delivery_mode' => 2,
        'content_type'  => 'application/xml',
    ]);
    $channel->basic_publish($msg, 'planning.exchange', 'planning.session.created');

    echo "✓ Simulated session_created published to planning.exchange\n";
    echo "  session_id    : {$sessionId}\n";
    echo "  title         : {$title}\n";
    echo "  session_type  : {$sessionType}\n";
    echo "  max_attendees : {$maxAttendees}\n";
    echo "  routing key   : planning.session.created\n";

    $channel->close();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Simulate producer failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($connection !== null) {
        $connection->close();
    }
}
