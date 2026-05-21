<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_sender\XmlValidationTrait;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * @deprecated contract v2.3 §19.2 — Planning is als service weggevallen.
 * Deze RPC bestaat niet meer. Sessiedata komt nu via push-berichten
 * session_created / session_updated / session_deleted.
 * Deze receiver mag niet meer worden gebruikt.
 */
class SessionViewResponseReceiver
{
    use XmlValidationTrait;
    use ReceiverLogTrait;

    private const EXCHANGE      = 'planning.exchange';
    private const EXCHANGE_TYPE = 'topic';
    private const ROUTING_KEY   = 'planning.to.frontend.session.view.response';
    private const QUEUE         = 'frontend.planning.session.view.response';
    private const DLQ           = 'frontend.planning.session.view.response.dlq';
    private const DLX           = 'frontend.planning.dlx';
    private const XSD_PATH      = __DIR__ . '/../../../../../xsd/session_view_response.xsd';

    public function __construct(private readonly RabbitMQClient $client) {}

    /**
     * @return list<array<string, mixed>>
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

        $requestMessageId  = trim((string) $body->request_message_id);
        $requestedSessionId = isset($body->requested_session_id) ? trim((string) $body->requested_session_id) : '';
        $sessionCount       = (int) (string) $body->session_count;

        $status = trim((string) $body->status);
        if ($status === '') {
            throw new \InvalidArgumentException('status is required');
        }

        \Drupal::logger('rabbitmq_receiver')->info(
            'session_view_response: request_message_id=@req, requested_session=@sid, status=@status, session_count=@count.',
            [
                '@req'    => $requestMessageId ?: 'n/a',
                '@sid'    => $requestedSessionId ?: 'all',
                '@status' => $status,
                '@count'  => $sessionCount,
            ]
        );

        if ($status === 'not_found') {
            return [];
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

        if ($sessionCount !== count($sessions)) {
            \Drupal::logger('rabbitmq_receiver')->warning(
                'session_view_response: session_count=@declared but parsed @actual sessions (request_message_id=@req).',
                ['@declared' => $sessionCount, '@actual' => count($sessions), '@req' => $requestMessageId ?: 'n/a']
            );
        }

        return $sessions;
    }

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
                    $sessions = $this->processMessageFromXml($msg->body);
                    \Drupal::state()->set('planning.sessions', $sessions);
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
            $sessions = $this->processMessageFromXml($msg->body);
            \Drupal::state()->set('planning.sessions', $sessions);
            $msg->ack();
        } catch (\Throwable $e) {
            $this->logReceiverError($e, self::QUEUE, $msg->body);
            $msg->nack(false, false);
        }

        return true;
    }

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
}
