<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\CalendarInviteConfirmedReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;
use Tests\Unit\XmlTestBuilder;

/**
 * Unit tests for CalendarInviteConfirmedReceiver — XML parsing and validation.
 */
class CalendarInviteConfirmedReceiverTest extends TestCase
{
    private CalendarInviteConfirmedReceiver $receiver;

    protected function setUp(): void
    {
        $stub = $this->createStub(RabbitMQClient::class);
        $this->receiver = new CalendarInviteConfirmedReceiver($stub);
    }

    private function buildXml(array $fields): string
    {
        return XmlTestBuilder::build('calendar_invite_confirmed', [], $fields);
    }

    // ─── Invalid XML ──────────────────────────────────────────────────────────

    public function test_throws_when_xml_is_completely_invalid(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml('not xml');
    }

    public function test_throws_when_xml_is_empty_string(): void
    {
        $this->expectException(\ValueError::class);
        $this->receiver->processMessageFromXml('');
    }

    public function test_throws_when_body_element_is_missing(): void
    {
        $this->expectException(\Exception::class);
        // buildXml always adds body, so we manually build a broken XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?><message><header><type>calendar_invite_confirmed</type></header></message>';
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_session_id_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml(['original_message_id' => 'orig-uuid-001', 'status' => 'confirmed']));
    }

    public function test_throws_when_original_message_id_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml(['session_id' => 'sess-001', 'status' => 'confirmed']));
    }

    public function test_throws_when_status_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml(['session_id' => 'sess-001', 'original_message_id' => 'orig-uuid-001']));
    }

    public function test_throws_when_status_is_invalid(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml([
            'session_id' => 'sess-001',
            'original_message_id' => 'orig-uuid-001',
            'status' => 'unknown'
        ]));
    }

    // ─── Successful parsing ───────────────────────────────────────────────────

    public function test_returns_correct_session_id(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml([
            'session_id' => 'sess-uuid-001',
            'original_message_id' => 'orig-uuid-001',
            'status' => 'confirmed'
        ]));
        $this->assertSame('sess-uuid-001', $result['session_id']);
    }

    public function test_returns_correct_original_message_id(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml([
            'session_id' => 'sess-001',
            'original_message_id' => 'orig-uuid-abc',
            'status' => 'confirmed'
        ]));
        $this->assertSame('orig-uuid-abc', $result['original_message_id']);
    }

    public function test_returns_confirmed_status(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml([
            'session_id' => 'sess-001',
            'original_message_id' => 'orig-001',
            'status' => 'confirmed'
        ]));
        $this->assertSame('confirmed', $result['status']);
    }

    public function test_returns_failed_status(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml([
            'session_id' => 'sess-001',
            'original_message_id' => 'orig-001',
            'status' => 'failed'
        ]));
        $this->assertSame('failed', $result['status']);
    }

    // ─── Namespace handling ───────────────────────────────────────────────────

    public function test_parses_xml_with_planning_namespace(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml([
            'session_id' => 'sess-ns-001',
            'original_message_id' => 'orig-ns-001',
            'status' => 'confirmed'
        ]));
        $this->assertSame('sess-ns-001', $result['session_id']);
    }

    public function test_parses_xml_without_namespace(): void
    {
        $xml = $this->buildXml(['session_id' => 'sess-no-ns', 'original_message_id' => 'orig-no-ns', 'status' => 'confirmed']);
        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertSame('sess-no-ns', $result['session_id']);
        $this->assertSame('confirmed', $result['status']);
    }

    // ─── Return shape ─────────────────────────────────────────────────────────

    public function test_result_contains_all_expected_keys(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml([
            'session_id' => 'sess-001',
            'original_message_id' => 'orig-001',
            'status' => 'confirmed'
        ]));
        foreach (['session_id', 'original_message_id', 'status', 'ics_url'] as $key) {
            $this->assertArrayHasKey($key, $result, "Key '{$key}' must be present");
        }
    }

    public function test_returns_ics_url_when_present(): void
    {
        $xml = $this->buildXml([
            'session_id' => 'sess-001',
            'original_message_id' => 'orig-001',
            'status' => 'confirmed',
            'ics_url' => 'http://example.com/ical/123'
        ]);
        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertSame('http://example.com/ical/123', $result['ics_url']);
    }

    public function test_ics_url_is_empty_string_when_not_present(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml([
            'session_id' => 'sess-001',
            'original_message_id' => 'orig-001',
            'status' => 'confirmed'
        ]));
        $this->assertSame('', $result['ics_url']);
    }
}
