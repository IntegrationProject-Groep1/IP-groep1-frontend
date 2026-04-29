<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Drupal\rabbitmq_sender\CalendarInviteSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for CalendarInviteSender — XML generation and validation.
 */
class CalendarInviteSenderTest extends TestCase
{
    private CalendarInviteSender $sender;

    protected function setUp(): void
    {
        // Use createStub() for tests that only exercise buildXml() or send()
        // without assertions on the mock; avoids PHPUnit unexpected-call notices.
        $stub = $this->createStub(RabbitMQClient::class);
        $this->sender = new CalendarInviteSender($stub);
    }

    // ─── buildXml: required field validation ─────────────────────────────────

    public function test_buildXml_throws_when_session_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('session_id is required');

        $this->sender->buildXml([
            'title'          => 'Keynote',
            'start_datetime' => '2026-05-15T14:00:00Z',
            'end_datetime'   => '2026-05-15T15:00:00Z',
        ]);
    }

    public function test_buildXml_throws_when_title_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title is required');

        $this->sender->buildXml([
            'session_id'     => 'sess-001',
            'start_datetime' => '2026-05-15T14:00:00Z',
            'end_datetime'   => '2026-05-15T15:00:00Z',
        ]);
    }

    public function test_buildXml_throws_when_start_datetime_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start_datetime is required');

        $this->sender->buildXml([
            'session_id'   => 'sess-001',
            'title'        => 'Keynote',
            'end_datetime' => '2026-05-15T15:00:00Z',
        ]);
    }

    public function test_buildXml_throws_when_end_datetime_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('end_datetime is required');

        $this->sender->buildXml([
            'session_id'     => 'sess-001',
            'title'          => 'Keynote',
            'start_datetime' => '2026-05-15T14:00:00Z',
        ]);
    }

    // ─── buildXml: correct XML structure ─────────────────────────────────────

    public function test_buildXml_contains_correct_namespace(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('xmlns="urn:integration:planning:v1"', $xml);
    }

    public function test_buildXml_contains_type_calendar_invite(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<type>calendar.invite</type>', $xml);
    }

    public function test_buildXml_contains_source_frontend(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<source>frontend</source>', $xml);
    }

    public function test_buildXml_contains_all_required_body_fields(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<session_id>sess-uuid-001</session_id>', $xml);
        $this->assertStringContainsString('<title>Keynote: AI in de zorgsector</title>', $xml);
        $this->assertStringContainsString('<start_datetime>2026-05-15T14:00:00Z</start_datetime>', $xml);
        $this->assertStringContainsString('<end_datetime>2026-05-15T15:00:00Z</end_datetime>', $xml);
    }

    public function test_buildXml_includes_location_when_provided(): void
    {
        $data = $this->validData();
        $data['location'] = 'Aula A - Campus Jette';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('<location>Aula A - Campus Jette</location>', $xml);
    }

    public function test_buildXml_omits_location_when_not_in_data(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringNotContainsString('<location>', $xml);
    }

    public function test_buildXml_includes_user_id_when_provided(): void
    {
        $data = $this->validData();
        $data['user_id'] = 'user-uuid-001';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('<user_id>user-uuid-001</user_id>', $xml);
    }

    public function test_buildXml_omits_user_id_when_not_provided(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringNotContainsString('<user_id>', $xml);
    }

    public function test_buildXml_omits_user_id_when_empty(): void
    {
        $data = $this->validData();
        $data['user_id'] = '';

        $xml = $this->sender->buildXml($data);

        $this->assertStringNotContainsString('<user_id>', $xml);
    }

    public function test_buildXml_escapes_special_chars_in_user_id(): void
    {
        $data = $this->validData();
        $data['user_id'] = 'id<with>&special';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('<user_id>id&lt;with&gt;&amp;special</user_id>', $xml);
    }

    public function test_buildXml_includes_empty_location_when_key_present_but_empty(): void
    {
        $data = $this->validData();
        $data['location'] = '';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('<location/>', $xml);
    }

    public function test_buildXml_produces_well_formed_xml(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $dom = new \DOMDocument();
        $result = $dom->loadXML($xml);

        $this->assertNotFalse($result, 'buildXml() must produce well-formed XML');
    }

    public function test_buildXml_contains_message_id_in_header(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertMatchesRegularExpression('/<message_id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}<\/message_id>/', $xml);
    }

    public function test_buildXml_contains_timestamp_in_header(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<timestamp>', $xml);
    }

    // ─── buildXml: XSS / injection escaping ──────────────────────────────────

    public function test_buildXml_escapes_html_in_title(): void
    {
        $data = $this->validData();
        $data['title'] = '<script>alert("xss")</script>';

        $xml = $this->sender->buildXml($data);

        $this->assertStringNotContainsString('<script>', $xml);
        $this->assertStringContainsString('&lt;script&gt;', $xml);
    }

    public function test_buildXml_escapes_ampersand_in_title(): void
    {
        $data = $this->validData();
        $data['title'] = 'Sessie A & B';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('Sessie A &amp; B', $xml);
    }

    public function test_buildXml_escapes_html_in_location(): void
    {
        $data = $this->validData();
        $data['location'] = '<img src=x onerror=alert(1)>';

        $xml = $this->sender->buildXml($data);

        $this->assertStringNotContainsString('<img', $xml);
    }

    public function test_buildXml_escapes_html_in_session_id(): void
    {
        $data = $this->validData();
        $data['session_id'] = 'sess-<evil>';

        $xml = $this->sender->buildXml($data);

        $this->assertStringNotContainsString('<evil>', $xml);
    }

    // ─── send(): exchange and routing key ────────────────────────────────────

    public function test_send_declares_calendar_exchange(): void
    {
        $mock = $this->createMock(RabbitMQClient::class);
        $mock->expects($this->once())
            ->method('declareExchange')
            ->with('calendar.exchange', 'topic');
        $mock->method('publishToExchange');

        (new CalendarInviteSender($mock))->send($this->validData());
    }

    public function test_send_publishes_to_correct_exchange_and_routing_key(): void
    {
        $mock = $this->createMock(RabbitMQClient::class);
        $mock->method('declareExchange');
        $mock->expects($this->once())
            ->method('publishToExchange')
            ->with(
                'calendar.exchange',
                'frontend.to.planning.calendar.invite',
                $this->anything()
            );

        (new CalendarInviteSender($mock))->send($this->validData());
    }

    public function test_send_throws_when_session_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('session_id is required');

        $data = $this->validData();
        unset($data['session_id']);
        $this->sender->send($data);
    }

    public function test_send_throws_when_title_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title is required');

        $data = $this->validData();
        unset($data['title']);
        $this->sender->send($data);
    }

    public function test_send_throws_when_start_datetime_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start_datetime is required');

        $data = $this->validData();
        unset($data['start_datetime']);
        $this->sender->send($data);
    }

    public function test_send_throws_when_end_datetime_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('end_datetime is required');

        $data = $this->validData();
        unset($data['end_datetime']);
        $this->sender->send($data);
    }

    public function test_send_passes_valid_xml_to_publishToExchange(): void
    {
        $mock = $this->createMock(RabbitMQClient::class);
        $mock->method('declareExchange');
        $mock->expects($this->once())
            ->method('publishToExchange')
            ->with(
                'calendar.exchange',
                'frontend.to.planning.calendar.invite',
                $this->callback(static function (string $xml): bool {
                    $dom = new \DOMDocument();
                    return $dom->loadXML($xml) !== false;
                })
            );

        (new CalendarInviteSender($mock))->send($this->validData());
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function validData(): array
    {
        return [
            'session_id'     => 'sess-uuid-001',
            'title'          => 'Keynote: AI in de zorgsector',
            'start_datetime' => '2026-05-15T14:00:00Z',
            'end_datetime'   => '2026-05-15T15:00:00Z',
        ];
    }
}
