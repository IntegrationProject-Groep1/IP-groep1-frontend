<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

/**
 * Publishes user_unregistered events to RabbitMQ (v2.3 contract §5.5b).
 * Dual-publish: crm.incoming (CRM) + kassa.exchange (Kassa).
 */
class UserUnregisteredSender
{
    use RetryTrait;
    use XmlValidationTrait;

    private ?RabbitMQClient $client;

    private const QUEUE_CRM         = 'crm.incoming';
    private const EXCHANGE_KASSA    = 'kassa.exchange';
    private const ROUTING_KEY_KASSA = 'kassa.incoming.user_unregistered';
    private const EXCHANGE_TYPE     = 'topic';
    private const SOURCE            = 'frontend';
    private const TYPE              = 'user_unregistered';
    private const VERSION           = '2.0';
    private const XSD_PATH          = __DIR__ . '/../../../../../xsd/user_unregistered.xsd';

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        if (empty($data['identity_uuid'])) {
            throw new \InvalidArgumentException('identity_uuid is required');
        }
        $this->assertValidUuid((string) $data['identity_uuid'], 'identity_uuid');
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }

        \Drupal::logger('rabbitmq_sender')->info('Sending user_unregistered event', [
            'identity_uuid' => $data['identity_uuid'],
            'session_id'    => $data['session_id'],
        ]);

        $xml = $this->buildXml($data);
        $this->validateXml($xml, self::XSD_PATH);

        $this->sendWithRetry(function () use ($xml): void {
            // Publish to CRM
            $this->resolveClient()->declareQueue(self::QUEUE_CRM);
            $this->resolveClient()->publishToQueue(self::QUEUE_CRM, $xml);
            $this->logOutboundSuccess(self::TYPE, self::QUEUE_CRM, $xml);

            // Dual-publish to Kassa (contract §5.5b v2.3)
            $this->resolveClient()->declareExchange(self::EXCHANGE_KASSA, self::EXCHANGE_TYPE);
            $this->resolveClient()->publishToExchange(self::EXCHANGE_KASSA, self::ROUTING_KEY_KASSA, $xml);
            $this->logOutboundSuccess(self::TYPE, self::ROUTING_KEY_KASSA, $xml);
        });
    }

    public function buildXml(array $data): string
    {
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

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
        $body->appendChild($dom->createElement('identity_uuid', htmlspecialchars((string) $data['identity_uuid'], ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('session_id', htmlspecialchars((string) $data['session_id'], ENT_XML1, 'UTF-8')));

        if (!empty($data['session_title'])) {
            $body->appendChild($dom->createElement('session_title', htmlspecialchars((string) $data['session_title'], ENT_XML1, 'UTF-8')));
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
            getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker',
            (int) (getenv('RABBITMQ_PORT') ?: '5672'),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASS') ?: 'guest',
            getenv('RABBITMQ_VHOST') ?: '/'
        );

        return $this->client;
    }

    private function generateUuidV4(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
