<?php
declare(strict_types=1);

/**
 * Setup script: declares all exchanges, queues and bindings needed for the
 * Planning integration on a local RabbitMQ instance.
 *
 * Run ONCE after starting the local broker:
 *   docker-compose -f docker-compose.local.yml up -d
 *   php scripts/setup_planning_exchanges.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

$host  = getenv('RABBITMQ_HOST') ?: 'localhost';
$port  = (int) (getenv('RABBITMQ_PORT') ?: '5672');
$user  = getenv('RABBITMQ_USER') ?: 'guest';
$pass  = getenv('RABBITMQ_PASS') ?: 'guest';
$vhost = getenv('RABBITMQ_VHOST') ?: '/';

echo "Connecting to RabbitMQ on {$host}:{$port} (vhost: {$vhost})...\n";

$connection = null;

try {
    $connection = new AMQPStreamConnection($host, $port, $user, $pass, $vhost);
    $channel    = $connection->channel();

    // ── calendar.exchange ────────────────────────────────────────────────────
    // We publish TO this exchange when sending a calendar.invite to Planning.
    $channel->exchange_declare('calendar.exchange', 'topic', false, true, false);
    echo "✓ Exchange declared: calendar.exchange (topic, durable)\n";

    // ── planning.exchange ────────────────────────────────────────────────────
    // Planning publishes session.created events here; we consume from a bound queue.
    $channel->exchange_declare('planning.exchange', 'topic', false, true, false);
    echo "✓ Exchange declared: planning.exchange (topic, durable)\n";

    // ── frontend.planning.session.created (queue) ────────────────────────────
    // Our durable consumer queue on the planning.exchange.
    $channel->queue_declare('frontend.planning.session.created', false, true, false, false);
    $channel->queue_bind('frontend.planning.session.created', 'planning.exchange', 'planning.session.created');
    echo "✓ Queue declared & bound: frontend.planning.session.created → planning.exchange (planning.session.created)\n";

    // ── frontend.planning.session.updated (queue) ────────────────────────────
    $channel->queue_declare('frontend.planning.session.updated', false, true, false, false);
    $channel->queue_bind('frontend.planning.session.updated', 'planning.exchange', 'planning.session.updated');
    echo "✓ Queue declared & bound: frontend.planning.session.updated → planning.exchange (planning.session.updated)\n";

    // ── frontend.planning.session.deleted (queue) ────────────────────────────
    $channel->queue_declare('frontend.planning.session.deleted', false, true, false, false);
    $channel->queue_bind('frontend.planning.session.deleted', 'planning.exchange', 'planning.session.deleted');
    echo "✓ Queue declared & bound: frontend.planning.session.deleted → planning.exchange (planning.session.deleted)\n";

    // ── frontend.planning.session.view.response (queue) ──────────────────────
    $channel->queue_declare('frontend.planning.session.view.response', false, true, false, false);
    $channel->queue_bind('frontend.planning.session.view.response', 'planning.exchange', 'planning.session.view.response');
    echo "✓ Queue declared & bound: frontend.planning.session.view.response → planning.exchange (planning.session.view.response)\n";

    // ── planning.calendar.invite (queue) ─────────────────────────────────────
    // Planning's consumer queue — we create it locally so test sends land somewhere.
    $channel->queue_declare('planning.calendar.invite', false, true, false, false);
    $channel->queue_bind('planning.calendar.invite', 'calendar.exchange', 'calendar.invite');
    echo "✓ Queue declared & bound: planning.calendar.invite → calendar.exchange (calendar.invite)\n";

    $channel->close();
    echo "\nAll exchanges and queues are ready.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Setup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($connection !== null) {
        $connection->close();
    }
}
