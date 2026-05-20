<?php

declare(strict_types=1);

namespace Drupal\registration_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\registration_form\Service\RegistrationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Admin page: list pending company registrations and approve/reject them.
 */
class CompanyApprovalController extends ControllerBase
{
    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly Connection $database,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('registration_form.registration_service'),
            $container->get('database'),
        );
    }

    /**
     * Renders the list of pending company registrations.
     */
    public function listPending(): array
    {
        // Query the users_data table for all UIDs with company_approval_status = 'pending'.
        // Drupal's user.data service stores scalar strings as-is (no serialization).
        $uids = $this->database->select('users_data', 'ud')
            ->fields('ud', ['uid'])
            ->condition('ud.module', 'registration_form')
            ->condition('ud.name', 'company_approval_status')
            ->condition('ud.value', 'pending')
            ->execute()
            ->fetchCol();

        $companies = [];
        foreach ($uids as $uid) {
            $uid  = (int) $uid;
            $user = $this->entityTypeManager()->getStorage('user')->load($uid);
            if ($user === null) {
                continue;
            }

            $userData    = \Drupal::service('user.data');
            $pendingData = $userData->get('registration_form', $uid, 'company_pending_data') ?? [];

            $companies[] = [
                'uid'          => $uid,
                'email'        => $user->getEmail(),
                'company_name' => (string) ($pendingData['company_name'] ?? $userData->get('registration_form', $uid, 'company_name') ?? ''),
                'vat_number'   => (string) ($pendingData['vat_number']   ?? $userData->get('registration_form', $uid, 'vat_number')   ?? ''),
                'street'       => (string) ($pendingData['street']       ?? ''),
                'postal_code'  => (string) ($pendingData['postal_code']  ?? ''),
                'municipality' => (string) ($pendingData['municipality'] ?? ''),
                'created'      => $user->getCreatedTime(),
                'approve_url'  => Url::fromRoute('registration_form.company_approve', ['uid' => $uid])->toString(),
                'reject_url'   => Url::fromRoute('registration_form.company_reject',  ['uid' => $uid])->toString(),
            ];
        }

        return [
            '#theme'     => 'company_approval_list',
            '#companies' => $companies,
            '#cache'     => ['max-age' => 0],
        ];
    }

    /**
     * Approves a single pending company and redirects back to the list.
     */
    public function approve(int $uid): RedirectResponse
    {
        try {
            $this->registrationService->approveCompany($uid);
            $this->messenger()->addStatus($this->t('Bedrijfsregistratie goedgekeurd.'));
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($this->t('Goedkeuring mislukt: @msg', ['@msg' => $e->getMessage()]));
        }

        return new RedirectResponse(Url::fromRoute('registration_form.company_approvals')->toString());
    }

    /**
     * Rejects a single pending company (deletes the Drupal user) and redirects back.
     */
    public function reject(int $uid): RedirectResponse
    {
        try {
            $this->registrationService->rejectCompany($uid);
            $this->messenger()->addStatus($this->t('Bedrijfsregistratie geweigerd en gebruiker verwijderd.'));
        } catch (\InvalidArgumentException $e) {
            $this->messenger()->addError($this->t('Weigering mislukt: @msg', ['@msg' => $e->getMessage()]));
        }

        return new RedirectResponse(Url::fromRoute('registration_form.company_approvals')->toString());
    }
}
