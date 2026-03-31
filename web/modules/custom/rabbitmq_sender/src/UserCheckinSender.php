<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes user check-in events to RabbitMQ.
 */
class UserCheckinSender
{
    use RetryTrait;

    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        // Validate mandatory identifiers before publishing the check-in event.
        if (empty($data['user_id'])) {
            throw new \InvalidArgumentException('user_id is required');
        }
        if (empty($data['badge_id'])) {
            throw new \InvalidArgumentException('badge_id is required');
        }

        $xml = $this->buildXml($data);
        $this->sendWithRetry(function () use ($xml): void {
            $msg = new AMQPMessage($xml, ['delivery_mode' => 2]);
            $this->client->getChannel()->basic_publish($msg, '', 'user.checkin');
        });
    }

    public function buildXml(array $data): string
    {
        // Generate an event-scoped identifier for traceability in downstream systems.
        $messageId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $timestamp = (new \DateTime())->format('c');

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<message xmlns="urn:integration:planning:v1">';
        $xml .= '<header>';
        $xml .= "<message_id>{$messageId}</message_id>";
        $xml .= "<timestamp>{$timestamp}</timestamp>";
        $xml .= '<source>frontend.drupal</source>';
        $xml .= '<receiver>monitoring.elastic</receiver>';
        $xml .= '<type>user.checkin</type>';
        $xml .= '<version>1.0</version>';
        $xml .= '<correlation_id></correlation_id>';
        $xml .= '</header>';
        $xml .= '<body>';
        $xml .= '<user_id>' . htmlspecialchars($data['user_id'], ENT_XML1, 'UTF-8') . '</user_id>';
        $xml .= '<badge_id>' . htmlspecialchars($data['badge_id'], ENT_XML1, 'UTF-8') . '</badge_id>';
        $xml .= "<timestamp>{$timestamp}</timestamp>";
        $xml .= '</body>';
        $xml .= '</message>';

        return $xml;
    }
}