<?php

declare(strict_types=1);

namespace Drupal\qr_code\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Renders the QR code page for the authenticated attendee.
 *
 * The page encodes the user's master_uuid (from the Identity Service) as a
 * QR code so they can use their phone at the kassa instead of a physical badge.
 */
class QrCodeController extends ControllerBase
{
    public function page(): array
    {
        $uid          = (int) $this->currentUser()->id();
        $userData     = \Drupal::service('user.data');
        $masterUuid   = (string) ($userData->get('registration_form', $uid, 'master_uuid') ?? '');
        $walletBalance = $userData->get('registration_form', $uid, 'wallet_balance');

        if ($masterUuid === '') {
            \Drupal::logger('qr_code')->warning(
                'QR code page: uid @uid has no master_uuid — registration with Identity Service may be incomplete.',
                ['@uid' => $uid]
            );
            return [
                '#markup' => '<div class="qr-code-page">'
                    . '<p>' . $this->t('Geen QR code beschikbaar. Voltooi eerst uw registratie.') . '</p>'
                    . '</div>',
            ];
        }

        \Drupal::logger('qr_code')->info(
            'QR code page served for uid @uid (balance: @balance).',
            ['@uid' => $uid, '@balance' => $walletBalance ?? 'none']
        );

        $balanceHtml = '';
        if ($walletBalance !== null && $walletBalance !== '') {
            $balanceHtml = '<p class="qr-wallet-balance">'
                . $this->t('Badge saldo: €@balance', ['@balance' => htmlspecialchars((string) $walletBalance, ENT_HTML5, 'UTF-8')])
                . '</p>';
        }

        return [
            '#markup' => '<div class="qr-code-page">'
                . '<div id="qr-code-wrapper" data-uuid="' . htmlspecialchars($masterUuid, ENT_HTML5, 'UTF-8') . '">'
                . '<div id="qr-code-canvas"></div>'
                . '</div>'
                . $balanceHtml
                . '<p class="qr-code-hint">' . $this->t('Toon deze QR code aan de inkom of kassa.') . '</p>'
                . '</div>',
            '#attached' => [
                'library' => ['event_theme/qr_code'],
            ],
        ];
    }
}
