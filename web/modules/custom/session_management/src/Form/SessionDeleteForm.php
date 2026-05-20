<?php

declare(strict_types=1);

namespace Drupal\session_management\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\session_management\Service\SessionService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for deleting a session (writes directly to MariaDB).
 */
class SessionDeleteForm extends FormBase
{
    public function __construct(
        private readonly SessionService $sessionService,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('session_management.session_service'),
        );
    }

    public function getFormId(): string
    {
        return 'session_management_session_delete_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state, string $session_id = ''): array
    {
        $form_state->set('session_id', $session_id);
        $session = $this->loadSession($session_id);
        $form_state->set('session', $session);

        if ($session !== null) {
            $form['session_info'] = [
                '#markup' => $this->buildSessionSummaryHtml($session),
                '#weight' => -10,
            ];
        }

        $form['warning'] = [
            '#markup' => '<div class="admin-delete-warning"><svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg><p>' . $this->t('This will send a delete request to the Planning service. The action cannot be undone.') . '</p></div>',
            '#weight' => -5,
        ];

        $form['reason'] = [
            '#type'        => 'textfield',
            '#title'       => $this->t('Reason for deletion'),
            '#required'    => false,
            '#maxlength'   => 500,
            '#description' => $this->t('Optional. Will be included in the delete request to Planning.'),
        ];

        $form['actions'] = ['#type' => 'actions'];

        $form['actions']['submit'] = [
            '#type'       => 'submit',
            '#value'      => $this->t('Delete session'),
            '#attributes' => ['class' => ['button', 'button--danger']],
        ];

        $form['actions']['cancel'] = [
            '#type'       => 'link',
            '#title'      => $this->t('Cancel'),
            '#url'        => Url::fromRoute('session_management.admin'),
            '#attributes' => ['class' => ['button', 'button--ghost']],
        ];

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $sessionId = (string) $form_state->get('session_id');
        $session   = $form_state->get('session');

        $data = ['session_id' => $sessionId];

        $reason = trim((string) $form_state->getValue('reason'));
        if ($reason !== '') {
            $data['reason'] = $reason;
        }

        try {
            $this->sessionService->deleteSession($sessionId, $reason);
            $title = $session['title'] ?? $sessionId;
            $this->messenger()->addStatus($this->t('Session "@title" has been deleted.', ['@title' => $title]));
        } catch (\Throwable $e) {
            $this->messenger()->addError($this->t('Failed to delete session: @error', ['@error' => $e->getMessage()]));
        }

        $form_state->setRedirectUrl(Url::fromRoute('session_management.admin'));
    }

    private function loadSession(string $sessionId): ?array
    {
        if ($sessionId === '') {
            return null;
        }
        return $this->sessionService->loadSession($sessionId);
    }

    private function buildSessionSummaryHtml(array $s): string
    {
        $title    = htmlspecialchars($s['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $type     = htmlspecialchars($s['session_type'] ?? '—', ENT_QUOTES, 'UTF-8');
        $start    = htmlspecialchars($s['start_datetime'] ?? '—', ENT_QUOTES, 'UTF-8');
        $end      = htmlspecialchars($s['end_datetime'] ?? '—', ENT_QUOTES, 'UTF-8');
        $location = htmlspecialchars($s['location'] ?? '—', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div class="session-delete-summary">
  <div class="session-delete-summary-row"><span>Title</span><strong>{$title}</strong></div>
  <div class="session-delete-summary-row"><span>Type</span><strong>{$type}</strong></div>
  <div class="session-delete-summary-row"><span>Start</span><strong>{$start}</strong></div>
  <div class="session-delete-summary-row"><span>End</span><strong>{$end}</strong></div>
  <div class="session-delete-summary-row"><span>Location</span><strong>{$location}</strong></div>
</div>
HTML;
    }
}
