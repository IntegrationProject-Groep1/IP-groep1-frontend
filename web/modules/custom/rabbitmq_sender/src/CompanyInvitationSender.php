<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes company invitation messages to CRM via RabbitMQ.
 */
class CompanyInvitationSender
{
    use RetryTrait;

    private ?RabbitMQClient $client;

    private const QUEUE_NAME = 'crm.incoming';
    private const SOURCE = 'frontend';
    private const TYPE = 'company_invite';
    private const VERSION = '2.0';

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        if (empty($data['invitee_email'])) {
            throw new \InvalidArgumentException('invitee_email is required');
        }
        if (filter_var((string) $data['invitee_email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('invitee_email must be a valid email address');
        }
        if (empty($data['inviter_user_id'])) {
            throw new \InvalidArgumentException('inviter_user_id is required');
        }

        $xml = $this->buildXml($data);

        $this->sendWithRetry(function () use ($xml): void {
            $this->resolveClient()->declareQueue(self::QUEUE_NAME);
            $msg = new AMQPMessage($xml, [
                'delivery_mode' => 2,
                'content_type' => 'application/xml',
            ]);
            $this->resolveClient()->getChannel()->basic_publish($msg, '', self::QUEUE_NAME);
        });
    }

    public function buildXml(array $data): string
    {
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $dom = new \DOMDocument('1.0', 'UTF-8');

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
        $invitation = $dom->createElement('invitation');

        $invitation->appendChild($dom->createElement('invitee_email', htmlspecialchars((string) $data['invitee_email'], ENT_XML1, 'UTF-8')));
        $invitation->appendChild($dom->createElement('inviter_user_id', htmlspecialchars((string) $data['inviter_user_id'], ENT_XML1, 'UTF-8')));

        if (!empty($data['company_id'])) {
            $invitation->appendChild($dom->createElement('company_id', htmlspecialchars((string) $data['company_id'], ENT_XML1, 'UTF-8')));
        }

        if (!empty($data['company_name'])) {
            $invitation->appendChild($dom->createElement('company_name', htmlspecialchars((string) $data['company_name'], ENT_XML1, 'UTF-8')));
        }

        $body->appendChild($invitation);
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
