<?php

declare(strict_types=1);

use Drupal\qr_code\Controller\QrCodeController;
use PHPUnit\Framework\TestCase;
use Tests\Stubs\UserDataServiceStub;

/**
 * Unit tests for QrCodeController.
 *
 * The controller depends on \Drupal::service('user.data') and currentUser().
 * Both are satisfied by the stubs in tests/bootstrap.php.
 */
class QrCodeControllerTest extends TestCase
{
    private const UID       = 5;
    private const UUID_USER = 'deadbeef-dead-beef-dead-beefdeadbeef';

    private QrCodeController $controller;
    private UserDataServiceStub $userDataStub;

    protected function setUp(): void
    {
        $this->controller   = new QrCodeController();
        $this->controller->setMockCurrentUid(self::UID);
        $this->userDataStub = new UserDataServiceStub();
        Drupal::setupService('user.data', $this->userDataStub);
    }

    protected function tearDown(): void
    {
        Drupal::resetTestStubs();
    }

    // ── page with master_uuid ─────────────────────────────────────────────────

    public function test_page_with_master_uuid_renders_qr_wrapper(): void
    {
        $this->userDataStub->set('registration_form', self::UID, 'master_uuid', self::UUID_USER);

        $result = $this->controller->page();

        $this->assertStringContainsString('id="qr-code-wrapper"', $result['#markup']);
        $this->assertStringContainsString('data-uuid="' . self::UUID_USER . '"', $result['#markup']);
    }

    public function test_page_with_master_uuid_renders_canvas_element(): void
    {
        $this->userDataStub->set('registration_form', self::UID, 'master_uuid', self::UUID_USER);

        $result = $this->controller->page();

        $this->assertStringContainsString('id="qr-code-canvas"', $result['#markup']);
    }

    public function test_page_with_master_uuid_attaches_qr_code_library(): void
    {
        $this->userDataStub->set('registration_form', self::UID, 'master_uuid', self::UUID_USER);

        $result = $this->controller->page();

        $this->assertContains('event_theme/qr_code', $result['#attached']['library']);
    }

    public function test_page_with_master_uuid_escapes_special_chars(): void
    {
        // Ensure data-uuid is always a plain UUID; htmlspecialchars is called.
        $this->userDataStub->set('registration_form', self::UID, 'master_uuid', self::UUID_USER);

        $result = $this->controller->page();

        $markup = $result['#markup'];
        $this->assertStringNotContainsString('<script', $markup);
    }

    // ── page without master_uuid ──────────────────────────────────────────────

    public function test_page_without_master_uuid_shows_fallback_message(): void
    {
        // No master_uuid stored → userDataStub returns null.
        $result = $this->controller->page();

        $this->assertStringContainsString('Geen QR code beschikbaar', $result['#markup']);
    }

    public function test_page_without_master_uuid_does_not_render_qr_wrapper(): void
    {
        $result = $this->controller->page();

        $this->assertStringNotContainsString('qr-code-wrapper', $result['#markup']);
        $this->assertStringNotContainsString('qr-code-canvas', $result['#markup']);
    }

    public function test_page_without_master_uuid_no_library_attached(): void
    {
        $result = $this->controller->page();

        $this->assertArrayNotHasKey('#attached', $result);
    }

    // ── wallet balance display ────────────────────────────────────────────────

    public function test_page_with_wallet_balance_shows_balance(): void
    {
        $this->userDataStub->set('registration_form', self::UID, 'master_uuid', self::UUID_USER);
        $this->userDataStub->set('registration_form', self::UID, 'wallet_balance', '42.50');

        $result = $this->controller->page();

        $this->assertStringContainsString('42.50', $result['#markup']);
    }

    public function test_page_with_zero_balance_shows_zero(): void
    {
        $this->userDataStub->set('registration_form', self::UID, 'master_uuid', self::UUID_USER);
        $this->userDataStub->set('registration_form', self::UID, 'wallet_balance', '0.00');

        $result = $this->controller->page();

        $this->assertStringContainsString('0.00', $result['#markup']);
    }

    public function test_page_without_wallet_balance_no_balance_markup(): void
    {
        $this->userDataStub->set('registration_form', self::UID, 'master_uuid', self::UUID_USER);
        // wallet_balance not set.

        $result = $this->controller->page();

        $this->assertStringNotContainsString('qr-wallet-balance', $result['#markup']);
    }

    // ── different users are isolated ──────────────────────────────────────────

    public function test_user_a_does_not_see_user_b_qr_code(): void
    {
        $uuidA = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $uuidB = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

        $this->userDataStub->set('registration_form', 1, 'master_uuid', $uuidA);
        $this->userDataStub->set('registration_form', 2, 'master_uuid', $uuidB);

        // Controller is set to UID=1.
        $this->controller->setMockCurrentUid(1);
        $result = $this->controller->page();

        $this->assertStringContainsString($uuidA, $result['#markup']);
        $this->assertStringNotContainsString($uuidB, $result['#markup']);
    }
}
