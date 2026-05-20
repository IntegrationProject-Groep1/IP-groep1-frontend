<?php

declare(strict_types=1);

namespace Drupal\registration_form\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects pending company users to the "account in review" page.
 *
 * Every request is checked. If the logged-in user is a company whose
 * registration has not yet been approved, they are redirected to
 * /company/pending — unless they are already there or logging out.
 */
class CompanyApprovalRedirectSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_PATHS = [
        '/company/pending',
        '/user/logout',
        '/user/login',
    ];

    public function __construct(
        private readonly AccountProxyInterface $currentUser,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priority 31 — runs after authentication (30) but before controller dispatch.
        return [KernelEvents::REQUEST => ['onRequest', 31]];
    }

    public function onRequest(RequestEvent $event): void
    {
        // Only act on the main request (not sub-requests for embedded blocks, etc.).
        if (!$event->isMainRequest()) {
            return;
        }

        $account = $this->currentUser;
        if ($account->isAnonymous()) {
            return;
        }

        $uid = (int) $account->id();

        $status = \Drupal::service('user.data')
            ->get('registration_form', $uid, 'company_approval_status');

        if ($status !== 'pending') {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        foreach (self::ALLOWED_PATHS as $allowed) {
            if (str_starts_with($path, $allowed)) {
                return;
            }
        }

        // Also allow admin paths so a super-admin account is never locked out.
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/user/')) {
            $roles = $account->getRoles();
            if (in_array('administrator', $roles, true)) {
                return;
            }
        }

        $pendingUrl = \Drupal::request()->getSchemeAndHttpHost() . '/company/pending';
        $event->setResponse(new RedirectResponse($pendingUrl, 302));
    }
}
