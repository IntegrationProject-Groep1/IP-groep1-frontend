<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

/**
 * Sends cancel_registration messages to CRM (contract §5.6).
 * CRM routes the message onward to Kassa and Planning (§10.3).
 *
 * Frontend publishes on:
 *   Queue: crm.incoming  (direct, durable)
 */
class CancelRegistrationSender
{
    use RetryTrait;
    use XmlValidationTrait;

    private const QUEUE_NAME = 'crm.incoming';
    private const SOURCE     = 'frontend';
    private const TYPE       = 'cancel_registration';
    private const VERSION    = '2.0';
    private const XSD_PATH   = __DIR__ . '/../../../../../xsd/cancel_registration.xsd';

    private ?RabbitMQClient $client;

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(string $sessionId, string $identityUuid): void
    {
        $this->assertValidUuid($identityUuid, 'identity_uuid');

        $correlationId = $this->generateUuidV4();
        $xml = $this->buildXml($sessionId, $identityUuid, $correlationId);
        $this->validateXml($xml, self::XSD_PATH);

        $this->sendWithRetry(function () use ($xml): void {
            $this->resolveClient()->declareQueue(self::QUEUE_NAME);
            $this->resolveClient()->publishToQueue(self::QUEUE_NAME, $xml);
            $this->logOutboundSuccess(self::TYPE, self::QUEUE_NAME, $xml);
        });
    }

    public function buildXml(string $sessionId, string $identityUuid, string $correlationId): string
    {
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $message = $dom->createElement('message');
        $dom->appendChild($message);

        $header = $dom->createElement('header');
        $header->appendChild($dom->createElement('message_id',     $messageId));
        $header->appendChild($dom->createElement('timestamp',      $timestamp));
        $header->appendChild($dom->createElement('source',         self::SOURCE));
        $header->appendChild($dom->createElement('type',           self::TYPE));
        $header->appendChild($dom->createElement('version',        self::VERSION));
        $header->appendChild($dom->createElement('correlation_id', $correlationId));
        $message->appendChild($header);

        $body = $dom->createElement('body');
        $body->appendChild($dom->createElement('identity_uuid', htmlspecialchars($identityUuid, ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('session_id',    htmlspecialchars($sessionId,    ENT_XML1, 'UTF-8')));
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
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
