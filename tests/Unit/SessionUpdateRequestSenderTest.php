<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\SessionUpdateRequestSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for SessionUpdateRequestSender — XML generation and exchange routing.
 */
class SessionUpdateRequestSenderTest extends TestCase
{
    private SessionUpdateRequestSender $sender;

    protected function setUp(): void
    {
        $stub = $this->createStub(RabbitMQClient::class);
        $this->sender = new SessionUpdateRequestSender($stub);
    }

    // ─── buildXml: required field validation ─────────────────────────────────

    public function test_buildXml_throws_when_session_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('session_id is required');

        $this->sender->buildXml($this->validData(['session_id' => '']));
    }

    public function test_buildXml_throws_when_title_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title is required');

        $this->sender->buildXml($this->validData(['title' => '']));
    }

    public function test_buildXml_throws_when_start_datetime_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start_datetime is required');

        $this->sender->buildXml($this->validData(['start_datetime' => '']));
    }

    public function test_buildXml_throws_when_end_datetime_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('end_datetime is required');

        $this->sender->buildXml($this->validData(['end_datetime' => '']));
    }

    // ─── buildXml: XML structure ──────────────────────────────────────────────

    public function test_buildXml_does_not_contain_namespace(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringNotContainsString('xmlns=', $xml);
    }

    public function test_buildXml_contains_correct_type(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<type>session_update_request</type>', $xml);
    }

    public function test_buildXml_contains_correct_version(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<version>2.0</version>', $xml);
    }

    public function test_buildXml_contains_source_frontend(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<source>frontend</source>', $xml);
    }

    public function test_buildXml_contains_message_id_uuid_v4(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertMatchesRegularExpression(
            '/<message_id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}<\/message_id>/',
            $xml
        );
    }

    public function test_buildXml_contains_timestamp(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<timestamp>', $xml);
    }

    public function test_buildXml_produces_well_formed_xml(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $dom = new \DOMDocument();
        $this->assertNotFalse($dom->loadXML($xml), 'buildXml() must produce well-formed XML');
    }

    // ─── buildXml: required body fields ──────────────────────────────────────

    public function test_buildXml_contains_session_id(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<session_id>sess-uuid-001</session_id>', $xml);
    }

    public function test_buildXml_contains_title(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<title>Keynote: AI 2026</title>', $xml);
    }

    public function test_buildXml_contains_start_datetime(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<start_datetime>2026-05-15T14:00:00Z</start_datetime>', $xml);
    }

    public function test_buildXml_contains_end_datetime(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<end_datetime>2026-05-15T15:00:00Z</end_datetime>', $xml);
    }

    // ─── buildXml: optional fields ────────────────────────────────────────────

    public function test_buildXml_includes_location_when_provided(): void
    {
        $xml = $this->sender->buildXml($this->validData(['location' => 'Zaal A']));

        $this->assertStringContainsString('<location>Zaal A</location>', $xml);
    }

    public function test_buildXml_omits_location_when_not_provided(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringNotContainsString('<location>', $xml);
    }

    public function test_buildXml_includes_session_type_when_provided(): void
    {
        $xml = $this->sender->buildXml($this->validData(['session_type' => 'keynote']));

        $this->assertStringContainsString('<session_type>keynote</session_type>', $xml);
    }

    public function test_buildXml_includes_status_when_provided(): void
    {
        $xml = $this->sender->buildXml($this->validData(['status' => 'published']));

        $this->assertStringContainsString('<status>published</status>', $xml);
    }

    public function test_buildXml_includes_max_attendees_when_provided(): void
    {
        $xml = $this->sender->buildXml($this->validData(['max_attendees' => 150]));

        $this->assertStringContainsString('<max_attendees>150</max_attendees>', $xml);
    }

    public function test_buildXml_omits_optional_fields_when_not_provided(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringNotContainsString('<location>', $xml);
        $this->assertStringNotContainsString('<session_type>', $xml);
        $this->assertStringNotContainsString('<status>', $xml);
        $this->assertStringNotContainsString('<max_attendees>', $xml);
    }

    public function test_each_call_generates_unique_message_id(): void
    {
        $xml1 = $this->sender->buildXml($this->validData());
        $xml2 = $this->sender->buildXml($this->validData());

        preg_match('/<message_id>([^<]+)<\/message_id>/', $xml1, $m1);
        preg_match('/<message_id>([^<]+)<\/message_id>/', $xml2, $m2);

        $this->assertNotSame($m1[1], $m2[1]);
    }

    // ─── send(): exchange and routing key ────────────────────────────────────

    public function test_send_declares_planning_exchange(): void
    {
        $mock = $this->createMock(RabbitMQClient::class);
        $mock->expects($this->once())
            ->method('declareExchange')
            ->with('planning.exchange', 'topic');
        $mock->method('publishToExchange');

        (new SessionUpdateRequestSender($mock))->send($this->validData());
    }

    public function test_send_publishes_to_correct_routing_key(): void
    {
        $mock = $this->createMock(RabbitMQClient::class);
        $mock->method('declareExchange');
        $mock->expects($this->once())
            ->method('publishToExchange')
            ->with('planning.exchange', 'frontend.to.planning.session.update', $this->anything());

        (new SessionUpdateRequestSender($mock))->send($this->validData());
    }

    public function test_send_passes_well_formed_xml(): void
    {
        $mock = $this->createMock(RabbitMQClient::class);
        $mock->method('declareExchange');
        $mock->expects($this->once())
            ->method('publishToExchange')
            ->with(
                'planning.exchange',
                'frontend.to.planning.session.update',
                $this->callback(static function (string $xml): bool {
                    $dom = new \DOMDocument();
                    return $dom->loadXML($xml) !== false;
                })
            );

        (new SessionUpdateRequestSender($mock))->send($this->validData());
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'session_id'     => 'sess-uuid-001',
            'title'          => 'Keynote: AI 2026',
            'start_datetime' => '2026-05-15T14:00:00Z',
            'end_datetime'   => '2026-05-15T15:00:00Z',
        ], $overrides);
    }
}
