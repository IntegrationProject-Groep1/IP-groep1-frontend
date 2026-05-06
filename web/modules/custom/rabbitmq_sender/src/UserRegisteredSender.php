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

    private ?RabbitMQClient $client;

    private const QUEUE_NAME = 'crm.incoming';
    private const SOURCE     = 'frontend';
    private const TYPE       = 'user_registered';
    private const VERSION    = '2.0';

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
        if (empty($data['session_name'])) {
            throw new \InvalidArgumentException('session_name is required');
        }

        \Drupal::logger('rabbitmq_sender')->info('Sending user registered event', [
            'user_id'    => $data['user_id'],
            'session_id' => $data['session_id'],
        ]);

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
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $dom = new \DOMDocument('1.0', 'UTF-8');

        $message = $dom->createElement('message');
        $dom->appendChild($message);

        $header = $dom->createElement('header');
        $header->appendChild($dom->createElement('message_id', $messageId));
        $header->appendChild($dom->createElement('correlation_id', $messageId));
        $header->appendChild($dom->createElement('timestamp', $timestamp));
        $header->appendChild($dom->createElement('source', self::SOURCE));
        $header->appendChild($dom->createElement('type', self::TYPE));
        $header->appendChild($dom->createElement('version', self::VERSION));
        $message->appendChild($header);

        $body = $dom->createElement('body');
        $user = $dom->createElement('user');

        $user->appendChild($dom->createElement('user_id', htmlspecialchars((string) $data['user_id'], ENT_XML1, 'UTF-8')));
        $user->appendChild($dom->createElement('email', htmlspecialchars((string) $data['email'], ENT_XML1, 'UTF-8')));

        $contact = $dom->createElement('contact');
        $contact->appendChild($dom->createElement('first_name', htmlspecialchars($data['first_name'] ?? '', ENT_XML1, 'UTF-8')));
        $contact->appendChild($dom->createElement('last_name', htmlspecialchars($data['last_name'] ?? '', ENT_XML1, 'UTF-8')));
        $user->appendChild($contact);

        $user->appendChild($dom->createElement('type', !empty($data['is_company']) ? 'company' : 'private'));

        if (!empty($data['is_company'])) {
            $company = $dom->createElement('company');
            $company->appendChild($dom->createElement('name', htmlspecialchars($data['company_name'] ?? '', ENT_XML1, 'UTF-8')));
            $company->appendChild($dom->createElement('vat_number', htmlspecialchars($data['vat_number'] ?? '', ENT_XML1, 'UTF-8')));
            $user->appendChild($company);
        }

        $body->appendChild($user);

        $session = $dom->createElement('session');
        $session->appendChild($dom->createElement('session_id', htmlspecialchars((string) $data['session_id'], ENT_XML1, 'UTF-8')));
        $session->appendChild($dom->createElement('name', htmlspecialchars((string) $data['session_name'], ENT_XML1, 'UTF-8')));
        $body->appendChild($session);

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
