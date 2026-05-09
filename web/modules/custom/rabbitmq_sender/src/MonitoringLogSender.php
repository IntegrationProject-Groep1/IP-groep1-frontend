<?php
declare(strict_types=1);

namespace Drupal\rabbitmq_sender;

/**
 * Publishes log events to the Monitoring team (v2.0 contract, section 3.5).
 */
class MonitoringLogSender
{
    use RetryTrait;
    use XmlValidationTrait;

    private ?RabbitMQClient $client;

    private const QUEUE_NAME = 'logs';
    private const SOURCE     = 'frontend';
    private const TYPE       = 'log';
    private const VERSION    = '2.0';
    private const XSD_PATH   = __DIR__ . '/../../../../../xsd/schema_log.xsd';

    public function __construct(?RabbitMQClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * Sends a log message to the Monitoring team.
     *
     * @param string $level   'info', 'warning', or 'error'
     * @param string $action  The category of the log (e.g., 'registration', 'system_error')
     * @param string $message The descriptive message
     */
    public function send(string $level, string $action, string $messageText): void
    {
        $xml = $this->buildXml($level, $action, $messageText);
        
        try {
            $this->validateXml($xml, self::XSD_PATH);
        } catch (\Throwable $e) {
            if (class_exists('\Drupal')) {
                \Drupal::logger('rabbitmq_sender')->error('Failed to validate Monitoring Log XML: ' . $e->getMessage());
            }
            return;
        }

        $this->sendWithRetry(function () use ($xml): void {
            $this->resolveClient()->declareQueue(self::QUEUE_NAME);
            $this->resolveClient()->publishToQueue(self::QUEUE_NAME, $xml);
        });
    }

    private function buildXml(string $level, string $action, string $messageText): string
    {
        $messageId = $this->generateUuidV4();
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

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
        $body->appendChild($dom->createElement('level', $level));
        $body->appendChild($dom->createElement('action', $action));
        $body->appendChild($dom->createElement('message', htmlspecialchars($messageText, ENT_XML1, 'UTF-8')));
        $message->appendChild($body);

        return $dom->saveXML() ?: '';
    }

    private function resolveClient(): RabbitMQClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = new RabbitMQClient();
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
