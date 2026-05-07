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
        
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><header>'
            . '<message_id>550e8400-e29b-41d4-a716-446655440001</message_id>'
            . '<timestamp>2026-04-29T10:00:00Z</timestamp>'
            . '<source>planning</source>'
            . '<type>calendar_invite_confirmed</type>'
            . '<version>2.0</version>'
            . '</header></message>';

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_session_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('session_id is required');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><header>'
            . '<message_id>550e8400-e29b-41d4-a716-446655440001</message_id>'
            . '<timestamp>2026-04-29T10:00:00Z</timestamp>'
            . '<source>planning</source>'
            . '<type>calendar_invite_confirmed</type>'
            . '<version>2.0</version>'
            . '</header><body>'
            . '<original_message_id>orig-uuid-001</original_message_id>'
            . '<status>confirmed</status>'
            . '</body></message>';

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_original_message_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('original_message_id is required');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><header>'
            . '<message_id>550e8400-e29b-41d4-a716-446655440001</message_id>'
            . '<timestamp>2026-04-29T10:00:00Z</timestamp>'
            . '<source>planning</source>'
            . '<type>calendar_invite_confirmed</type>'
            . '<version>2.0</version>'
            . '</header><body>'
            . '<session_id>sess-001</session_id>'
            . '<status>confirmed</status>'
            . '</body></message>';

        $this->receiver->processMessageFromXml($xml);
    }

    public function test_throws_when_status_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('status is required');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><header>'
            . '<message_id>550e8400-e29b-41d4-a716-446655440001</message_id>'
            . '<timestamp>2026-04-29T10:00:00Z</timestamp>'
            . '<source>planning</source>'
            . '<type>calendar_invite_confirmed</type>'
            . '<version>2.0</version>'
            . '</header><body>'
            . '<session_id>sess-001</session_id>'
            . '<original_message_id>orig-uuid-001</original_message_id>'
            . '</body></message>';

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
        $this->assertArrayHasKey('ics_url', $result);
    }

    public function test_returns_ics_url_when_present(): void
    {
        $xml = $this->buildConfirmedXmlWithIcsUrl('sess-001', 'orig-001', 'confirmed', 'http://example.com/ical/user-uuid-123?token=abc');

        $result = $this->receiver->processMessageFromXml($xml);

        $this->assertSame('http://example.com/ical/user-uuid-123?token=abc', $result['ics_url']);
    }

    public function test_ics_url_is_empty_string_when_not_present(): void
    {
        $result = $this->receiver->processMessageFromXml(
            $this->buildConfirmedXml('sess-001', 'orig-001', 'confirmed')
        );

        $this->assertSame('', $result['ics_url']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function buildConfirmedXmlWithIcsUrl(string $sessionId, string $originalMessageId, string $status, string $icsUrl): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><header>'
            . '<message_id>550e8400-e29b-41d4-a716-446655440001</message_id>'
            . '<timestamp>2026-04-29T10:00:00Z</timestamp>'
            . '<source>planning</source>'
            . '<type>calendar_invite_confirmed</type>'
            . '<version>2.0</version>'
            . '</header>'
            . '<body>'
            . '<session_id>' . htmlspecialchars($sessionId, ENT_XML1) . '</session_id>'
            . '<original_message_id>' . htmlspecialchars($originalMessageId, ENT_XML1) . '</original_message_id>'
            . '<status>' . htmlspecialchars($status, ENT_XML1) . '</status>'
            . '<ics_url>' . htmlspecialchars($icsUrl, ENT_XML1) . '</ics_url>'
            . '</body>'
            . '</message>';
    }

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
            . '<message xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><header>'
            . '<message_id>550e8400-e29b-41d4-a716-446655440001</message_id>'
            . '<timestamp>2026-04-29T10:00:00Z</timestamp>'
            . '<source>planning</source>'
            . '<type>calendar_invite_confirmed</type>'
            . '<version>2.0</version>'
            . '</header>'
            . '<body>' . $bodyParts . '</body>'
            . '</message>';
    }
}
