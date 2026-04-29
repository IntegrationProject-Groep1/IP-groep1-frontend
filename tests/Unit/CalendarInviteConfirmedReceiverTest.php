<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\CalendarInviteConfirmedReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

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

    // ─── Invalid XML ──────────────────────────────────────────────────────────

    public function test_throws_when_xml_is_completely_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid XML received');

        $this->receiver->processMessageFromXml('not xml');
    }

    public function test_throws_when_xml_is_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->receiver->processMessageFromXml('');
    }

    public function test_throws_when_body_element_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('<body> element is missing');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns="urn:integration:planning:v1">'
            . '<header><type>calendar.invite.confirmed</type></header>'
            . '</message>';

        $this->receiver->processMessageFromXml($xml);
    }

    // ─── Required field validation ────────────────────────────────────────────

    public function test_throws_when_session_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('session_id is required');

        $xml = $this->buildConfirmedXml('', 'orig-uuid-001', 'confirmed');
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_original_message_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('original_message_id is required');

        $xml = $this->buildConfirmedXml('sess-001', '', 'confirmed');
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_status_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('status is required');

        $xml = $this->buildConfirmedXml('sess-001', 'orig-uuid-001', '');
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_status_is_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('status must be "confirmed" or "failed"');

        $xml = $this->buildConfirmedXml('sess-001', 'orig-uuid-001', 'unknown');
        $this->receiver->processMessageFromXml($xml);
    }

    // ─── Successful parsing ───────────────────────────────────────────────────

    public function test_returns_correct_session_id(): void
    {
        $result = $this->receiver->processMessageFromXml(
            $this->buildConfirmedXml('sess-uuid-001', 'orig-uuid-001', 'confirmed')
        );

        $this->assertSame('sess-uuid-001', $result['session_id']);
    }

    public function test_returns_correct_original_message_id(): void
    {
        $result = $this->receiver->processMessageFromXml(
            $this->buildConfirmedXml('sess-001', 'orig-uuid-abc', 'confirmed')
        );

        $this->assertSame('orig-uuid-abc', $result['original_message_id']);
    }

    public function test_returns_confirmed_status(): void
    {
        $result = $this->receiver->processMessageFromXml(
            $this->buildConfirmedXml('sess-001', 'orig-001', 'confirmed')
        );

        $this->assertSame('confirmed', $result['status']);
    }

    public function test_returns_failed_status(): void
    {
        $result = $this->receiver->processMessageFromXml(
            $this->buildConfirmedXml('sess-001', 'orig-001', 'failed')
        );

        $this->assertSame('failed', $result['status']);
    }

    // ─── Namespace handling ───────────────────────────────────────────────────

    public function test_parses_xml_with_planning_namespace(): void
    {
        $result = $this->receiver->processMessageFromXml(
            $this->buildConfirmedXml('sess-ns-001', 'orig-ns-001', 'confirmed')
        );

        $this->assertSame('sess-ns-001', $result['session_id']);
    }

    public function test_parses_xml_without_namespace(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message>'
            . '<header><type>calendar.invite.confirmed</type></header>'
            . '<body>'
            . '<session_id>sess-no-ns</session_id>'
            . '<original_message_id>orig-no-ns</original_message_id>'
            . '<status>confirmed</status>'
            . '</body></message>';

        $result = $this->receiver->processMessageFromXml($xml);

        $this->assertSame('sess-no-ns', $result['session_id']);
        $this->assertSame('confirmed', $result['status']);
    }

    // ─── Return shape ─────────────────────────────────────────────────────────

    public function test_result_contains_all_expected_keys(): void
    {
        $result = $this->receiver->processMessageFromXml(
            $this->buildConfirmedXml('sess-001', 'orig-001', 'confirmed')
        );

        $this->assertArrayHasKey('session_id', $result);
        $this->assertArrayHasKey('original_message_id', $result);
        $this->assertArrayHasKey('status', $result);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function buildConfirmedXml(string $sessionId, string $originalMessageId, string $status): string
    {
        $bodyParts = '';

        if ($sessionId !== '') {
            $bodyParts .= '<session_id>' . htmlspecialchars($sessionId, ENT_XML1) . '</session_id>';
        }
        if ($originalMessageId !== '') {
            $bodyParts .= '<original_message_id>' . htmlspecialchars($originalMessageId, ENT_XML1) . '</original_message_id>';
        }
        if ($status !== '') {
            $bodyParts .= '<status>' . htmlspecialchars($status, ENT_XML1) . '</status>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns="urn:integration:planning:v1">'
            . '<header>'
            . '<message_id>test-msg-id</message_id>'
            . '<timestamp>2026-04-29T10:00:00Z</timestamp>'
            . '<source>planning</source>'
            . '<type>calendar.invite.confirmed</type>'
            . '</header>'
            . '<body>' . $bodyParts . '</body>'
            . '</message>';
    }
}
