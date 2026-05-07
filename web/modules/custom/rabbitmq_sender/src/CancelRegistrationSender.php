<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Sends cancel_registration messages to CRM via crm.incoming (contract §5.6).
 */
class CancelRegistrationSender
{
    use RetryTrait;

    private ?RabbitMQClient $client;

    private const QUEUE_NAME = 'crm.incoming';
    private const SOURCE     = 'frontend';
    private const TYPE       = 'cancel_registration';
    private const VERSION    = '2.0';

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        $this->validate($data);

        $xml = $this->buildXml($data);

        $this->sendWithRetry(function () use ($xml): void {
            $this->resolveClient()->declareQueue(self::QUEUE_NAME);
            $msg = new AMQPMessage($xml, [
                'delivery_mode' => 2,
                'content_type'  => 'application/xml',
            ]);
            $this->resolveClient()->getChannel()->basic_publish($msg, '', self::QUEUE_NAME);
        });
    }

    public function buildXml(array $data): string
    {
        $this->validate($data);

        $messageId     = $this->generateUuidV4();
        $timestamp     = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
        $correlationId = (string) $data['correlation_id'];
        $identityUuid  = (string) ($data['identity_uuid'] ?? $data['user_id'] ?? '');

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
        $header->appendChild($dom->createElement('correlation_id', htmlspecialchars($correlationId, ENT_XML1, 'UTF-8')));
        $message->appendChild($header);

        $body = $dom->createElement('body');
        $body->appendChild($dom->createElement('identity_uuid', htmlspecialchars($identityUuid, ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('session_id', htmlspecialchars((string) $data['session_id'], ENT_XML1, 'UTF-8')));

        if (array_key_exists('reason', $data) && $data['reason'] !== null) {
            $body->appendChild($dom->createElement('reason', htmlspecialchars((string) $data['reason'], ENT_XML1, 'UTF-8')));
        }

        $message->appendChild($body);

        return $dom->saveXML() ?: '';
    }

    private function validate(array $data): void
    {
        if (empty($data['identity_uuid']) && empty($data['user_id'])) {
            throw new \InvalidArgumentException('identity_uuid is required');
        }
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }
        if (empty($data['correlation_id'])) {
            throw new \InvalidArgumentException('correlation_id is required');
        }
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
