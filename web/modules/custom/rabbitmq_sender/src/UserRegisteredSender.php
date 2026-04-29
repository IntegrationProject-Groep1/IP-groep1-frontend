<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

/**
 * Publishes session-registration events to the CRM via the user.exchange topic exchange.
 *
 * CRM's consumer listens on:
 *   Exchange:    user.exchange     (topic, durable)
 *   Routing key: user.registered
 *   Queue:       crm.user.registered
 *
 * Required body fields: email, first_name
 * Optional body fields: last_name, session_id, session_name, is_company
 */
class UserRegisteredSender
{
    use RetryTrait;

    private const EXCHANGE      = 'user.exchange';
    private const ROUTING_KEY   = 'user.registered';
    private const EXCHANGE_TYPE = 'topic';
    private const SOURCE        = 'frontend';
    private const TYPE          = 'user.registered';
    private const NAMESPACE     = 'urn:integration:planning:v1';

    private ?RabbitMQClient $client;

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        if (empty($data['email'])) {
            throw new \InvalidArgumentException('email is required');
        }
        if (empty($data['first_name'])) {
            throw new \InvalidArgumentException('first_name is required');
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
            $this->resolveClient()->declareExchange(self::EXCHANGE, self::EXCHANGE_TYPE);
            $this->resolveClient()->publishToExchange(self::EXCHANGE, self::ROUTING_KEY, $xml);
        });
    }

    public function buildXml(array $data): string
    {
        if (empty($data['email'])) {
            throw new \InvalidArgumentException('email is required');
        }
        if (empty($data['first_name'])) {
            throw new \InvalidArgumentException('first_name is required');
        }

        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $message = $dom->createElementNS(self::NAMESPACE, 'message');
        $dom->appendChild($message);

        $header = $dom->createElement('header');
        $header->appendChild($dom->createElement('message_id', $messageId));
        $header->appendChild($dom->createElement('timestamp', $timestamp));
        $header->appendChild($dom->createElement('source', self::SOURCE));
        $header->appendChild($dom->createElement('type', self::TYPE));
        $message->appendChild($header);

        $body = $dom->createElement('body');
        $body->appendChild($dom->createElement('email',        htmlspecialchars((string) $data['email'],        ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('first_name',   htmlspecialchars((string) $data['first_name'],   ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('last_name',    htmlspecialchars((string) ($data['last_name'] ?? ''), ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('is_company',   !empty($data['is_company']) ? 'true' : 'false'));

        if (!empty($data['session_id'])) {
            $body->appendChild($dom->createElement('session_id',   htmlspecialchars((string) $data['session_id'],   ENT_XML1, 'UTF-8')));
        }
        if (!empty($data['session_name'])) {
            $body->appendChild($dom->createElement('session_name', htmlspecialchars((string) $data['session_name'], ENT_XML1, 'UTF-8')));
        }

        $message->appendChild($body);

        return $dom->saveXML() ?: '';
    }

    private function resolveClient(): RabbitMQClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = new RabbitMQClient(
            (string) (getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker'),
            (int)    (getenv('RABBITMQ_PORT') ?: 5672),
            (string) (getenv('RABBITMQ_USER') ?: 'guest'),
            (string) (getenv('RABBITMQ_PASS') ?: 'guest'),
            (string) (getenv('RABBITMQ_VHOST') ?: '/')
        );

        return $this->client;
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}