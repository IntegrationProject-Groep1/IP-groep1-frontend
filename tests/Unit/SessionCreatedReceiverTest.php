<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_receiver\SessionCreatedReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for SessionCreatedReceiver — XML parsing and validation.
 */
class SessionCreatedReceiverTest extends TestCase
{
    private SessionCreatedReceiver $receiver;

    protected function setUp(): void
    {
        $stub = $this->createStub(RabbitMQClient::class);
        $this->receiver = new SessionCreatedReceiver($stub);
    }

    // ─── Invalid XML ─────────────────────────────────────────────────────────

    public function test_throws_when_xml_is_completely_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid XML received');

        $this->receiver->processMessageFromXml('this is not xml at all');
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
        $this->expectException(\Exception::class);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><header>'
            . '<message_id>550e8400-e29b-41d4-a716-446655440001</message_id>'
            . '<timestamp>2026-05-07T00:00:00Z</timestamp>'
            . '<source>planning</source>'
            . '<type>session_created</type>'
            . '<version>2.0</version>'
            . '</header><body>'
            . '<title>Title</title>'
            . '<start_datetime>2026-05-07T10:00:00Z</start_datetime>'
            . '<end_datetime>2026-05-07T12:00:00Z</end_datetime>'
            . '</body></message>';

        $this->receiver->processMessageFromXml($xml);
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
            'session_id' => 'sess-001',
            'title'      => 'Keynote',
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

    public function test_valid_xml_returns_correct_session_id(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));

        $this->assertSame('sess-uuid-001', $result['session_id']);
    }

    public function test_valid_xml_returns_correct_title(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));

        $this->assertSame('Keynote: AI in de zorgsector', $result['title']);
    }

    public function test_valid_xml_returns_correct_datetimes(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));

        $this->assertSame('2026-05-15T14:00:00Z', $result['start_datetime']);
        $this->assertSame('2026-05-15T15:00:00Z', $result['end_datetime']);
    }

    public function test_valid_xml_returns_correct_location(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));

        $this->assertSame('Aula A - Campus Jette', $result['location']);
    }

    public function test_valid_xml_returns_correct_status(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));

        $this->assertSame('published', $result['status']);
    }

    public function test_valid_xml_returns_max_attendees_as_int(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));

        $this->assertSame(120, $result['max_attendees']);
        $this->assertIsInt($result['max_attendees']);
    }

    public function test_valid_xml_returns_current_attendees_as_int(): void
    {
        $result = $this->receiver->processMessageFromXml($this->buildXml($this->fullData()));

        $this->assertSame(0, $result['current_attendees']);
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
        // Planning's producer always includes the namespace on the root element.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns="urn:integration:planning:v1">'
            . '<header><type>session_created</type></header>'
            . '<body>'
            . '<session_id>ns-sess-001</session_id>'
            . '<title>Namespace test session</title>'
            . '<start_datetime>2026-05-15T14:00:00Z</start_datetime>'
            . '<end_datetime>2026-05-15T15:00:00Z</end_datetime>'
            . '</body></message>';

        $result = $this->receiver->processMessageFromXml($xml);

        $this->assertSame('ns-sess-001', $result['session_id']);
        $this->assertSame('Namespace test session', $result['title']);
    }

    public function test_parses_xml_without_namespace(): void
    {
        // Defensive: handle messages without the namespace declaration.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message>'
            . '<body>'
            . '<session_id>no-ns-001</session_id>'
            . '<title>Geen namespace</title>'
            . '<start_datetime>2026-05-15T14:00:00Z</start_datetime>'
            . '<end_datetime>2026-05-15T15:00:00Z</end_datetime>'
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
            $this->assertArrayHasKey($key, $result, "Key '{$key}' must be present in the result");
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function fullData(): array
    {
        return [
            'session_id'        => 'sess-uuid-001',
            'title'             => 'Keynote: AI in de zorgsector',
            'start_datetime'    => '2026-05-15T14:00:00Z',
            'end_datetime'      => '2026-05-15T15:00:00Z',
            'location'          => 'Aula A - Campus Jette',
            'session_type'      => 'keynote',
            'status'            => 'published',
            'max_attendees'     => 120,
            'current_attendees' => 0,
        ];
    }

    /**
     * Builds a minimal Planning-style XML string from the given fields.
     * Only the fields present in $fields are included in the <body>.
     */
    /**
     * Builds a minimal Planning-style XML string from the given fields.
     */
    private function buildXml(array $fields): string
    {
        $header = '<header>'
            . '<message_id>550e8400-e29b-41d4-a716-446655440001</message_id>'
            . '<timestamp>2026-05-07T00:00:00Z</timestamp>'
            . '<source>planning</source>'
            . '<type>session_created</type>'
            . '<version>2.0</version>'
            . '</header>';

        // XSD dictates the order of elements in <body>
        $order = ['session_id', 'title', 'start_datetime', 'end_datetime', 'location', 'session_type', 'status', 'max_attendees', 'current_attendees'];
        
        $body = '<body>';
        foreach ($order as $key) {
            // Include element if it exists in fields array
            // If the element is missing, provide a default or dummy value that is valid per XSD.
            if (array_key_exists($key, $fields)) {
                $body .= "<{$key}>" . htmlspecialchars((string) $fields[$key], ENT_XML1, 'UTF-8') . "</{$key}>";
            } else {
                // For tests checking missing required fields, skip the key.
                // For others, provide a dummy to maintain order if required by XSD sequence.
                if (in_array($key, ['session_id', 'title', 'start_datetime', 'end_datetime'])) {
                    // This will intentionally cause validation error if mandatory and omitted.
                    continue;
                }
                // Provide defaults for optional fields to keep sequence order.
                $defaults = [
                    'location' => 'Room 1',
                    'session_type' => 'other',
                    'status' => 'draft',
                    'max_attendees' => '1',
                    'current_attendees' => '0'
                ];
                $body .= "<{$key}>" . $defaults[$key] . "</{$key}>";
            }
        }
        $body .= '</body>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . $header
            . $body
            . '</message>';
    }
}
