<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\SessionUpdateReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;
use Tests\Unit\XmlTestBuilder;

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

    private function buildXml(array $fields): string
    {
        return XmlTestBuilder::build('session_updated', [], $fields);
    }

    // ─── Invalid XML ──────────────────────────────────────────────────────────

    public function test_throws_when_xml_is_completely_invalid(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml('invalid xml');
    }

    public function test_throws_when_xml_is_empty_string(): void
    {
        $this->expectException(\ValueError::class);
        $this->receiver->processMessageFromXml('');
    }

    public function test_throws_when_xml_is_unclosed_tag(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml('<message><header><type>session_updated</type></header><body><session_id>abc</session_id>');
    }

    // ─── Missing required fields ──────────────────────────────────────────────

    public function test_throws_when_session_id_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml(['title' => 'T', 'start_datetime' => '2026-05-15T10:00:00Z', 'end_datetime' => '2026-05-15T12:00:00Z']));
    }

    public function test_throws_when_session_id_empty(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml(['session_id' => '   ']));
    }

    public function test_throws_when_title_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml(['session_id' => 'sess-001', 'start_datetime' => '2026-05-15T10:00:00Z', 'end_datetime' => '2026-05-15T12:00:00Z']));
    }

    public function test_throws_when_start_datetime_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml(['session_id' => 'sess-001', 'title' => 'T', 'end_datetime' => '2026-05-15T12:00:00Z']));
    }

    public function test_throws_when_end_datetime_missing(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->buildXml(['session_id' => 'sess-001', 'title' => 'T', 'start_datetime' => '2026-05-15T10:00:00Z']));
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

    // ─── Namespace handling ───────────────────────────────────────────────────

    public function test_parses_xml_with_planning_namespace(): void
    {
        $xml = XmlTestBuilder::build('session_updated', [], [
            'session_id' => 'ns-sess-001',
            'title' => 'Namespace update',
            'start_datetime' => '2026-05-15T14:30:00Z',
            'end_datetime' => '2026-05-15T15:30:00Z',
            'location' => 'Aula A',
            'session_type' => 'keynote',
            'status' => 'published',
            'max_attendees' => '10',
            'current_attendees' => '0'
        ]);
        $result = $this->receiver->processMessageFromXml($xml);
        $this->assertSame('ns-sess-001', $result['session_id']);
    }

    public function test_parses_xml_without_namespace(): void
    {
        $xml = $this->buildXml([
            'session_id' => 'no-ns-001',
            'title' => 'Geen namespace',
            'start_datetime' => '2026-05-15T14:30:00Z',
            'end_datetime' => '2026-05-15T15:30:00Z',
            'location' => 'Aula A',
            'session_type' => 'keynote',
            'status' => 'published',
            'max_attendees' => '10',
            'current_attendees' => '0'
        ]);
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
}