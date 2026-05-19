<?php

declare(strict_types=1);

use Drupal\rabbitmq_receiver\BadgeScannedReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;
use Drupal\rabbitmq_sender\UserCheckinSender;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\DatabaseQueryBuilderStub;

/**
 * Unit tests for BadgeScannedReceiver.
 *
 * Covers the xs:choice contract: body must contain either badge_id (physical
 * badge, existing flow) or identity_uuid (QR scan, new flow), never both.
 */
class BadgeScannedReceiverTest extends TestCase
{
    private const UUID_MSG  = '550e8400-e29b-41d4-a716-446655440001';
    private const UUID_USER = 'e8b27c1d-4f2a-4b3e-9c5f-123456789abc';
    private const UID       = 42;

    private BadgeScannedReceiver $receiver;

    protected function setUp(): void
    {
        $this->receiver = new BadgeScannedReceiver($this->createStub(RabbitMQClient::class));
        Drupal::resetTestStubs();
    }

    protected function tearDown(): void
    {
        Drupal::resetTestStubs();
    }

    // ── badge_id path (existing behaviour) ──────────────────────────────────

    public function test_badge_id_valid_xml_returns_true(): void
    {
        $result = $this->receiver->processMessageFromXml($this->badgeXml('BADGE-001'));
        $this->assertTrue($result);
    }

    public function test_badge_id_with_sender_provided_sender_is_not_called(): void
    {
        $sender = $this->createMock(UserCheckinSender::class);
        $sender->expects($this->never())->method('send');

        $receiver = new BadgeScannedReceiver(
            $this->createStub(RabbitMQClient::class),
            $sender,
        );

        $receiver->processMessageFromXml($this->badgeXml('BADGE-001'));
    }

    public function test_badge_id_all_valid_locations_accepted(): void
    {
        foreach (['entrance', 'bar', 'main_bar', 'session'] as $location) {
            $result = $this->receiver->processMessageFromXml($this->badgeXml('B-001', $location));
            $this->assertTrue($result, "Expected true for location '{$location}'");
        }
    }

    public function test_badge_id_invalid_location_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml($this->badgeXml('B-001', 'lobby'));
    }

    public function test_badge_id_invalid_source_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml(
            $this->badgeXml('B-001', 'entrance', 'frontend')
        );
    }

    public function test_badge_id_source_kassa_is_valid(): void
    {
        $result = $this->receiver->processMessageFromXml(
            $this->badgeXml('B-001', 'entrance', 'kassa')
        );
        $this->assertTrue($result);
    }

    public function test_badge_id_missing_location_throws(): void
    {
        $this->expectException(\Exception::class);
        $xml = $this->buildRawXml(self::UUID_MSG, 'iot_gateway', '<badge_id>B-001</badge_id>', '');
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_badge_id_missing_scanned_at_throws(): void
    {
        $this->expectException(\Exception::class);
        $xml = $this->buildRawXml(self::UUID_MSG, 'iot_gateway', '<badge_id>B-001</badge_id>', '<location>entrance</location>');
        $this->receiver->processMessageFromXml($xml);
    }

    // ── identity_uuid path (QR code, new behaviour) ─────────────────────────

    public function test_identity_uuid_valid_xml_calls_sender(): void
    {
        Drupal::setupDatabase(new DatabaseQueryBuilderStub($this->dbRowFor(self::UUID_USER, self::UID)));

        $sender = $this->createMock(UserCheckinSender::class);
        $sender->expects($this->once())->method('send');

        $receiver = new BadgeScannedReceiver(
            $this->createStub(RabbitMQClient::class),
            $sender,
        );

        $receiver->processMessageFromXml($this->identityXml(self::UUID_USER));
    }

    public function test_identity_uuid_sender_called_with_correct_payload(): void
    {
        Drupal::setupDatabase(new DatabaseQueryBuilderStub($this->dbRowFor(self::UUID_USER, self::UID)));

        $scannedAt = '2026-05-15T18:06:35Z';
        $sender    = $this->createMock(UserCheckinSender::class);
        $sender->expects($this->once())
            ->method('send')
            ->with($this->callback(function (array $data) use ($scannedAt): bool {
                return $data['user_id']    === self::UUID_USER
                    && $data['badge_id']   === self::UUID_USER
                    && $data['checkin_at'] === $scannedAt;
            }));

        $receiver = new BadgeScannedReceiver(
            $this->createStub(RabbitMQClient::class),
            $sender,
        );

        $receiver->processMessageFromXml($this->identityXml(self::UUID_USER, 'entrance', $scannedAt));
    }

    public function test_identity_uuid_user_not_found_throws(): void
    {
        // DB returns no rows → findUidByMasterUuid returns null.
        Drupal::setupDatabase(new DatabaseQueryBuilderStub([]));

        $receiver = new BadgeScannedReceiver(
            $this->createStub(RabbitMQClient::class),
            $this->createStub(UserCheckinSender::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No user found for identity_uuid/');
        $receiver->processMessageFromXml($this->identityXml(self::UUID_USER));
    }

    public function test_identity_uuid_without_sender_returns_true(): void
    {
        // Sender is null — should not call findUidByMasterUuid at all.
        $receiver = new BadgeScannedReceiver($this->createStub(RabbitMQClient::class), null);

        // No DB stub configured — if findUidByMasterUuid were called it would throw.
        $result = $receiver->processMessageFromXml($this->identityXml(self::UUID_USER));
        $this->assertTrue($result);
    }

    public function test_identity_uuid_db_row_mismatch_throws(): void
    {
        // DB returns a row, but it belongs to a different UUID.
        $differentUuid = 'aaaabbbb-cccc-dddd-eeee-ffffaaaabbbb';
        Drupal::setupDatabase(new DatabaseQueryBuilderStub($this->dbRowFor($differentUuid, 99)));

        $receiver = new BadgeScannedReceiver(
            $this->createStub(RabbitMQClient::class),
            $this->createStub(UserCheckinSender::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $receiver->processMessageFromXml($this->identityXml(self::UUID_USER));
    }

    public function test_identity_uuid_invalid_format_throws(): void
    {
        // 'not-a-uuid' fails the XSD UUIDType pattern.
        $this->expectException(\Exception::class);
        $xml = $this->buildRawXml(
            self::UUID_MSG,
            'iot_gateway',
            '<identity_uuid>not-a-uuid</identity_uuid>',
            '<location>entrance</location><scanned_at>2026-05-15T18:06:35Z</scanned_at>'
        );
        $this->receiver->processMessageFromXml($xml);
    }

    // ── general validation ───────────────────────────────────────────────────

    public function test_invalid_xml_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml('this is not xml at all');
    }

    public function test_truncated_xml_throws(): void
    {
        // '<' alone is malformed XML — loadXML() returns false → exception.
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml('<message><header>');
    }

    public function test_missing_header_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml(
            '<?xml version="1.0" encoding="UTF-8"?><message><body><badge_id>B-001</badge_id><location>entrance</location><scanned_at>2026-05-15T18:06:35Z</scanned_at></body></message>'
        );
    }

    public function test_wrong_type_in_header_throws(): void
    {
        $this->expectException(\Exception::class);
        $xml = $this->buildRawXmlFull(self::UUID_MSG, 'iot_gateway', 'wrong_type', '<badge_id>B-001</badge_id>', '<location>entrance</location><scanned_at>2026-05-15T18:06:35Z</scanned_at>');
        $this->receiver->processMessageFromXml($xml);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function badgeXml(
        string $badgeId,
        string $location = 'entrance',
        string $source   = 'iot_gateway',
        string $scannedAt = '2026-05-15T18:06:35Z',
    ): string {
        return $this->buildRawXml(
            self::UUID_MSG,
            $source,
            "<badge_id>{$badgeId}</badge_id>",
            "<location>{$location}</location><scanned_at>{$scannedAt}</scanned_at>"
        );
    }

    private function identityXml(
        string $identityUuid,
        string $location  = 'entrance',
        string $scannedAt = '2026-05-15T18:06:35Z',
        string $source    = 'iot_gateway',
    ): string {
        return $this->buildRawXml(
            self::UUID_MSG,
            $source,
            "<identity_uuid>{$identityUuid}</identity_uuid>",
            "<location>{$location}</location><scanned_at>{$scannedAt}</scanned_at>"
        );
    }

    private function buildRawXml(
        string $msgId,
        string $source,
        string $choiceElement,
        string $afterChoice,
    ): string {
        return $this->buildRawXmlFull($msgId, $source, 'badge_scanned', $choiceElement, $afterChoice);
    }

    private function buildRawXmlFull(
        string $msgId,
        string $source,
        string $type,
        string $choiceElement,
        string $afterChoice,
    ): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message>'
            . '<header>'
            . "<message_id>{$msgId}</message_id>"
            . '<timestamp>2026-05-15T18:06:35Z</timestamp>'
            . "<source>{$source}</source>"
            . "<type>{$type}</type>"
            . '<version>2.0</version>'
            . '</header>'
            . '<body>'
            . $choiceElement
            . $afterChoice
            . '</body>'
            . '</message>';
    }

    /**
     * Build a DB row as a stdClass matching the users_data table structure.
     * The value column stores serialize(master_uuid).
     *
     * @return array<int, \stdClass>
     */
    private function dbRowFor(string $masterUuid, int $uid): array
    {
        $row        = new \stdClass();
        $row->uid   = $uid;
        $row->value = serialize($masterUuid);
        return [$row];
    }
}
