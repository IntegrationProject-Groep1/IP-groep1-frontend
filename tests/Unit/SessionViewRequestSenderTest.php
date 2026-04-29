<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\SessionViewRequestSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for SessionViewRequestSender — XML generation and exchange routing.
 */
class SessionViewRequestSenderTest extends TestCase
{
    private SessionViewRequestSender $sender;

    protected function setUp(): void
    {
        $stub = $this->createStub(RabbitMQClient::class);
        $this->sender = new SessionViewRequestSender($stub);
    }

    // ─── buildXml: XML structure ──────────────────────────────────────────────

    public function test_buildXml_contains_planning_namespace(): void
    {
        $xml = $this->sender->buildXml();

        $this->assertStringContainsString('xmlns="urn:integration:planning:v1"', $xml);
    }

    public function test_buildXml_contains_correct_type(): void
    {
        $xml = $this->sender->buildXml();

        $this->assertStringContainsString('<type>session_view_request</type>', $xml);
    }

    public function test_buildXml_contains_correct_version(): void
    {
        $xml = $this->sender->buildXml();

        $this->assertStringContainsString('<version>1.0</version>', $xml);
    }

    public function test_buildXml_contains_source_frontend(): void
    {
        $xml = $this->sender->buildXml();

        $this->assertStringContainsString('<source>frontend</source>', $xml);
    }

    public function test_buildXml_contains_message_id_uuid_v4(): void
    {
        $xml = $this->sender->buildXml();

        $this->assertMatchesRegularExpression(
            '/<message_id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}<\/message_id>/',
            $xml
        );
    }

    public function test_buildXml_contains_timestamp(): void
    {
        $xml = $this->sender->buildXml();

        $this->assertStringContainsString('<timestamp>', $xml);
    }

    public function test_buildXml_produces_well_formed_xml(): void
    {
        $xml = $this->sender->buildXml();

        $dom = new \DOMDocument();
        $this->assertNotFalse($dom->loadXML($xml), 'buildXml() must produce well-formed XML');
    }

    // ─── buildXml: optional session_id ───────────────────────────────────────

    public function test_buildXml_includes_session_id_when_provided(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'sess-uuid-001']);

        $this->assertStringContainsString('<session_id>sess-uuid-001</session_id>', $xml);
    }

    public function test_buildXml_omits_session_id_when_not_provided(): void
    {
        $xml = $this->sender->buildXml();

        $this->assertStringNotContainsString('<session_id>', $xml);
    }

    public function test_buildXml_omits_session_id_when_empty(): void
    {
        $xml = $this->sender->buildXml(['session_id' => '']);

        $this->assertStringNotContainsString('<session_id>', $xml);
    }

    public function test_buildXml_escapes_special_chars_in_session_id(): void
    {
        $xml = $this->sender->buildXml(['session_id' => 'id<with>&special']);

        $this->assertStringContainsString('<session_id>id&lt;with&gt;&amp;special</session_id>', $xml);
    }

    public function test_each_call_generates_unique_message_id(): void
    {
        $xml1 = $this->sender->buildXml();
        $xml2 = $this->sender->buildXml();

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

        (new SessionViewRequestSender($mock))->send();
    }

    public function test_send_publishes_to_correct_routing_key(): void
    {
        $mock = $this->createMock(RabbitMQClient::class);
        $mock->method('declareExchange');
        $mock->expects($this->once())
            ->method('publishToExchange')
            ->with('planning.exchange', 'planning.session.view.request', $this->anything());

        (new SessionViewRequestSender($mock))->send();
    }

    public function test_send_passes_well_formed_xml(): void
    {
        $mock = $this->createMock(RabbitMQClient::class);
        $mock->method('declareExchange');
        $mock->expects($this->once())
            ->method('publishToExchange')
            ->with(
                'planning.exchange',
                'planning.session.view.request',
                $this->callback(static function (string $xml): bool {
                    $dom = new \DOMDocument();
                    return $dom->loadXML($xml) !== false;
                })
            );

        (new SessionViewRequestSender($mock))->send(['session_id' => 'sess-001']);
    }
}
