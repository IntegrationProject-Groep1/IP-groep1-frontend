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
    use XmlValidationTrait;

    private ?RabbitMQClient $client;
    private const QUEUE_NAME = 'crm.incoming';
    private const DEFAULT_SOURCE = 'frontend';
    private const MESSAGE_TYPE = 'new_registration';
    private const MESSAGE_VERSION = '2.0';
    private const XSD_PATH = __DIR__ . '/../../../../../xsd/new_registration.xsd';

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
        $this->validateXml($xml, self::XSD_PATH);

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
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = false;

        $message = $xml->createElement('message');
        $message->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->appendChild($message);

        // Header order per contract §5.1: message_id, timestamp, source, type, version, correlation_id
        $header = $xml->createElement('header');
        $header->appendChild($xml->createElement('message_id', $messageId));
        $header->appendChild($xml->createElement('timestamp', $timestamp));
        $header->appendChild($xml->createElement('source', $this->resolveSource()));
        $header->appendChild($xml->createElement('type', self::MESSAGE_TYPE));
        $header->appendChild($xml->createElement('version', self::MESSAGE_VERSION));
        $header->appendChild($xml->createElement('correlation_id', $messageId));
        $message->appendChild($header);

        $body     = $xml->createElement('body');
        $customer = $xml->createElement('customer');

        // identity_uuid: master UUID from Identity Service (falls back to Drupal user_id)
        $identityUuid = (string) ($data['identity_uuid'] ?? $data['user_id'] ?? '');
        $customer->appendChild($xml->createElement('identity_uuid', htmlspecialchars($identityUuid, ENT_XML1, 'UTF-8')));
        $customer->appendChild($xml->createElement('email', htmlspecialchars((string) $data['email'], ENT_XML1, 'UTF-8')));

        // type: private or company
        $type = !empty($data['is_company']) ? 'company' : 'private';
        if (!empty($data['type'])) {
            $type = (string) $data['type'];
        }
        $customer->appendChild($xml->createElement('type', $type));

        // is_company_linked: xs:boolean → true/false
        $isCompanyLinked = (!empty($data['is_company_linked']) || !empty($data['is_company'])) ? 'true' : 'false';
        $customer->appendChild($xml->createElement('is_company_linked', $isCompanyLinked));

        if (!empty($data['vat_number'])) {
            $customer->appendChild($xml->createElement('vat_number', htmlspecialchars((string) $data['vat_number'], ENT_XML1, 'UTF-8')));
        }

        $customer->appendChild($xml->createElement('date_of_birth', htmlspecialchars((string) ($data['date_of_birth'] ?? ''), ENT_XML1, 'UTF-8')));

        $contact = $xml->createElement('contact');
        $contact->appendChild($xml->createElement('first_name', htmlspecialchars((string) ($data['first_name'] ?? ''), ENT_XML1, 'UTF-8')));
        $contact->appendChild($xml->createElement('last_name', htmlspecialchars((string) ($data['last_name'] ?? ''), ENT_XML1, 'UTF-8')));
        $customer->appendChild($contact);

        // address is required per contract; send empty string if not provided
        $addressStr = '';
        if (!empty($data['address'])) {
            if (is_array($data['address'])) {
                $parts  = [];
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
        }
        $customer->appendChild($xml->createElement('address', htmlspecialchars($addressStr, ENT_XML1, 'UTF-8')));

        if (!empty($data['company_id'])) {
            $customer->appendChild($xml->createElement('company_id', htmlspecialchars((string) $data['company_id'], ENT_XML1, 'UTF-8')));
        }

        // session_id inside <customer> per contract §5.1
        $customer->appendChild($xml->createElement('session_id', htmlspecialchars((string) ($data['session_id'] ?? ''), ENT_XML1, 'UTF-8')));

        // payment_due is required per contract; always send with amount 0.00 eur / unpaid
        $paymentDue = $xml->createElement('payment_due');
        $amountValue = '0.00';
        if (!empty($data['registration_fee']['amount'])) {
            $amountValue = number_format((float) $data['registration_fee']['amount'], 2, '.', '');
        }
        $amountEl = $xml->createElement('amount', $amountValue);
        $amountEl->setAttribute('currency', 'eur');
        $paymentDue->appendChild($amountEl);
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