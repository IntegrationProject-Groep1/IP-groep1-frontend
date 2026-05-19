<?php

declare(strict_types=1);

namespace Drupal\qr_code\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;

/**
 * Renders the QR code page for the authenticated attendee.
 */
class QrCodeController extends ControllerBase
{
    public function page(): array
    {
        $uid       = (int) $this->currentUser()->id();
        $userData  = \Drupal::service('user.data');
        $masterUuid  = (string) ($userData->get('registration_form', $uid, 'master_uuid') ?? '');
        $walletBalance = $userData->get('registration_form', $uid, 'wallet_balance');

        // Load display name from profile fields.
        $fullUser  = User::load($uid);
        $firstName = $fullUser && $fullUser->hasField('field_first_name')
            ? (string) $fullUser->get('field_first_name')->value : '';
        $lastName  = $fullUser && $fullUser->hasField('field_last_name')
            ? (string) $fullUser->get('field_last_name')->value : '';
        $company   = $fullUser && $fullUser->hasField('field_company')
            ? (string) $fullUser->get('field_company')->value : '';
        $email = $fullUser ? (string) $fullUser->getEmail() : '';
        $emailFallback = ucwords(str_replace(['.', '_', '-'], ' ', strtok($email ?: $this->currentUser()->getDisplayName(), '@')));
        $displayName = trim("$firstName $lastName") ?: $emailFallback;
        $roleLabel   = $company ?: (string) $this->t('Deelnemer');

        $e = fn(string $v): string => htmlspecialchars($v, ENT_HTML5, 'UTF-8');
        $shortId = $uid > 0 ? 'BDG-' . str_pad((string) $uid, 4, '0', STR_PAD_LEFT) : 'BDG-????';

        if ($masterUuid === '') {
            \Drupal::logger('qr_code')->warning(
                'QR code page: uid @uid has no master_uuid.',
                ['@uid' => $uid]
            );
            $noQrHtml = '
<div class="badge-page badge-page--single">
  <div class="card badge-page-card" style="max-width:480px;margin:60px auto;text-align:center;padding:40px;">
    <div class="badge-page-hd">
      <span class="eyebrow">' . $this->t('Festivalbadge') . '</span>
      <h1>' . $this->t('Geen badge beschikbaar') . '</h1>
      <p>' . $this->t('Jouw registratie is nog niet volledig verwerkt. Probeer het later opnieuw of neem contact op met de helpdesk.') . '</p>
    </div>
  </div>
</div>';
            return [
                '#markup' => \Drupal\Core\Render\Markup::create($noQrHtml),
                '#attached' => ['library' => ['event_theme/qr_code']],
                '#cache' => [
                    'contexts' => ['user'],
                    'tags' => $fullUser ? $fullUser->getCacheTags() : [],
                ],
            ];
        }

        $email        = $fullUser ? (string) ($fullUser->getEmail() ?? '') : '';

        \Drupal::logger('qr_code')->info(
            'QR code page served for uid @uid.',
            ['@uid' => $uid]
        );

        $balanceAction = '';
        if ($walletBalance !== null && $walletBalance !== '') {
            $balanceAction = '
          <div class="badge-action">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            <div>
              <strong>' . $this->t('Wallet saldo') . '</strong>
              <span>€' . $e((string) $walletBalance) . ' ' . $this->t('beschikbaar') . '</span>
            </div>
          </div>';
        }

        $html = '
<div class="badge-page">

  <div class="card badge-page-card">
    <div class="badge-page-hd">
      <span class="eyebrow">' . $this->t('Jouw QR badge') . '</span>
      <h1>' . $this->t('Scan aan de kassa om te betalen met je QR code.') . '</h1>
      <p>' . $this->t('Deze QR code is nodig om je te identificeren bij de kassa.') . '</p>
    </div>

    <div class="badge-display">
      <div class="badge-display-strap"></div>
      <div class="badge-display-card">
        <div class="badge-display-top">
          <span>SHIFT · 2026</span>
          <span>' . $e($shortId) . '</span>
        </div>
        <div class="badge-display-name">' . $e($displayName) . '</div>
        <div class="badge-display-role">' . $e($roleLabel) . '</div>
        <div class="badge-display-qr" id="qr-code-wrapper" data-uuid="' . $e($masterUuid) . '" data-email="' . $e($email) . '">
          <div id="qr-code-canvas"></div>
        </div>
      </div>
    </div>

    <div class="badge-actions">
      ' . $balanceAction . '
      <div class="badge-action" style="width:100%;">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        <div>
          <strong>' . $this->t('Identiteit geverifieerd') . '</strong>
          <span>' . $this->t('Uitgegeven door de Identity Service') . '</span>
        </div>
      </div>
    </div>
  </div>

  <div class="card badge-help">
    <p class="section-label">' . $this->t('Hoe te gebruiken') . '</p>
    <ol class="how-list">
      <li><span>1</span> ' . $this->t('Houd je telefoonscherm omhoog onder de scanner. Maximale helderheid helpt.') . '</li>
      <li><span>2</span> ' . $this->t('Luister naar het geluid. Groen = toegang verleend, oranje = personeel assisteert.') . '</li>
      <li><span>3</span> ' . $this->t('Telefoon kwijt? Bezoek de helpdesk in het Atrium met een geldig ID.') . '</li>
    </ol>
  </div>

</div>';

        return [
            '#markup' => \Drupal\Core\Render\Markup::create($html),
            '#attached' => ['library' => ['event_theme/qr_code']],
            '#cache' => [
                'contexts' => ['user'],
                'tags' => $fullUser->getCacheTags(),
            ],
        ];
    }
}
