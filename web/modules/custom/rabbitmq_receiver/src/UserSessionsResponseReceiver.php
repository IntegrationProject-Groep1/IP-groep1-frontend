<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_sender\XmlValidationTrait;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Receives user_sessions_response messages from the Planning system.
 *
 * Returns the sessions a specific visitor is enrolled in, identified by
 * identity_uuid and correlation_id.
 *
 * Queue topology:
 *   Exchange:    planning.exchange      (topic, durable)
 *   Routing key: planning.to.frontend.user_sessions_response
 *   Queue:       frontend.planning.user_sessions_response  (durable, with DLQ)
 *   DLX:         frontend.planning.dlx
 *   DLQ:         frontend.planning.user_sessions_response.dlq
 */
class UserSessionsResponseReceiver
{
    use XmlValidationTrait;
    use ReceiverLogTrait;

    private const EXCHANGE      = 'planning.exchange';
    private const EXCHANGE_TYPE = 'topic';
    private const ROUTING_KEY   = 'planning.to.frontend.user_sessions_response';
    private const QUEUE         = 'frontend.planning.user_sessions_response';
    private const DLQ           = 'frontend.planning.user_sessions_response.dlq';
    private const DLX           = 'frontend.planning.dlx';
    private const XSD_PATH      = __DIR__ . '/../../../../../xsd/user_sessions_response.xsd';

    public function __construct(private readonly RabbitMQClient $client) {}

    /**
     * Parse an incoming user_sessions_response XML message.
     *
     * Returns an array with:
     *   - identity_uuid    (string)
     *   - correlation_id   (string)
     *   - status           ('ok'|'not_found')
     *   - session_count    (int)
     *   - sessions         (list<array<string,mixed>>)
     *
     * @throws \InvalidArgumentException on invalid XML or missing required fields.
     */
    public function processMessageFromXml(string $xmlString): array
    {
        $this->validateXml($xmlString, self::XSD_PATH);
        $this->logReceiverSuccess(
            $this->extractXmlValue($xmlString, 'type'),
            $this->extractXmlValue($xmlString, 'source')
        );

        $xml  = $this->parseXml($xmlString);
        $body = $xml->body;

        $identityUuid  = trim((string) $body->identity_uuid);
        $status        = trim((string) $body->status);
        $correlationId = trim((string) $xml->header->correlation_id);

        if ($identityUuid === '' || $status === '') {
            throw new \InvalidArgumentException('identity_uuid and status are required in user_sessions_response');
        }

        if ($status === 'not_found') {
            return [
                'identity_uuid'  => $identityUuid,
                'correlation_id' => $correlationId,
                'status'         => 'not_found',
                'session_count'  => 0,
                'sessions'       => [],
            ];
        }

        $sessions = [];
        if (isset($body->sessions->session)) {
            foreach ($body->sessions->session as $session) {
                $sessionId = trim((string) $session->session_id);
                if ($sessionId === '') {
                    continue;
                }

                $sessions[] = [
                    'session_id'        => $sessionId,
                    'title'             => trim((string) $session->title),
                    'start_datetime'    => trim((string) $session->start_datetime),
                    'end_datetime'      => trim((string) $session->end_datetime),
                    'location'          => trim((string) $session->location),
                    'session_type'      => trim((string) $session->session_type),
                    'status'            => trim((string) $session->status),
                    'max_attendees'     => (int) (string) $session->max_attendees,
                    'current_attendees' => (int) (string) $session->current_attendees,
                    'price'             => isset($session->price) ? (float) (string) $session->price : null,
                    'speaker'           => isset($session->speaker) ? [
                        'identity_uuid' => trim((string) ($session->speaker->identity_uuid ?? '')),
                        'first_name'    => trim((string) ($session->speaker->contact->first_name ?? '')),
                        'last_name'     => trim((string) ($session->speaker->contact->last_name ?? '')),
                        'organisation'  => trim((string) ($session->speaker->organisation ?? '')),
                        'email'         => trim((string) ($session->speaker->email ?? '')),
                    ] : null,
                ];
            }
        }

        return [
            'identity_uuid'  => $identityUuid,
            'correlation_id' => $correlationId,
            'status'         => $status,
            'session_count'  => (int) (string) $body->session_count,
            'sessions'       => $sessions,
        ];
    }

    /**
     * Poll the user_sessions_response queue once (non-blocking).
     *
     * If a message is waiting, processes it and returns the parsed result.
     * Returns null when the queue is empty.
     *
     * @return array<string,mixed>|null
     */
    public function pollOnce(): ?array
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
            return null;
        }

        try {
            $result = $this->processMessageFromXml($msg->body);
            $msg->ack();
            return $result;
        } catch (\Throwable $e) {
            $this->logReceiverError($e, self::QUEUE, $msg->body);
            $msg->nack(false, false);
            return null;
        }
    }

    /**
     * Subscribe to the user_sessions_response queue indefinitely.
     *
     * Stores session data per user in Drupal's user.data service.
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
                    $result = $this->processMessageFromXml($msg->body);
                    $this->storeResult($result);
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
     * Store the parsed result in Drupal state keyed by identity_uuid.
     * The controller can then read it from state.
     */
    private function storeResult(array $result): void
    {
        $identityUuid = $result['identity_uuid'] ?? '';
        if ($identityUuid === '') {
            return;
        }

        // Store keyed by identity_uuid so MySessionsController can look it up.
        $key = 'user_sessions.' . $identityUuid;
        \Drupal::state()->set($key, [
            'sessions'       => $result['sessions'],
            'status'         => $result['status'],
            'correlation_id' => $result['correlation_id'],
            'fetched_at'     => time(),
        ]);
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
        $xml     = simplexml_load_string($cleaned ?? $xmlString);

        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            throw new \InvalidArgumentException(
                'Invalid XML: ' . implode('; ', array_map(fn($e) => trim($e->message), $errors))
            );
        }

        return $xml;
    }
}
