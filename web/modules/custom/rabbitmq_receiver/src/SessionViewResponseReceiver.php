<?php

declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Receives session_view_response messages from the Planning system.
 *
 * On success, stores the session list in Drupal state at key 'planning.sessions'.
 */
class SessionViewResponseReceiver
{
    private const EXCHANGE      = 'planning.exchange';
    private const EXCHANGE_TYPE = 'topic';
    private const ROUTING_KEY   = 'planning.to.frontend.session.view.response';
    private const QUEUE         = 'frontend.planning.session.view.response';
    private const DLQ           = 'frontend.planning.session.view.response.dlq';
    private const DLX           = 'frontend.planning.dlx';

    public function __construct(private readonly RabbitMQClient $client) {}

    /**
     * Parse an incoming session_view_response XML message.
     *
     * Returns an array of session arrays, or an empty array when status is 'not_found'.
     * Sessions without a session_id are silently skipped.
     *
     * @return list<array<string, mixed>>
     * @throws \InvalidArgumentException
     */
    public function processMessageFromXml(string $xmlString): array
    {
        $xml = $this->parseXml($xmlString);
        $body = $xml->body;

        $status = trim((string) $body->status);
        if ($status === '') {
            throw new \InvalidArgumentException('status is required');
        }

        if ($status === 'not_found') {
            return [];
        }

        $sessions = [];

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
            ];
        }

        return $sessions;
    }

    /**
     * Subscribe to the session_view_response queue with DLQ support.
     *
     * Stores parsed sessions in Drupal state under 'planning.sessions'.
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
                    $sessions = $this->processMessageFromXml($msg->body);
                    \Drupal::state()->set('planning.sessions', $sessions);
                    $msg->ack();
                } catch (\Throwable $e) {
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
}
