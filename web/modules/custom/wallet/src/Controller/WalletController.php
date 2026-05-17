<?php

declare(strict_types=1);

namespace Drupal\wallet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;

/**
 * Displays the festival wallet page for the current attendee.
 */
class WalletController extends ControllerBase
{
    public function page(): array
    {
        $uid      = (int) $this->currentUser()->id();
        $userData = \Drupal::service('user.data');
        $balance  = $userData->get('registration_form', $uid, 'wallet_balance') ?? '0.00';

        // Use stored transaction history when available, otherwise show empty list.
        $transactions = $userData->get('registration_form', $uid, 'wallet_transactions') ?? [];

        return [
            '#theme'        => 'wallet_page',
            '#balance'      => $balance,
            '#transactions' => $transactions,
            '#cache'        => ['contexts' => ['user'], 'max-age' => 0],
        ];
    }
}
