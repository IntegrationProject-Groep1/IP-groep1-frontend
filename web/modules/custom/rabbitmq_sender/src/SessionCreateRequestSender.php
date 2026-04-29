<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

/**
 * Sends session_create_request messages to Planning.
 *
 * Frontend publishes on:
 *   Exchange:    planning.exchange      (topic, durable)
 *   Routing key: frontend.to.planning.session.create
 *
 * Required body fields: title, start_datetime, end_datetime
 * Optional body fields: location, session_type, status, max_attendees
 */
class SessionCreateRequestSender
{
    use RetryTrait;

    private const EXCHANGE      = 'planning.exchange';
    private const ROUTING_KEY   = 'frontend.to.planning.session.create';
    private const EXCHANGE_TYPE = 'topic';
    private const SOURCE        = 'frontend';
    private const TYPE          = 'session_create_request';
    private const NAMESPACE     = 'urn:integration:planning:v1';
    private const VERSION       = '1.0';

    private ?RabbitMQClient $client;

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        $xml = $this->buildXml($data);

        $this->sendWithRetry(function () use ($xml): void {
            $this->resolveClient()->declareExchange(self::EXCHANGE, self::EXCHANGE_TYPE);
            $this->resolveClient()->publishToExchange(self::EXCHANGE, self::ROUTING_KEY, $xml);
        });
    }

    public function buildXml(array $data): string
    {
        if (empty($data['title'])) {
            throw new \InvalidArgumentException('title is required');
        }
        if (empty($data['start_datetime'])) {
            throw new \InvalidArgumentException('start_datetime is required');
        }
        if (empty($data['end_datetime'])) {
            throw new \InvalidArgumentException('end_datetime is required');
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
        $header->appendChild($dom->createElement('version', self::VERSION));
        $message->appendChild($header);

        $body = $dom->createElement('body');
        $body->appendChild($dom->createElement('title', htmlspecialchars((string) $data['title'], ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('start_datetime', htmlspecialchars((string) $data['start_datetime'], ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('end_datetime', htmlspecialchars((string) $data['end_datetime'], ENT_XML1, 'UTF-8')));

        if (!empty($data['location'])) {
            $body->appendChild($dom->createElement('location', htmlspecialchars((string) $data['location'], ENT_XML1, 'UTF-8')));
        }
        if (!empty($data['session_type'])) {
            $body->appendChild($dom->createElement('session_type', htmlspecialchars((string) $data['session_type'], ENT_XML1, 'UTF-8')));
        }
        if (!empty($data['status'])) {
            $body->appendChild($dom->createElement('status', htmlspecialchars((string) $data['status'], ENT_XML1, 'UTF-8')));
        }
        if (isset($data['max_attendees'])) {
            $body->appendChild($dom->createElement('max_attendees', (string) (int) $data['max_attendees']));
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
            (int) (getenv('RABBITMQ_PORT') ?: 5672),
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
