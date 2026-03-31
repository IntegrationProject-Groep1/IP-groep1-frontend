<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes user creation events to RabbitMQ.
 */
class UserCreatedSender
{
    use RetryTrait;

    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        // Email is the primary identity field expected by downstream systems.
        if (empty($data['email'])) {
            throw new \InvalidArgumentException('email is required');
        }

        $xml = $this->buildXml($data);
        $this->sendWithRetry(function () use ($xml): void {
            $msg = new AMQPMessage($xml, ['delivery_mode' => 2]);
            $this->client->getChannel()->basic_publish($msg, '', 'user.created');
        });
    }

    public function buildXml(array $data): string
    {
        // Generate a UUID-like identifier for event-level observability.
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
        $xml .= '<receiver>crm.salesforce</receiver>';
        $xml .= '<type>user.created</type>';
        $xml .= '<version>1.0</version>';
        $xml .= '<correlation_id></correlation_id>';
        $xml .= '</header>';
        $xml .= '<body>';
        $xml .= '<user>';
        $xml .= '<first_name>' . htmlspecialchars($data['first_name'] ?? '', ENT_XML1, 'UTF-8') . '</first_name>';
        $xml .= '<last_name>' . htmlspecialchars($data['last_name'] ?? '', ENT_XML1, 'UTF-8') . '</last_name>';
        $xml .= '<email>' . htmlspecialchars($data['email'], ENT_XML1, 'UTF-8') . '</email>';
        $xml .= '<is_company>' . (!empty($data['is_company']) ? 'true' : 'false') . '</is_company>';

        if (!empty($data['is_company'])) {
            $xml .= '<company>';
            $xml .= '<name>' . htmlspecialchars($data['company_name'] ?? '', ENT_XML1, 'UTF-8') . '</name>';
            $xml .= '<vat_number>' . htmlspecialchars($data['vat_number'] ?? '', ENT_XML1, 'UTF-8') . '</vat_number>';
            $xml .= '</company>';
        }

        $xml .= '</user>';
        $xml .= '</body>';
        $xml .= '</message>';

        return $xml;
    }
}