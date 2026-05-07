<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Drupal\rabbitmq_sender\PaymentRegisteredSender;
use Drupal\rabbitmq_sender\RabbitMQClient;

/**
 * Unit tests for PaymentRegisteredSender — contract §11.5.
 */
class PaymentRegisteredSenderTest extends TestCase
{
    private PaymentRegisteredSender $sender;

    protected function setUp(): void
    {
        $stub = $this->createStub(RabbitMQClient::class);
        $this->sender = new PaymentRegisteredSender($stub);
    }

    // ─── validation ──────────────────────────────────────────────────────────

    public function test_throws_when_invoice_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invoice is required');

        $this->sender->buildXml(['payment_context' => 'online_invoice']);
    }

    public function test_throws_when_invoice_amount_paid_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invoice.amount_paid is required');

        $this->sender->buildXml([
            'invoice'         => ['status' => 'paid'],
            'payment_context' => 'online_invoice',
        ]);
    }

    public function test_throws_when_invoice_amount_paid_not_numeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invoice.amount_paid must be numeric');

        $this->sender->buildXml([
            'invoice'         => ['amount_paid' => 'not-a-number', 'status' => 'paid'],
            'payment_context' => 'online_invoice',
        ]);
    }

    public function test_throws_when_invoice_status_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invoice.status is required');

        $this->sender->buildXml([
            'invoice'         => ['amount_paid' => '150.00'],
            'payment_context' => 'online_invoice',
        ]);
    }

    public function test_throws_when_invoice_status_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invoice.status must be');

        $this->sender->buildXml([
            'invoice'         => ['amount_paid' => '150.00', 'status' => 'unknown'],
            'payment_context' => 'online_invoice',
        ]);
    }

    public function test_throws_when_payment_context_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('payment_context is required');

        $this->sender->buildXml([
            'invoice' => ['amount_paid' => '150.00', 'status' => 'paid'],
        ]);
    }

    public function test_throws_when_payment_context_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('payment_context must be');

        $this->sender->buildXml([
            'invoice'         => ['amount_paid' => '150.00', 'status' => 'paid'],
            'payment_context' => 'invalid_context',
        ]);
    }

    public function test_throws_when_transaction_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transaction.id is required');

        $this->sender->buildXml([
            'invoice'         => ['amount_paid' => '150.00', 'status' => 'paid'],
            'payment_context' => 'online_invoice',
            'transaction'     => ['payment_method' => 'online'],
        ]);
    }

    public function test_throws_when_transaction_payment_method_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transaction.payment_method must be');

        $this->sender->buildXml([
            'invoice'         => ['amount_paid' => '150.00', 'status' => 'paid'],
            'payment_context' => 'online_invoice',
            'transaction'     => ['id' => 'TRANS-001', 'payment_method' => 'cash'],
        ]);
    }

    // ─── XML structure ───────────────────────────────────────────────────────

    public function test_produces_well_formed_xml(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $dom = new \DOMDocument();
        $this->assertNotFalse($dom->loadXML($xml), 'buildXml() must produce well-formed XML');
    }

    public function test_contains_type_payment_registered(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<type>payment_registered</type>', $xml);
    }

    public function test_contains_source_frontend(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<source>frontend</source>', $xml);
    }

    public function test_contains_version_2_0(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<version>2.0</version>', $xml);
    }

    public function test_contains_amount_paid_with_currency_attribute(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('currency="eur"', $xml);
        $this->assertStringContainsString('<amount_paid currency="eur">150.00</amount_paid>', $xml);
    }

    public function test_contains_invoice_status(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<status>paid</status>', $xml);
    }

    public function test_contains_payment_context(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringContainsString('<payment_context>online_invoice</payment_context>', $xml);
    }

    public function test_includes_identity_uuid_when_provided(): void
    {
        $data = $this->validData();
        $data['identity_uuid'] = 'b2c3d4e5-2222-4222-b222-222222222222';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('<identity_uuid>b2c3d4e5-2222-4222-b222-222222222222</identity_uuid>', $xml);
    }

    public function test_omits_identity_uuid_when_not_provided(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringNotContainsString('<identity_uuid>', $xml);
    }

    public function test_includes_invoice_id_when_provided(): void
    {
        $data = $this->validData();
        $data['invoice']['id'] = 'inv-26';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('<id>inv-26</id>', $xml);
    }

    public function test_omits_invoice_id_when_not_provided(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath  = new \DOMXPath($dom);
        $idNodes = $xpath->query('/message/body/invoice/id');
        $this->assertSame(0, $idNodes->length);
    }

    public function test_includes_due_date_when_provided(): void
    {
        $data = $this->validData();
        $data['invoice']['due_date'] = '2026-06-30';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('<due_date>2026-06-30</due_date>', $xml);
    }

    public function test_includes_transaction_when_provided(): void
    {
        $data = $this->validData();
        $data['transaction'] = ['id' => 'TRANS-ABC123', 'payment_method' => 'online'];

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('<id>TRANS-ABC123</id>', $xml);
        $this->assertStringContainsString('<payment_method>online</payment_method>', $xml);
    }

    public function test_omits_transaction_when_not_provided(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringNotContainsString('<transaction>', $xml);
    }

    public function test_does_not_contain_xmlns(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertStringNotContainsString('xmlns=', $xml);
    }

    public function test_contains_message_id_uuid_v4(): void
    {
        $xml = $this->sender->buildXml($this->validData());

        $this->assertMatchesRegularExpression(
            '/<message_id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}<\/message_id>/',
            $xml
        );
    }

    // ─── escaping ────────────────────────────────────────────────────────────

    public function test_escapes_special_chars_in_payment_context(): void
    {
        // Payment context is enum-validated so special chars can't reach XML,
        // but verify identity_uuid escaping as a proxy for the escaping mechanism.
        $data = $this->validData();
        $data['identity_uuid'] = 'id<with>&special';

        $xml = $this->sender->buildXml($data);

        $this->assertStringContainsString('&lt;with&gt;&amp;special', $xml);
    }

    // ─── send() routing ──────────────────────────────────────────────────────

    public function test_send_publishes_to_facturatie_incoming(): void
    {
        $mock = $this->createStub(RabbitMQClient::class);

        $channel = $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class);
        $channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->isInstanceOf(\PhpAmqpLib\Message\AMQPMessage::class),
                '',
                'facturatie.incoming'
            );

        $mock->method('getChannel')->willReturn($channel);

        (new PaymentRegisteredSender($mock))->send($this->validData());
    }

    public function test_send_throws_when_invoice_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $data = $this->validData();
        unset($data['invoice']);
        $this->sender->send($data);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function validData(): array
    {
        return [
            'invoice' => [
                'amount_paid' => '150.00',
                'status'      => 'paid',
            ],
            'payment_context' => 'online_invoice',
        ];
    }
}
