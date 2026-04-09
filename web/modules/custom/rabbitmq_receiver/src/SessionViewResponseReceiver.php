<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_receiver;

use Drupal\rabbitmq_sender\RabbitMQClient;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consumes session_view_response messages from Planning.
 *
 * Planning sends this in response to a session_view_request. The response
 * contains a list of sessions which we store in Drupal State API so the
 * registration form can display up-to-date session options.
 *
 * Exchange:    planning.exchange       (topic, durable)
 * Routing key: planning.session.view.response
 * Queue:       frontend.planning.session.view.response
 *
 * The stored sessions are available via:
 *   \Drupal::state()->get('planning.sessions', [])
 */
class SessionViewResponseReceiver
{
    private const EXCHANGE      = 'planning.exchange';
    private const EXCHANGE_TYPE = 'topic';
    private const ROUTING_KEY   = 'planning.session.view.response';
    private const QUEUE         = 'frontend.planning.session.view.response';
    public  const STATE_KEY     = 'planning.sessions';

    private RabbitMQClient $client;

    public function __construct(RabbitMQClient $client)
    {
        $this->client = $client;
    }

    public function listen(): void
    {
        $channel = $this->client->getChannel();

        $channel->exchange_declare(self::EXCHANGE, self::EXCHANGE_TYPE, false, true, false);
        $channel->queue_declare(self::QUEUE, false, true, false, false);
        $channel->queue_bind(self::QUEUE, self::EXCHANGE, self::ROUTING_KEY);

        $channel->basic_consume(
            self::QUEUE,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg): void {
                $this->processMessage($msg);
            }
        );

        echo 'Listening for planning.session.view.response on ' . self::EXCHANGE . ' → ' . self::QUEUE . "\n";

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    /**
     * Parses a session_view_response XML string from Planning.
     * Returns an array of session arrays, each matching SessionCreatedReceiver's shape.
     *
     * @throws \InvalidArgumentException on invalid or incomplete XML.
     */
    public function processMessageFromXml(string $xmlString): array
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            throw new \InvalidArgumentException('Invalid XML received');
        }

        $namespaces = $xml->getNamespaces(true);
        $ns = reset($namespaces) ?: null;

        if ($ns !== null) {
            $xml->registerXPathNamespace('ns', $ns);
            $bodyNodes = $xml->xpath('ns:body') ?: $xml->xpath('body');
        } else {
            $bodyNodes = $xml->xpath('body');
        }

        $body = ($bodyNodes && count($bodyNodes) > 0) ? $bodyNodes[0] : $xml->body ?? null;

        if ($body === null) {
            throw new \InvalidArgumentException('<body> element is missing');
        }

        $status = trim((string) ($body->status ?? ''));
        if ($status === 'not_found') {
            return [];
        }

        if (empty($status)) {
            throw new \InvalidArgumentException('status is required');
        }

        $sessions = [];

        if (isset($body->sessions->session)) {
            foreach ($body->sessions->session as $session) {
                $sessionId = trim((string) ($session->session_id ?? ''));
                if (empty($sessionId)) {
                    // Skip malformed session entries rather than aborting the whole response.
                    continue;
                }

                $sessions[] = [
                    'session_id'        => $sessionId,
                    'title'             => trim((string) ($session->title ?? '')),
                    'start_datetime'    => trim((string) ($session->start_datetime ?? '')),
                    'end_datetime'      => trim((string) ($session->end_datetime ?? '')),
                    'location'          => trim((string) ($session->location ?? '')),
                    'session_type'      => trim((string) ($session->session_type ?? '')),
                    'status'            => trim((string) ($session->status ?? '')),
                    'max_attendees'     => (int) ($session->max_attendees ?? 0),
                    'current_attendees' => (int) ($session->current_attendees ?? 0),
                ];
            }
        }

        return $sessions;
    }

    private function processMessage(AMQPMessage $msg): void
    {
        try {
            $sessions = $this->processMessageFromXml($msg->body);

            // Store in Drupal State so RegistrationForm can use live planning data.
            if (function_exists('drupal_get_profile')) {
                \Drupal::state()->set(self::STATE_KEY, $sessions);
            }

            echo sprintf("session.view.response received: %d session(s) stored.\n", count($sessions));

            $msg->ack();
        } catch (\Exception $e) {
            error_log('SessionViewResponseReceiver error: ' . $e->getMessage());
            $msg->nack(false);
        }
    }
}
