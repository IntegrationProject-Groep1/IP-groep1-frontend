<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\SessionUpdateReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for SessionUpdateReceiver — Planning XSD session_updated contract.
 */
class SessionUpdateReceiverTest extends TestCase
{
    private SessionUpdateReceiver $receiver;

    protected function setUp(): void
    {
        $stub = $this->createStub(RabbitMQClient::class);
        $this->receiver = new SessionUpdateReceiver($stub);
    }

    // ─── Invalid XML ──────────────────────────────────────────────────────────

    public function test_throws_when_xml_is_completely_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid XML received');
        $this->receiver->processMessageFromXml('invalid xml');
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

    public function test_throws_when_title_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title is required');
        $this->receiver->processMessageFromXml($this->buildXml([
            'session_id'     => 'sess-001',
            'start_datetime' => '2026-05-15T14:00:00Z',
            'end_datetime'   => '2026-05-15T15:00:00Z',
        ]));
    }

    public function test_throws_when_start_datetime_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start_datetime is required');
        $this->receiver->processMessageFromXml($this->buildXml([
            'session_id'   => 'sess-001',
            'title'        => 'Keynote',
            'end_datetime' => '2026-05-15T15:00:00Z',
        ]));
    }

    public function test_throws_when_end_datetime_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('end_datetime is required');
        $this->receiver->processMessageFromXml($this->buildXml([
            'session_id'     => 'sess-001',
            'title'          => 'Keynote',
            'start_datetime' => '2026-05-15T14:00:00Z',
        ]));
    }

    // ─── Successful parsing ───────────────────────────────────────────────────

    public function test_valid_xml_returns_array(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));
        $this->assertIsArray($result);
    }

    public function test_returns_correct_session_id(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));
        $this->assertSame('sess-uuid-001', $result['session_id']);
    }

    public function test_returns_correct_title(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));
        $this->assertSame('Keynote: AI Updated', $result['title']);
    }

    public function test_returns_correct_datetimes(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));
        $this->assertSame('2026-05-15T14:30:00Z', $result['start_datetime']);
        $this->assertSame('2026-05-15T15:30:00Z', $result['end_datetime']);
    }

    public function test_returns_correct_location(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));
        $this->assertSame('Aula B - Campus Jette', $result['location']);
    }

    public function test_returns_correct_status(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));
        $this->assertSame('published', $result['status']);
    }

    public function test_returns_max_attendees_as_int(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));
        $this->assertSame(150, $result['max_attendees']);
        $this->assertIsInt($result['max_attendees']);
    }

    public function test_returns_current_attendees_as_int(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));
        $this->assertSame(25, $result['current_attendees']);
        $this->assertIsInt($result['current_attendees']);
    }

    // ─── Optional fields ─────────────────────────────────────────────────────

    public function test_location_defaults_to_empty_string_when_absent(): void
    {
        $data = $this->fullData();
        unset($data['location']);
        $result = $this->receiver->processMessageFromXml($this->buildXml($data));
        $this->assertSame('', $result['location']);
    }

    public function test_session_type_defaults_to_empty_string_when_absent(): void
    {
        $data = $this->fullData();
        unset($data['session_type']);
        $result = $this->receiver->processMessageFromXml($this->buildXml($data));
        $this->assertSame('', $result['session_type']);
    }

    public function test_max_attendees_defaults_to_zero_when_absent(): void
    {
        $data = $this->fullData();
        unset($data['max_attendees']);
        $result = $this->receiver->processMessageFromXml($this->buildXml($data));
        $this->assertSame(0, $result['max_attendees']);
    }

    // ─── Namespace handling ───────────────────────────────────────────────────

    public function test_parses_xml_with_planning_namespace(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns="urn:integration:planning:v1">'
            . '<header><type>session_updated</type></header>'
            . '<body>'
            . '<session_id>ns-sess-001</session_id>'
            . '<title>Namespace update</title>'
            . '<start_datetime>2026-05-15T14:30:00Z</start_datetime>'
            . '<end_datetime>2026-05-15T15:30:00Z</end_datetime>'
            . '</body></message>';

        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertSame('ns-sess-001', $result['session_id']);
    }

    public function test_parses_xml_without_namespace(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message><body>'
            . '<session_id>no-ns-001</session_id>'
            . '<title>Geen namespace</title>'
            . '<start_datetime>2026-05-15T14:30:00Z</start_datetime>'
            . '<end_datetime>2026-05-15T15:30:00Z</end_datetime>'
            . '</body></message>';

        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertSame('no-ns-001', $result['session_id']);
    }

    // ─── Return shape ─────────────────────────────────────────────────────────

    public function test_returned_array_contains_all_expected_keys(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));
        $expected = ['session_id', 'title', 'start_datetime', 'end_datetime', 'location', 'session_type', 'status', 'max_attendees', 'current_attendees'];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $result, "Key '{$key}' must be present");
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function fullData(): array
    {
        return [
            'session_id'        => 'sess-uuid-001',
            'title'             => 'Keynote: AI Updated',
            'start_datetime'    => '2026-05-15T14:30:00Z',
            'end_datetime'      => '2026-05-15T15:30:00Z',
            'location'          => 'Aula B - Campus Jette',
            'session_type'      => 'keynote',
            'status'            => 'published',
            'max_attendees'     => 150,
            'current_attendees' => 25,
        ];
    }

    private function buildXml(array $fields): string
    {
        $body = '';
        foreach ($fields as $key => $value) {
            $body .= "<{$key}>" . htmlspecialchars((string) $value, ENT_XML1, 'UTF-8') . "</{$key}>";
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns="urn:integration:planning:v1">'
            . '<header><type>session_updated</type></header>'
            . "<body>{$body}</body>"
            . '</message>';
    }
}