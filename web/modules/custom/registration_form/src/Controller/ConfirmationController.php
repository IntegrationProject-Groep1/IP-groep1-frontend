<?php

declare(strict_types=1);

namespace Drupal\registration_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the post-registration confirmation page.
 */
class ConfirmationController extends ControllerBase
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
        $data = $this->tempStoreFactory->get('registration_form')->get('confirmation') ?? [];
        $session = (string) ($data['session'] ?? '');
        $name = (string) ($data['name'] ?? '');

        return [
            '#theme'   => 'registration_confirmation',
            '#name'    => $name,
            '#session' => $session,
        ];
    }
}
