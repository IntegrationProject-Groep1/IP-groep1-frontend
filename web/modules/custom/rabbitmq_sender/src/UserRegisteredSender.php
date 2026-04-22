<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes user registration events for downstream consumers.
 */
class UserRegisteredSender
{
    use RetryTrait;

    private ?RabbitMQClient $client;
    private string $queueName;

    public function __construct(?RabbitMQClient $client = null, ?string $queueName = null)
    {
        $this->client = $client;
        $prefix = getenv('RABBITMQ_PREFIX') ?: 'frontend.';
        $this->queueName = $queueName ?? ($prefix . 'user.registered');
    }

    public function send(array $data): void
    {
        // Enforce minimum payload validity before constructing XML.
        if (empty($data['email'])) {
            throw new \InvalidArgumentException('email is required');
        }
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }
        if (!empty($data['is_company']) && empty($data['vat_number'])) {
            throw new \InvalidArgumentException('vat_number is required for companies');
        }

        // ✅ Logging (business event)
        \Drupal::logger('rabbitmq_sender')->info('Sending user registered event', [
            'email' => $data['email'],
            'session_id' => $data['session_id'],
        ]);

        // ✅ Logging (business event)
        \Drupal::logger('rabbitmq_sender')->info('Sending user registered event', [
            'email' => $data['email'],
            'session_id' => $data['session_id'],
        ]);

        $xml = $this->buildXml($data);

        $this->sendWithRetry(function () use ($xml): void {
            $msg = new AMQPMessage($xml, ['delivery_mode' => 2]);
            $this->resolveClient()->getChannel()->basic_publish($msg, '', $this->queueName);
        });
    }

    public function buildXml(array $data): string
    {
        // Generate a unique message identifier for traceability across systems.
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
        $xml .= '<type>user.registered</type>';
        $xml .= '<version>1.0</version>';
        $xml .= '<correlation_id></correlation_id>';
        $xml .= '</header>';
        $xml .= '<body>';
        $xml .= '<user>';
        $xml .= '<first_name>' . htmlspecialchars($data['first_name'], ENT_XML1, 'UTF-8') . '</first_name>';
        $xml .= '<last_name>' . htmlspecialchars($data['last_name'], ENT_XML1, 'UTF-8') . '</last_name>';
        $xml .= '<email>' . htmlspecialchars($data['email'], ENT_XML1, 'UTF-8') . '</email>';
        $xml .= '<is_company>' . ($data['is_company'] ? 'true' : 'false') . '</is_company>';

        if (!empty($data['is_company'])) {
            $xml .= '<company>';
            $xml .= '<name>' . htmlspecialchars($data['company_name'], ENT_XML1, 'UTF-8') . '</name>';
            $xml .= '<vat_number>' . htmlspecialchars($data['vat_number'], ENT_XML1, 'UTF-8') . '</vat_number>';
            $xml .= '</company>';
        }

        $xml .= '</user>';
        $xml .= '<session>';
        $xml .= '<session_id>' . htmlspecialchars($data['session_id'], ENT_XML1, 'UTF-8') . '</session_id>';
        $xml .= '<name>' . htmlspecialchars($data['session_name'], ENT_XML1, 'UTF-8') . '</name>';
        $xml .= '</session>';
        $xml .= '<payment_status>pending</payment_status>';
        $xml .= '</body>';
        $xml .= '</message>';

        return $xml;
    }

    private function resolveClient(): RabbitMQClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = new RabbitMQClient(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker',
            (int) (getenv('RABBITMQ_PORT') ?: '5672'),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASS') ?: 'guest',
            getenv('RABBITMQ_VHOST') ?: '/'
        );

        return $this->client;
    }
}