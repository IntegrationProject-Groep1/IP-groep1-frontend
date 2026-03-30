<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$host = $_ENV['RABBITMQ_HOST'] ?? 'localhost';
$port = (int) ($_ENV['RABBITMQ_PORT'] ?? 5672);
$user = $_ENV['RABBITMQ_USER'] ?? 'guest';
$pass = $_ENV['RABBITMQ_PASS'] ?? 'guest';

$connection = new AMQPStreamConnection($host, $port, $user, $pass);
$channel = $connection->channel();

$channel->queue_declare('heartbeat.frontend', false, true, false, false);

$startTime = time();

echo "Heartbeat started...\n";

while (true) {
    $uptimeSeconds = time() - $startTime;
    $timestamp = (new \DateTime())->format('c');
    $messageId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<message>';
    $xml .= '<header>';
    $xml .= "<message_id>{$messageId}</message_id>";
    $xml .= "<timestamp>{$timestamp}</timestamp>";
    $xml .= '<source>frontend.drupal</source>';
    $xml .= '<receiver>monitoring.elastic</receiver>';
    $xml .= '<type>heartbeat.frontend</type>';
    $xml .= '<version>1.0</version>';
    $xml .= '</header>';
    $xml .= '<payload>';
    $xml .= '<status>online</status>';
    $xml .= "<uptime_seconds>{$uptimeSeconds}</uptime_seconds>";
    $xml .= '<version>1.0.0</version>';
    $xml .= '</payload>';
    $xml .= '</message>';

    $msg = new AMQPMessage($xml, ['delivery_mode' => 2]);
    $channel->basic_publish($msg, '', 'heartbeat.frontend');

    echo "Heartbeat sent at {$timestamp}\n";

    sleep(1);
}