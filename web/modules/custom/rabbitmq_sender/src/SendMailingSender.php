<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes mailing commands to RabbitMQ (v2.0 contract).
 */
class SendMailingSender
{
    use RetryTrait;
    use XmlValidationTrait;

    private ?RabbitMQClient $client;
    private const QUEUE_NAME = 'mailing.sendgrid'; // Legacy/Direct queue from previous context
    private const CONTRACT_QUEUE = 'crm.to.mailing';
    private const SOURCE     = 'frontend';
    private const TYPE       = 'send_mailing';
    private const VERSION    = '2.0';
    private const XSD_PATH   = __DIR__ . '/../../../../../xsd/send_mailing.xsd';

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data, string $queueName = self::QUEUE_NAME): void
    {
        if (empty($data['campaign_id'])) {
            throw new \InvalidArgumentException('campaign_id is required');
        }
        if (empty($data['subject'])) {
            throw new \InvalidArgumentException('subject is required');
        }
        if (empty($data['mail_type'])) {
            throw new \InvalidArgumentException('mail_type is required');
        }
        if (empty($data['recipients'])) {
            throw new \InvalidArgumentException('recipients are required');
        }

        $xml = $this->buildXml($data);
        $this->validateXml($xml, self::XSD_PATH);

        $this->sendWithRetry(function () use ($xml, $queueName): void {
            $this->resolveClient()->declareQueue($queueName);
            $msg = new AMQPMessage($xml, [
                'delivery_mode' => 2,
                'content_type'  => 'application/xml',
            ]);
            $this->resolveClient()->getChannel()->basic_publish($msg, '', $queueName);
            $this->logOutboundSuccess(self::TYPE, $queueName, $xml);
        });
    }

    public function buildXml(array $data): string
    {
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $correlationId = $data['correlation_id'] ?? $messageId;

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
        $header->appendChild($dom->createElement('correlation_id', $correlationId));
        $message->appendChild($header);

        $body = $dom->createElement('body');
        $body->appendChild($dom->createElement('campaign_id', htmlspecialchars((string) $data['campaign_id'], ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('subject', htmlspecialchars((string) $data['subject'], ENT_XML1, 'UTF-8')));
        $body->appendChild($dom->createElement('mail_type', htmlspecialchars((string) $data['mail_type'], ENT_XML1, 'UTF-8')));

        $recipients = $dom->createElement('recipients');
        foreach ($data['recipients'] as $recipientData) {
            $recipient = $dom->createElement('recipient');
            $recipient->appendChild($dom->createElement('email', htmlspecialchars((string) $recipientData['email'], ENT_XML1, 'UTF-8')));
            $recipient->appendChild($dom->createElement('identity_uuid', htmlspecialchars((string) $recipientData['identity_uuid'], ENT_XML1, 'UTF-8')));
            
            $contact = $dom->createElement('contact');
            $contact->appendChild($dom->createElement('first_name', htmlspecialchars((string) ($recipientData['first_name'] ?? ''), ENT_XML1, 'UTF-8')));
            $contact->appendChild($dom->createElement('last_name', htmlspecialchars((string) ($recipientData['last_name'] ?? ''), ENT_XML1, 'UTF-8')));
            $recipient->appendChild($contact);
            
            $recipients->appendChild($recipient);
        }
        $body->appendChild($recipients);

        if (!empty($data['template_data'])) {
            $body->appendChild($dom->createElement('template_data', htmlspecialchars((string) $data['template_data'], ENT_XML1, 'UTF-8')));
        }
        if (!empty($data['body_html'])) {
            $body->appendChild($dom->createElement('body_html', htmlspecialchars((string) $data['body_html'], ENT_XML1, 'UTF-8')));
        }

        if (!empty($data['attachment'])) {
            $attachment = $dom->createElement('attachment');
            $attachment->appendChild($dom->createElement('filename', htmlspecialchars((string) $data['attachment']['filename'], ENT_XML1, 'UTF-8')));
            $attachment->appendChild($dom->createElement('content_type', htmlspecialchars((string) $data['attachment']['content_type'], ENT_XML1, 'UTF-8')));
            $attachment->appendChild($dom->createElement('base64_data', (string) $data['attachment']['base64_data']));
            $body->appendChild($attachment);
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
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
