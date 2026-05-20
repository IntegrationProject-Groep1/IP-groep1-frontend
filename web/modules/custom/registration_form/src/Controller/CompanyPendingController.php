<?php

declare(strict_types=1);

namespace Drupal\registration_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Renders the "company account in review" page for pending companies.
 */
class CompanyPendingController extends ControllerBase
{
    public function page(): array|RedirectResponse
    {
        $uid = (int) $this->currentUser()->id();

        // If the user is anonymous or no longer pending, send them to the front.
        if ($uid === 0) {
            return new RedirectResponse(Url::fromRoute('<front>')->toString());
        }

        $status = \Drupal::service('user.data')
            ->get('registration_form', $uid, 'company_approval_status');

        if ($status === 'approved') {
            return new RedirectResponse(Url::fromRoute('<front>')->toString());
        }

        if ($status === null || ($status !== 'pending' && $status !== 'rejected')) {
            return new RedirectResponse(Url::fromRoute('<front>')->toString());
        }

        $companyName = (string) (\Drupal::service('user.data')
            ->get('registration_form', $uid, 'company_name') ?? '');

        return [
            '#theme'        => 'company_pending',
            '#status'       => $status,
            '#company_name' => $companyName,
            '#cache'        => ['max-age' => 0],
        ];
    }
}
