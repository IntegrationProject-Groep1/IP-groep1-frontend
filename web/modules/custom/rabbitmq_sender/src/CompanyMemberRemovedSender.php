<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes company_member_removed events to RabbitMQ (v2.0 contract).
 *
 * Triggered when an invite is revoked or a member is manually removed.
 */
class CompanyMemberRemovedSender
{
    use RetryTrait;
    use XmlValidationTrait;

    private ?RabbitMQClient $client;

    private const QUEUE_NAME = 'crm.incoming';
    private const SOURCE     = 'frontend';
    private const TYPE       = 'company_member_removed';
    private const VERSION    = '2.0';
    private const XSD_PATH   = __DIR__ . '/../../../../../xsd/company_member_removed.xsd';

    private const VALID_REASONS = ['invite_revoked', 'admin_removed'];

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

        if (empty($data['email'])) {
            throw new \InvalidArgumentException('email is required');
        }

        if (empty($data['company_id'])) {
            throw new \InvalidArgumentException('company_id is required');
        }

        if (empty($data['reason']) || !in_array($data['reason'], self::VALID_REASONS, true)) {
            throw new \InvalidArgumentException('reason must be one of: ' . implode(', ', self::VALID_REASONS));
        }

        \Drupal::logger('rabbitmq_sender')->info('Sending company_member_removed event', [
            'identity_uuid' => $data['identity_uuid'],
            'company_id'    => $data['company_id'],
            'reason'        => $data['reason'],
        ]);

        $xml = $this->buildXml($data);
        $this->validateXml($xml, self::XSD_PATH);

        $this->sendWithRetry(function () use ($xml): void {
            $this->resolveClient()->declareQueue(self::QUEUE_NAME);
            $msg = new AMQPMessage($xml, [
                'delivery_mode' => 2,
                'content_type'  => 'application/xml',
            ]);
            $this->resolveClient()->getChannel()->basic_publish($msg, '', self::QUEUE_NAME);
            $this->logOutboundSuccess(self::TYPE, self::QUEUE_NAME, $xml);
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
        if (!empty($data['correlation_id'])) {
            $header->appendChild($dom->createElement('correlation_id', htmlspecialchars((string) $data['correlation_id'], ENT_XML1, 'UTF-8')));
        }
        $message->appendChild($header);

        $customer = $dom->createElement('customer');
        $customer->appendChild($dom->createElement('identity_uuid', htmlspecialchars((string) $data['identity_uuid'], ENT_XML1, 'UTF-8')));
        $customer->appendChild($dom->createElement('email', htmlspecialchars((string) $data['email'], ENT_XML1, 'UTF-8')));
        $customer->appendChild($dom->createElement('company_id', htmlspecialchars((string) $data['company_id'], ENT_XML1, 'UTF-8')));
        $customer->appendChild($dom->createElement('reason', htmlspecialchars((string) $data['reason'], ENT_XML1, 'UTF-8')));

        $body = $dom->createElement('body');
        $body->appendChild($customer);
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
