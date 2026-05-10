<?php

declare(strict_types=1);

use Drupal\rabbitmq_receiver\WalletBalanceReceiver;
use Drupal\rabbitmq_sender\RabbitMQClient;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\DatabaseQueryBuilderStub;
use Tests\Stubs\UserDataServiceStub;

/**
 * Unit tests for WalletBalanceReceiver.
 *
 * Validates XSD-checked parsing, user lookup via master_uuid, and
 * storage of the balance via Drupal's user.data service.
 */
class WalletBalanceReceiverTest extends TestCase
{
    private const UUID_MSG  = '550e8400-e29b-41d4-a716-446655440002';
    private const UUID_USER = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
    private const UID       = 77;

    private WalletBalanceReceiver $receiver;
    private UserDataServiceStub $userDataStub;

    protected function setUp(): void
    {
        $this->receiver     = new WalletBalanceReceiver($this->createStub(RabbitMQClient::class));
        $this->userDataStub = new UserDataServiceStub();

        Drupal::setupDatabase(new DatabaseQueryBuilderStub($this->dbRowFor(self::UUID_USER, self::UID)));
        Drupal::setupService('user.data', $this->userDataStub);
    }

    protected function tearDown(): void
    {
        Drupal::resetTestStubs();
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function test_valid_xml_returns_true(): void
    {
        $result = $this->receiver->processMessageFromXml($this->walletXml(self::UUID_USER, '50.00'));
        $this->assertTrue($result);
    }

    public function test_valid_xml_stores_balance_for_correct_uid(): void
    {
        $this->receiver->processMessageFromXml($this->walletXml(self::UUID_USER, '75.50'));

        $stored = $this->userDataStub->stored["registration_form:" . self::UID . ":wallet_balance"] ?? null;
        $this->assertSame('75.50', $stored);
    }

    public function test_zero_balance_is_stored(): void
    {
        $this->receiver->processMessageFromXml($this->walletXml(self::UUID_USER, '0.00'));

        $stored = $this->userDataStub->stored["registration_form:" . self::UID . ":wallet_balance"] ?? null;
        $this->assertSame('0.00', $stored);
    }

    public function test_large_balance_stored_as_string(): void
    {
        $this->receiver->processMessageFromXml($this->walletXml(self::UUID_USER, '9999.99'));

        $stored = $this->userDataStub->stored["registration_form:" . self::UID . ":wallet_balance"] ?? null;
        $this->assertSame('9999.99', $stored);
    }

    public function test_source_crm_is_valid(): void
    {
        $result = $this->receiver->processMessageFromXml(
            $this->walletXml(self::UUID_USER, '10.00', 'crm')
        );
        $this->assertTrue($result);
    }

    // ── user lookup ───────────────────────────────────────────────────────────

    public function test_user_not_found_throws(): void
    {
        Drupal::resetTestStubs();
        Drupal::setupDatabase(new DatabaseQueryBuilderStub([]));
        Drupal::setupService('user.data', $this->userDataStub);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No user found for identity_uuid/');
        $this->receiver->processMessageFromXml($this->walletXml(self::UUID_USER, '20.00'));
    }

    public function test_db_row_uuid_mismatch_throws(): void
    {
        $differentUuid = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
        Drupal::resetTestStubs();
        Drupal::setupDatabase(new DatabaseQueryBuilderStub($this->dbRowFor($differentUuid, 99)));
        Drupal::setupService('user.data', $this->userDataStub);

        $this->expectException(\InvalidArgumentException::class);
        $this->receiver->processMessageFromXml($this->walletXml(self::UUID_USER, '20.00'));
    }

    public function test_correct_uid_is_used_for_storage(): void
    {
        // Two rows: only the second matches.
        $wrongRow         = new \stdClass();
        $wrongRow->uid    = 1;
        $wrongRow->value  = serialize('00000000-0000-0000-0000-000000000000');
        $correctRow       = new \stdClass();
        $correctRow->uid  = self::UID;
        $correctRow->value = serialize(self::UUID_USER);

        Drupal::resetTestStubs();
        Drupal::setupDatabase(new DatabaseQueryBuilderStub([$wrongRow, $correctRow]));
        Drupal::setupService('user.data', $this->userDataStub);

        $this->receiver->processMessageFromXml($this->walletXml(self::UUID_USER, '30.00'));

        $this->assertArrayHasKey(
            'registration_form:' . self::UID . ':wallet_balance',
            $this->userDataStub->stored
        );
        $this->assertArrayNotHasKey(
            'registration_form:1:wallet_balance',
            $this->userDataStub->stored
        );
    }

    // ── XSD / structural validation ───────────────────────────────────────────

    public function test_invalid_xml_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml('not xml');
    }

    public function test_truncated_xml_throws(): void
    {
        // Incomplete XML — loadXML() returns false → exception.
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml('<message><header>');
    }

    public function test_invalid_identity_uuid_format_throws(): void
    {
        // 'bad-uuid' does not match UUIDType pattern → XSD validation fails.
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml(
            $this->walletXmlRaw(self::UUID_MSG, 'kassa', 'bad-uuid', '50.00')
        );
    }

    public function test_invalid_source_throws(): void
    {
        // Only 'crm' and 'kassa' are valid sources in the XSD.
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml(
            $this->walletXml(self::UUID_USER, '50.00', 'frontend')
        );
    }

    public function test_wrong_currency_throws(): void
    {
        // The XSD fixes currency="eur"; any other value fails validation.
        $this->expectException(\Exception::class);
        $xml = $this->buildRawXml(
            self::UUID_MSG,
            'kassa',
            self::UUID_USER,
            '<wallet_balance currency="usd">50.00</wallet_balance>'
        );
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_non_decimal_balance_throws(): void
    {
        // xs:decimal requires a numeric value.
        $this->expectException(\Exception::class);
        $xml = $this->buildRawXml(
            self::UUID_MSG,
            'kassa',
            self::UUID_USER,
            '<wallet_balance currency="eur">fifty</wallet_balance>'
        );
        $this->receiver->processMessageFromXml($xml);
    }

    public function test_missing_header_throws(): void
    {
        $this->expectException(\Exception::class);
        $this->receiver->processMessageFromXml(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message><body>'
            . '<identity_uuid>' . self::UUID_USER . '</identity_uuid>'
            . '<wallet_balance currency="eur">50.00</wallet_balance>'
            . '</body></message>'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function walletXml(
        string $identityUuid,
        string $balance,
        string $source = 'kassa',
    ): string {
        return $this->buildRawXml(
            self::UUID_MSG,
            $source,
            $identityUuid,
            "<wallet_balance currency=\"eur\">{$balance}</wallet_balance>"
        );
    }

    private function walletXmlRaw(
        string $msgId,
        string $source,
        string $rawIdentityUuid,
        string $balance,
    ): string {
        return $this->buildRawXml($msgId, $source, $rawIdentityUuid, "<wallet_balance currency=\"eur\">{$balance}</wallet_balance>");
    }

    private function buildRawXml(
        string $msgId,
        string $source,
        string $identityUuid,
        string $walletBalanceElement,
    ): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<message>'
            . '<header>'
            . "<message_id>{$msgId}</message_id>"
            . '<timestamp>2026-05-15T18:06:35Z</timestamp>'
            . "<source>{$source}</source>"
            . '<type>wallet_balance_update</type>'
            . '<version>2.0</version>'
            . '</header>'
            . '<body>'
            . "<identity_uuid>{$identityUuid}</identity_uuid>"
            . $walletBalanceElement
            . '</body>'
            . '</message>';
    }

    /**
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
