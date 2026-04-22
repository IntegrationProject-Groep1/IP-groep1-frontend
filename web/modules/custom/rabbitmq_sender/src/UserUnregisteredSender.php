<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes user unregistration events to downstream queues.
 */
class UserUnregisteredSender
{
    use RetryTrait;

    private RabbitMQClient $client;

    private const QUEUES = [
        'crm.salesforce',
        'planning.outlook',
        'mailing.sendgrid',
    ];

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        // Validate mandatory identifiers before broadcasting the unregistration event.
        if (empty($data['user_id'])) {
            throw new \InvalidArgumentException('user_id is required');
        }
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }

        // ✅ Logging (business event)
        \Drupal::logger('rabbitmq_sender')->info('Sending user unregistered event', [
            'user_id' => $data['user_id'],
            'session_id' => $data['session_id'],
            'queues' => self::QUEUES,
        ]);

        $xml = $this->buildXml($data);

        // Fan out to all subscribed integration queues.
        foreach (self::QUEUES as $queue) {
            $this->sendWithRetry(function () use ($xml, $queue): void {
                $msg = new AMQPMessage($xml, ['delivery_mode' => 2]);
                $this->client->getChannel()->basic_publish($msg, '', $queue);
            });
        }
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
        $xml .= '<receiver>crm.salesforce planning.outlook mailing.sendgrid</receiver>';
        $xml .= '<type>user.unregistered</type>';
        $xml .= '<version>1.0</version>';
        $xml .= '<correlation_id></correlation_id>';
        $xml .= '</header>';

        $xml .= '<body>';
        $xml .= '<user_id>' . htmlspecialchars($data['user_id'], ENT_XML1, 'UTF-8') . '</user_id>';
        $xml .= '<session_id>' . htmlspecialchars($data['session_id'], ENT_XML1, 'UTF-8') . '</session_id>';
        $xml .= "<timestamp>{$timestamp}</timestamp>";
        $xml .= '</body>';

        $xml .= '</message>';

        return $xml;
    }
}