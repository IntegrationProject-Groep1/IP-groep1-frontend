<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\SessionDeletedReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for SessionDeletedReceiver — Planning XSD session_deleted contract.
 */
class SessionDeletedReceiverTest extends TestCase
{
    private SessionDeletedReceiver $receiver;

    protected function setUp(): void
    {
        $stub = $this->createStub(RabbitMQClient::class);
        $this->receiver = new SessionDeletedReceiver($stub);
    }

    // ─── Invalid XML ──────────────────────────────────────────────────────────

    public function test_throws_when_xml_is_completely_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid XML received');
        $this->receiver->processMessageFromXml('not xml at all');
    }

    public function test_throws_when_xml_is_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->receiver->processMessageFromXml('');
    }

    public function test_throws_when_xml_is_unclosed_tag(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->receiver->processMessageFromXml('<message><body><session_id>abc</session_id>');
    }

    // ─── Missing required fields ──────────────────────────────────────────────

    public function test_throws_when_session_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('session_id is required');
        $this->receiver->processMessageFromXml($this->buildXml([]));
    }

    public function test_throws_when_session_id_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('session_id is required');
        $this->receiver->processMessageFromXml($this->buildXml(['session_id' => '   ']));
    }

    // ─── Successful parsing ───────────────────────────────────────────────────

    public function test_valid_xml_returns_array(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml(['session_id' => 'sess-001']));
        $this->assertIsArray($result);
    }

    public function test_returns_correct_session_id(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml(['session_id' => 'sess-del-001']));
        $this->assertSame('sess-del-001', $result['session_id']);
    }

    public function test_returns_reason_when_provided(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml([
            'session_id' => 'sess-001',
            'reason'     => 'cancelled',
        ]));
        $this->assertSame('cancelled', $result['reason']);
    }

    public function test_returns_deleted_by_when_provided(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml([
            'session_id' => 'sess-001',
            'deleted_by' => 'planning-admin',
        ]));
        $this->assertSame('planning-admin', $result['deleted_by']);
    }

    // ─── Optional fields ─────────────────────────────────────────────────────

    public function test_reason_defaults_to_empty_string_when_absent(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml(['session_id' => 'sess-001']));
        $this->assertSame('', $result['reason']);
    }

    public function test_deleted_by_defaults_to_empty_string_when_absent(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml(['session_id' => 'sess-001']));
        $this->assertSame('', $result['deleted_by']);
    }

    // ─── Namespace handling ───────────────────────────────────────────────────

    public function test_parses_xml_with_planning_namespace(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns="urn:integration:planning:v1">'
            . '<header><type>session_deleted</type></header>'
            . '<body>'
            . '<session_id>ns-sess-del-001</session_id>'
            . '<reason>cancelled</reason>'
            . '</body></message>';

        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertSame('ns-sess-del-001', $result['session_id']);
        $this->assertSame('cancelled', $result['reason']);
    }

    public function test_parses_xml_without_namespace(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message><body>'
            . '<session_id>no-ns-del-001</session_id>'
            . '</body></message>';

        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertSame('no-ns-del-001', $result['session_id']);
    }

    // ─── Return shape ─────────────────────────────────────────────────────────

    public function test_returned_array_contains_all_expected_keys(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml(['session_id' => 'sess-001']));
        foreach (['session_id', 'reason', 'deleted_by'] as $key) {
            $this->assertArrayHasKey($key, $result, "Key '{$key}' must be present");
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function buildXml(array $fields): string
    {
        $body = '';
        foreach ($fields as $key => $value) {
            $body .= "<{$key}>" . htmlspecialchars((string) $value, ENT_XML1, 'UTF-8') . "</{$key}>";
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns="urn:integration:planning:v1">'
            . '<header><type>session_deleted</type></header>'
            . "<body>{$body}</body>"
            . '</message>';
    }
}
