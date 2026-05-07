<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\SessionViewResponseReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for SessionViewResponseReceiver — Planning XSD session_view_response contract.
 */
class SessionViewResponseReceiverTest extends TestCase
{
    private SessionViewResponseReceiver $receiver;

    protected function setUp(): void
    {
        $stub = $this->createStub(RabbitMQClient::class);
        $this->receiver = new SessionViewResponseReceiver($stub);
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

    public function test_throws_when_status_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('status is required');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns="urn:integration:planning:v1">'
            . '<body><sessions></sessions></body></message>';
        $this->receiver->processMessageFromXml($xml);
    }

    // ─── not_found response ───────────────────────────────────────────────────

    public function test_returns_empty_array_when_status_is_not_found(): void
    {
        $xml = $this->buildResponseXml('not_found', []);
        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertSame([], $result);
    }

    // ─── Successful parsing ───────────────────────────────────────────────────

    public function test_returns_empty_array_when_no_sessions(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildResponseXml('ok', []));
        $this->assertSame([], $result);
    }

    public function test_returns_correct_number_of_sessions(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildResponseXml('ok', [
            $this->sessionData('sess-001', 'Keynote'),
            $this->sessionData('sess-002', 'Workshop'),
        ]));
        $this->assertCount(2, $result);
    }

    public function test_returns_correct_session_id(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildResponseXml('ok', [
            $this->sessionData('sess-uuid-001', 'Keynote: AI'),
        ]));
        $this->assertSame('sess-uuid-001', $result[0]['session_id']);
    }

    public function test_returns_correct_title(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildResponseXml('ok', [
            $this->sessionData('sess-001', 'Keynote: AI in de zorgsector'),
        ]));
        $this->assertSame('Keynote: AI in de zorgsector', $result[0]['title']);
    }

    public function test_returns_correct_datetimes(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildResponseXml('ok', [
            $this->sessionData('sess-001', 'Test', '2026-05-15T14:00:00Z', '2026-05-15T15:00:00Z'),
        ]));
        $this->assertSame('2026-05-15T14:00:00Z', $result[0]['start_datetime']);
        $this->assertSame('2026-05-15T15:00:00Z', $result[0]['end_datetime']);
    }

    public function test_returns_max_attendees_as_int(): void
    {
        $data = $this->sessionData('sess-001', 'Test');
        $data['maxAttendees'] = 120;
        $result = $this->receiver->processMessageFromXml($this->buildResponseXml('ok', [$data]));
        $this->assertSame(120, $result[0]['max_attendees']);
        $this->assertIsInt($result[0]['max_attendees']);
    }

    public function test_returns_current_attendees_as_int(): void
    {
        $data = $this->sessionData('sess-001', 'Test');
        $data['currentAttendees'] = 25;
        $result = $this->receiver->processMessageFromXml($this->buildResponseXml('ok', [$data]));
        $this->assertSame(25, $result[0]['current_attendees']);
        $this->assertIsInt($result[0]['current_attendees']);
    }

    // ─── Optional fields ─────────────────────────────────────────────────────

    public function test_location_defaults_to_empty_string_when_absent(): void
    {
        $data = $this->sessionData('sess-001', 'Test');
        unset($data['location']);
        $result = $this->receiver->processMessageFromXml($this->buildResponseXml('ok', [$data]));
        $this->assertSame('', $result[0]['location']);
    }

    public function test_session_type_defaults_to_empty_string_when_absent(): void
    {
        $data = $this->sessionData('sess-001', 'Test');
        unset($data['sessionType']);
        $result = $this->receiver->processMessageFromXml($this->buildResponseXml('ok', [$data]));
        $this->assertSame('', $result[0]['session_type']);
    }

    public function test_skips_session_without_session_id(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns="urn:integration:planning:v1">'
            . '<body><status>ok</status><session_count>1</session_count>'
            . '<sessions><session><title>No ID</title></session></sessions>'
            . '</body></message>';

        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertSame([], $result);
    }

    // ─── Namespace handling ───────────────────────────────────────────────────

    public function test_parses_xml_with_planning_namespace(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildResponseXml('ok', [
            $this->sessionData('ns-sess-001', 'Namespace test'),
        ]));
        $this->assertSame('ns-sess-001', $result[0]['session_id']);
    }

    public function test_parses_xml_without_namespace(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message><body><status>ok</status><session_count>1</session_count>'
            . '<sessions><session>'
            . '<session_id>no-ns-001</session_id><title>Test</title>'
            . '</session></sessions></body></message>';

        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertSame('no-ns-001', $result[0]['session_id']);
    }

    // ─── Return shape ─────────────────────────────────────────────────────────

    public function test_each_session_contains_all_expected_keys(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildResponseXml('ok', [
            $this->sessionData('sess-001', 'Test'),
        ]));
        $expected = ['session_id', 'title', 'start_datetime', 'end_datetime', 'location', 'session_type', 'status', 'max_attendees', 'current_attendees'];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $result[0], "Key '{$key}' must be present");
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function sessionData(
        string $sessionId,
        string $title,
        string $startDatetime = '2026-05-15T14:00:00Z',
        string $endDatetime = '2026-05-15T15:00:00Z',
        string $location = 'Aula A',
        string $sessionType = 'keynote',
        string $status = 'published',
        int $maxAttendees = 120,
        int $currentAttendees = 0,
    ): array {
        return compact('sessionId', 'title', 'startDatetime', 'endDatetime', 'location', 'sessionType', 'status', 'maxAttendees', 'currentAttendees');
    }

    private function buildResponseXml(string $status, array $sessions): string
    {
        $sessionsXml = '';
        foreach ($sessions as $s) {
            $sessionsXml .= '<session>';
            if (isset($s['sessionId'])) {
                $sessionsXml .= '<session_id>' . htmlspecialchars($s['sessionId'], ENT_XML1) . '</session_id>';
            }
            if (isset($s['title'])) {
                $sessionsXml .= '<title>' . htmlspecialchars($s['title'], ENT_XML1) . '</title>';
            }
            if (isset($s['startDatetime'])) {
                $sessionsXml .= '<start_datetime>' . $s['startDatetime'] . '</start_datetime>';
            }
            if (isset($s['endDatetime'])) {
                $sessionsXml .= '<end_datetime>' . $s['endDatetime'] . '</end_datetime>';
            }
            if (isset($s['location'])) {
                $sessionsXml .= '<location>' . htmlspecialchars($s['location'], ENT_XML1) . '</location>';
            }
            if (isset($s['sessionType'])) {
                $sessionsXml .= '<session_type>' . $s['sessionType'] . '</session_type>';
            }
            if (isset($s['status'])) {
                $sessionsXml .= '<status>' . $s['status'] . '</status>';
            }
            if (isset($s['maxAttendees'])) {
                $sessionsXml .= '<max_attendees>' . $s['maxAttendees'] . '</max_attendees>';
            }
            if (isset($s['currentAttendees'])) {
                $sessionsXml .= '<current_attendees>' . $s['currentAttendees'] . '</current_attendees>';
            }
            $sessionsXml .= '</session>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message>'
            . '<header>'
            . '<message_id>01890a5d-ac96-7ab2-80e2-4536629c90de</message_id>'
            . '<timestamp>2026-04-05T12:00:00Z</timestamp>'
            . '<source>planning</source>'
            . '<type>session_view_response</type>'
            . '<version>2.0</version>'
            . '</header>'
            . '<body>'
            . '<request_message_id>01890a5d-ac96-7ab2-80e2-4536629c90de</request_message_id>'
            . '<status>' . $status . '</status>'
            . '<session_count>' . count($sessions) . '</session_count>'
            . '<sessions>' . $sessionsXml . '</sessions>'
            . '</body></message>';
    }
}
