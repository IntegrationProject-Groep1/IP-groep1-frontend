<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes CRM-compatible new registration messages to RabbitMQ.
 */
class NewRegistrationSender
{
    use RetryTrait;

    private ?RabbitMQClient $client;
    private const QUEUE_NAME = 'crm.incoming';
    private const DEFAULT_SOURCE = 'frontend';
    private const MESSAGE_TYPE = 'new_registration';
    private const MESSAGE_VERSION = '2.0';

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    public function send(array $data): void
    {
        // Validate required contract fields before building and publishing XML.
        if (empty($data['email'])) {
            throw new \InvalidArgumentException('email is required');
        }
        if (empty($data['user_id'])) {
            throw new \InvalidArgumentException('user_id is required');
        }
        if (empty($data['first_name'])) {
            throw new \InvalidArgumentException('first_name is required');
        }
        if (empty($data['last_name'])) {
            throw new \InvalidArgumentException('last_name is required');
        }
        if (empty($data['date_of_birth'])) {
            throw new \InvalidArgumentException('date_of_birth is required; without it CRM will not synchronize the registration to Kassa.');
        }
        if (!empty($data['is_company']) && empty($data['vat_number'])) {
            throw new \InvalidArgumentException('vat_number is required for companies');
        }

        $xml = $this->buildXml($data);

        try {
            $this->sendWithRetry(function () use ($xml): void {
                // Ensure target queue exists before publishing to the default exchange.
                $this->resolveClient()->declareQueue(self::QUEUE_NAME);
                $msg = new AMQPMessage($xml, [
                    'delivery_mode' => 2,
                    'content_type' => 'application/xml',
                ]);
                $this->resolveClient()->getChannel()->basic_publish($msg, '', self::QUEUE_NAME);
            });
        } catch (\Throwable $e) {
            // Let callers decide logging strategy through Drupal's logger channels.
            throw $e;
        }
    }

    public function buildXml(array $data): string
    {
        if (empty($data['date_of_birth'])) {
            throw new \InvalidArgumentException('date_of_birth is required; without it CRM will not synchronize the registration to Kassa.');
        }

        // Correlation and message IDs are aligned to simplify cross-system tracing.
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = false;

        $message = $xml->createElement('message');
        $xml->appendChild($message);

        $header = $xml->createElement('header');
        $header->appendChild($xml->createElement('message_id', $messageId));
        $header->appendChild($xml->createElement('timestamp', $timestamp));
        $header->appendChild($xml->createElement('source', $this->resolveSource()));
        $header->appendChild($xml->createElement('type', self::MESSAGE_TYPE));
        $header->appendChild($xml->createElement('version', self::MESSAGE_VERSION));
        $header->appendChild($xml->createElement('correlation_id', $messageId));
        $message->appendChild($header);

        $body = $xml->createElement('body');
        $customer = $xml->createElement('customer');
        $customer->appendChild($xml->createElement('identity_uuid', (string) ($data['identity_uuid'] ?? $data['user_id'])));
        $customer->appendChild($xml->createElement('email', (string) $data['email']));

        $customerType = !empty($data['type']) ? $data['type'] : (!empty($data['is_company']) ? 'company' : 'private');
        $customer->appendChild($xml->createElement('type', $customerType));

        $isCompanyLinked = !empty($data['is_company_linked']) || !empty($data['is_company']);
        $customer->appendChild($xml->createElement('is_company_linked', $isCompanyLinked ? 'true' : 'false'));

        if (!empty($data['vat_number'])) {
            $customer->appendChild($xml->createElement('vat_number', (string) $data['vat_number']));
        }

        // date_of_birth is always required by downstream CRM -> Kassa forwarding.
        $customer->appendChild($xml->createElement('date_of_birth', (string) $data['date_of_birth']));

        $contact = $xml->createElement('contact');
        $contact->appendChild($xml->createElement('first_name', (string) $data['first_name']));
        $contact->appendChild($xml->createElement('last_name', (string) $data['last_name']));
        $customer->appendChild($contact);

        if (!empty($data['address'])) {
            $addressStr = is_array($data['address'])
                ? implode(', ', array_filter($data['address']))
                : (string) $data['address'];
            $customer->appendChild($xml->createElement('address', $addressStr));
        }

        if (!empty($data['company_id'])) {
            $customer->appendChild($xml->createElement('company_id', (string) $data['company_id']));
        }

        if (!empty($data['session_id'])) {
            $customer->appendChild($xml->createElement('session_id', (string) $data['session_id']));
        }

        $paymentDue = $xml->createElement('payment_due');
        $amount = $xml->createElement('amount', (string) ($data['payment_due']['amount'] ?? '0.00'));
        $amount->setAttribute('currency', 'eur');
        $paymentDue->appendChild($amount);
        $paymentDue->appendChild($xml->createElement('status', 'unpaid'));
        $customer->appendChild($paymentDue);

        $body->appendChild($customer);

        $message->appendChild($body);

        return $xml->saveXML() ?: '';
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function resolveClient(): RabbitMQClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        // Fall back to environment configuration when no client is injected.
        $this->client = new RabbitMQClient(
            getenv('RABBITMQ_HOST') ?: 'rabbitmq_broker',
            (int) (getenv('RABBITMQ_PORT') ?: '5672'),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASS') ?: 'guest',
            getenv('RABBITMQ_VHOST') ?: '/'
        );

        return $this->client;
    }

    private function resolveSource(): string
    {
        return getenv('RABBITMQ_SOURCE') ?: self::DEFAULT_SOURCE;
    }
}
