<?php

declare(strict_types=1);

namespace Drupal\registration_form\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Access check for the company dashboard.
 */
final class CompanyDashboardAccess
{
    public static function access(AccountInterface $account): AccessResult
    {
        if ($account->isAnonymous()) {
            return AccessResult::forbidden();
        }

        $user = \Drupal::entityTypeManager()->getStorage('user')->load($account->id());
        if ($user === null || !$user->hasField('field_is_company')) {
            return AccessResult::forbidden()->cachePerUser();
        }

        return AccessResult::allowedIf((bool) $user->get('field_is_company')->value)
            ->cachePerUser();
    }
}
