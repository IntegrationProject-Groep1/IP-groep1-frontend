<?php

declare(strict_types=1);

namespace Drupal\session_enrollment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the post-enrollment confirmation page.
 */
class EnrollmentConfirmationController extends ControllerBase
{
    public function __construct(
        private readonly PrivateTempStoreFactory $tempStoreFactory,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('tempstore.private'),
        );
    }

    public function page(): array
    {
        $data     = $this->tempStoreFactory->get('session_enrollment')->get('confirmation') ?? [];
        $name     = (string) ($data['name'] ?? '');
        $sessions = (array) ($data['sessions'] ?? []);

        return [
            '#theme'    => 'enrollment_confirmation',
            '#name'     => $name,
            '#sessions' => $sessions,
        ];
    }
}
