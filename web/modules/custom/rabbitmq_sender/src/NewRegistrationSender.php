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
            throw new \InvalidArgumentException('date_of_birth is required');
        }

        if (!empty($data['is_company']) && empty($data['vat_number'])) {
            throw new \InvalidArgumentException('vat_number is required for companies');
        }

        // ✅ Logging
        \Drupal::logger('rabbitmq_sender')->info('Sending new registration', [
            'user_id' => $data['user_id'],
            'email' => $data['email'],
        ]);

        $xml = $this->buildXml($data);

        try {
            $this->sendWithRetry(function () use ($xml): void {
                $this->resolveClient()->declareQueue(self::QUEUE_NAME);

                $msg = new AMQPMessage($xml, [
                    'delivery_mode' => 2,
                    'content_type' => 'application/xml',
                ]);

                $this->resolveClient()->getChannel()->basic_publish($msg, '', self::QUEUE_NAME);
            });
        } catch (\Throwable $e) {
            // logging gebeurt in RabbitMQClient
            throw $e;
        }
    }

    public function buildXml(array $data): string
    {
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');

        $xml = new \DOMDocument('1.0', 'UTF-8');

        $message = $xml->createElement('message');
        $xml->appendChild($message);

        // Header order per contract: message_id, correlation_id, timestamp, source, type, version
        $header = $xml->createElement('header');
        $header->appendChild($xml->createElement('message_id', $messageId));
        $header->appendChild($xml->createElement('correlation_id', $messageId));
        $header->appendChild($xml->createElement('timestamp', $timestamp));
        $header->appendChild($xml->createElement('source', $this->resolveSource()));
        $header->appendChild($xml->createElement('type', self::MESSAGE_TYPE));
        $header->appendChild($xml->createElement('version', self::MESSAGE_VERSION));
        $message->appendChild($header);

        $body = $xml->createElement('body');

        // session_id is first in body per contract
        if (!empty($data['session_id'])) {
            $body->appendChild($xml->createElement('session_id', (string) $data['session_id']));
        }

        $customer = $xml->createElement('customer');

        // Field order per contract: user_id, email, type, is_company_linked, vat_number, date_of_birth, contact, address, company_id, registration_fee
        $customer->appendChild($xml->createElement('user_id', (string) ($data['user_id'] ?? '')));
        $customer->appendChild($xml->createElement('email', (string) $data['email']));

        if (!empty($data['type'])) {
            $customer->appendChild($xml->createElement('type', (string) $data['type']));
        } elseif (array_key_exists('is_company', $data)) {
            $customer->appendChild($xml->createElement('type', !empty($data['is_company']) ? 'company' : 'private'));
        }

        if (array_key_exists('is_company_linked', $data)) {
            $customer->appendChild($xml->createElement('is_company_linked', !empty($data['is_company_linked']) ? 'true' : 'false'));
        } elseif (array_key_exists('is_company', $data)) {
            $customer->appendChild($xml->createElement('is_company_linked', !empty($data['is_company']) ? 'true' : 'false'));
        }

        if (!empty($data['vat_number'])) {
            $customer->appendChild($xml->createElement('vat_number', (string) $data['vat_number']));
        }

        $customer->appendChild($xml->createElement('date_of_birth', (string) ($data['date_of_birth'] ?? '')));

        $contact = $xml->createElement('contact');
        $contact->appendChild($xml->createElement('first_name', (string) $data['first_name']));
        $contact->appendChild($xml->createElement('last_name', (string) $data['last_name']));
        $customer->appendChild($contact);

        // address as xs:string per contract
        if (!empty($data['address'])) {
            if (is_array($data['address'])) {
                $parts = [];
                $street = trim(($data['address']['street'] ?? '') . ' ' . ($data['address']['number'] ?? ''));
                if ($street !== '') {
                    $parts[] = $street;
                }
                $city = trim(($data['address']['postal_code'] ?? '') . ' ' . ($data['address']['city'] ?? ''));
                if ($city !== '') {
                    $parts[] = $city;
                }
                if (!empty($data['address']['country'])) {
                    $parts[] = strtoupper((string) $data['address']['country']);
                }
                $addressStr = implode(', ', $parts);
            } else {
                $addressStr = (string) $data['address'];
            }
            $customer->appendChild($xml->createElement('address', htmlspecialchars($addressStr, ENT_XML1, 'UTF-8')));
        }

        if (!empty($data['company_id'])) {
            $customer->appendChild($xml->createElement('company_id', (string) $data['company_id']));
        }

        // registration_fee with status=unpaid per contract (replaces paid boolean)
        if (!empty($data['registration_fee']) && is_array($data['registration_fee'])) {
            $registrationFee = $xml->createElement('registration_fee');
            if (!empty($data['registration_fee']['amount'])) {
                $amount = $xml->createElement('amount', (string) $data['registration_fee']['amount']);
                $amount->setAttribute('currency', 'eur');
                $registrationFee->appendChild($amount);
            }
            $registrationFee->appendChild($xml->createElement('status', 'unpaid'));
            $customer->appendChild($registrationFee);
        }

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