<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_sender\XmlValidationTrait;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Receives session_updated messages from the Planning system.
 */
class SessionUpdateReceiver
{
    use XmlValidationTrait;
    use ReceiverLogTrait;

    private const EXCHANGE      = 'planning.exchange';
    private const EXCHANGE_TYPE = 'topic';
    private const ROUTING_KEY   = 'planning.to.frontend.session.updated';
    private const QUEUE         = 'frontend.planning.session.updated';
    private const DLQ           = 'frontend.planning.session.updated.dlq';
    private const DLX           = 'frontend.planning.dlx';
    private const XSD_PATH      = __DIR__ . '/../../../../../xsd/session_updated.xsd';

    public function __construct(private readonly RabbitMQClient $client) {}

    /**
     * Parse and validate an incoming session_updated XML message.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public function processMessageFromXml(string $xmlString): array
    {
        $this->validateXml($xmlString, self::XSD_PATH);
        $this->logReceiverSuccess(
            $this->extractXmlValue($xmlString, 'type'),
            $this->extractXmlValue($xmlString, 'source')
        );
        
        $xml = $this->parseXml($xmlString);
        $body = $xml->body;

        $sessionId = trim((string) $body->session_id);
        if ($sessionId === '') {
            throw new \InvalidArgumentException('session_id is required');
        }

        $title = trim((string) $body->title);
        if ($title === '') {
            throw new \InvalidArgumentException('title is required');
        }

        $startDatetime = trim((string) $body->start_datetime);
        if ($startDatetime === '') {
            throw new \InvalidArgumentException('start_datetime is required');
        }

        $endDatetime = trim((string) $body->end_datetime);
        if ($endDatetime === '') {
            throw new \InvalidArgumentException('end_datetime is required');
        }

        return [
            'session_id'        => $sessionId,
            'title'             => $title,
            'start_datetime'    => $startDatetime,
            'end_datetime'      => $endDatetime,
            'location'          => trim((string) $body->location),
            'session_type'      => trim((string) $body->session_type),
            'status'            => trim((string) $body->status),
            'max_attendees'     => (int) (string) $body->max_attendees,
            'current_attendees' => (int) (string) $body->current_attendees,
            'change_reason'     => trim((string) ($body->change_reason ?? '')),
            'speaker'           => isset($body->speaker) ? [
                'identity_uuid' => trim((string) ($body->speaker->identity_uuid ?? '')),
                'first_name'    => trim((string) ($body->speaker->contact->first_name ?? '')),
                'last_name'     => trim((string) ($body->speaker->contact->last_name ?? '')),
                'organisation'  => trim((string) ($body->speaker->organisation ?? '')),
                'email'         => trim((string) ($body->speaker->email ?? '')),
            ] : null,
        ];
    }

    /**
     * Subscribe to the session_updated queue with DLQ support.
     */
    public function listen(): void
    {
        $channel = $this->client->getChannel();

        $args = new AMQPTable([
            'x-dead-letter-exchange'    => self::DLX,
            'x-dead-letter-routing-key' => self::DLQ,
        ]);

        $channel->exchange_declare(self::EXCHANGE, self::EXCHANGE_TYPE, false, true, false);
        $channel->queue_declare(self::QUEUE, false, true, false, false, false, $args);
        $channel->queue_bind(self::QUEUE, self::EXCHANGE, self::ROUTING_KEY);

        $channel->basic_consume(
            self::QUEUE,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg): void {
                try {
                    $session = $this->processMessageFromXml($msg->body);
                    $this->upsertSessionInState($session);
                    $msg->ack();
                } catch (\Throwable $e) {
                    $this->logReceiverError($e, self::QUEUE, $msg->body);
                    $msg->nack(false, false);
                }
            }
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    /**
     * Parse an XML string, stripping the default namespace for uniform access.
     *
     * @throws \InvalidArgumentException on invalid XML
     */
    private function parseXml(string $xmlString): \SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $cleaned = preg_replace('/ xmlns="[^"]*"/', '', $xmlString);
        $xml = simplexml_load_string($cleaned);
        libxml_clear_errors();

        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        return $xml;
    }

    /**
     * Poll the session_updated queue once (non-blocking) and process any waiting message.
     *
     * Upserts the updated session into planning.sessions state so the enrollment
     * form reflects the latest session data after cron runs.
     * Returns true when a message was processed, false when the queue was empty.
     */
    public function pollOnce(): bool
    {
        $channel = $this->client->getChannel();

        $args = new AMQPTable([
            'x-dead-letter-exchange'    => self::DLX,
            'x-dead-letter-routing-key' => self::DLQ,
        ]);

        $channel->exchange_declare(self::EXCHANGE, self::EXCHANGE_TYPE, false, true, false);
        $channel->queue_declare(self::QUEUE, false, true, false, false, false, $args);
        $channel->queue_bind(self::QUEUE, self::EXCHANGE, self::ROUTING_KEY);

        $msg = $channel->basic_get(self::QUEUE);

        if ($msg === null) {
            return false;
        }

        try {
            $session = $this->processMessageFromXml($msg->body);
            $this->upsertSessionInState($session);
            $msg->ack();
        } catch (\Throwable $e) {
            $this->logReceiverError($e, self::QUEUE, $msg->body);
            $msg->nack(false, false);
        }

        return true;
    }

    /**
     * Upserts a session into the planning.sessions Drupal state array.
     * Replaces an existing entry with the same session_id, or appends a new one.
     */
    private function upsertSessionInState(array $session): void
    {
        $sessions = \Drupal::state()->get('planning.sessions', []);
        $found = false;
        foreach ($sessions as &$existing) {
            if ($existing['session_id'] === $session['session_id']) {
                $existing = $session;
                $found = true;
                break;
            }
        }
        unset($existing);
        if (!$found) {
            $sessions[] = $session;
        }
        \Drupal::state()->set('planning.sessions', $sessions);
    }
}
