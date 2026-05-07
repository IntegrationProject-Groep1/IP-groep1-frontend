<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes user_registered events to RabbitMQ (v2.0 contract).
 */
class UserRegisteredSender
{
    use RetryTrait;
    use XmlValidationTrait;

    private ?RabbitMQClient $client;

    private const QUEUE_NAME = 'crm.incoming';
    private const SOURCE     = 'frontend';
    private const TYPE       = 'user_registered';
    private const VERSION    = '2.0';
    private const XSD_PATH   = __DIR__ . '/../../../../../xsd/user_registered.xsd';

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        if (empty($data['user_id'])) {
            throw new \InvalidArgumentException('user_id is required');
        }
        if (empty($data['email'])) {
            throw new \InvalidArgumentException('email is required');
        }
        if (empty($data['session_id'])) {
            throw new \InvalidArgumentException('session_id is required');
        }
        // session_title is optional in contract §5.5; no validation needed

        \Drupal::logger('rabbitmq_sender')->info('Sending user registered event', [
            'user_id'    => $data['user_id'],
            'session_id' => $data['session_id'],
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
        });
    }

    public function buildXml(array $data): string
    {
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $message = $dom->createElement('message');
        $message->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $dom->appendChild($message);

        // Header order per contract §5.5: message_id, timestamp, source, type, version, correlation_id (optional, last)
        $header = $dom->createElement('header');
        $header->appendChild($dom->createElement('message_id', $messageId));
        $header->appendChild($dom->createElement('timestamp', $timestamp));
        $header->appendChild($dom->createElement('source', self::SOURCE));
        $header->appendChild($dom->createElement('type', self::TYPE));
        $header->appendChild($dom->createElement('version', self::VERSION));
        $header->appendChild($dom->createElement('correlation_id', $messageId));
        $message->appendChild($header);

        $body     = $dom->createElement('body');
        $customer = $dom->createElement('customer');

        // identity_uuid: master UUID from Identity Service (falls back to user_id)
        $identityUuid = (string) ($data['identity_uuid'] ?? $data['user_id'] ?? '');
        $customer->appendChild($dom->createElement('identity_uuid', htmlspecialchars($identityUuid, ENT_XML1, 'UTF-8')));
        $customer->appendChild($dom->createElement('email', htmlspecialchars((string) $data['email'], ENT_XML1, 'UTF-8')));

        $contact = $dom->createElement('contact');
        $contact->appendChild($dom->createElement('first_name', htmlspecialchars($data['first_name'] ?? '', ENT_XML1, 'UTF-8')));
        $contact->appendChild($dom->createElement('last_name', htmlspecialchars($data['last_name'] ?? '', ENT_XML1, 'UTF-8')));
        $customer->appendChild($contact);

        // type: private or company
        $type = !empty($data['is_company']) ? 'company' : 'private';
        $customer->appendChild($dom->createElement('type', $type));

        // company fields (flat siblings per contract §5.5)
        if (!empty($data['is_company'])) {
            if (!empty($data['company_name'])) {
                $customer->appendChild($dom->createElement('company_name', htmlspecialchars((string) $data['company_name'], ENT_XML1, 'UTF-8')));
            }
            if (!empty($data['vat_number'])) {
                $customer->appendChild($dom->createElement('vat_number', htmlspecialchars((string) $data['vat_number'], ENT_XML1, 'UTF-8')));
            }
        }

        // session_id inside <customer> per contract §5.5
        $customer->appendChild($dom->createElement('session_id', htmlspecialchars((string) $data['session_id'], ENT_XML1, 'UTF-8')));

        $body->appendChild($customer);

        // session_title outside <customer>, optional
        if (!empty($data['session_title'])) {
            $body->appendChild($dom->createElement('session_title', htmlspecialchars((string) $data['session_title'], ENT_XML1, 'UTF-8')));
        } elseif (!empty($data['session_name'])) {
            $body->appendChild($dom->createElement('session_title', htmlspecialchars((string) $data['session_name'], ENT_XML1, 'UTF-8')));
        }

        $body->appendChild($dom->createElement('payment_status', 'pending'));
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
