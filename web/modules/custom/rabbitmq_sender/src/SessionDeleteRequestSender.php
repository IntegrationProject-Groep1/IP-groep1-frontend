<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

/**
 * Sends session_delete_request messages to Planning.
 *
 * Frontend publishes on:
 *   Exchange:    planning.exchange      (topic, durable)
 *   Routing key: planning.session.delete.request
 *
 * Required body fields: session_id
 * Optional body fields: reason
 */
class SessionDeleteRequestSender
{
    use RetryTrait;

    private const EXCHANGE      = 'planning.exchange';
    private const ROUTING_KEY   = 'frontend.to.planning.session.delete';
    private const EXCHANGE_TYPE = 'topic';
    private const SOURCE        = 'frontend';
    private const TYPE          = 'session_delete_request';
    private const VERSION       = '2.0';

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
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }

        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $message = $dom->createElement('message');
        $dom->appendChild($message);

        $header = $dom->createElement('header');
        $header->appendChild($dom->createElement('message_id', $messageId));
        $header->appendChild($dom->createElement('timestamp', $timestamp));
        $header->appendChild($dom->createElement('source', self::SOURCE));
        $header->appendChild($dom->createElement('type', self::TYPE));
        $header->appendChild($dom->createElement('version', self::VERSION));
        $message->appendChild($header);

        $body = $dom->createElement('body');
        $body->appendChild($dom->createElement('session_id', htmlspecialchars((string) $data['session_id'], ENT_XML1, 'UTF-8')));

        if (!empty($data['reason'])) {
            $body->appendChild($dom->createElement('reason', htmlspecialchars((string) $data['reason'], ENT_XML1, 'UTF-8')));
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
