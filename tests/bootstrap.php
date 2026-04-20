<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: load Composer autoloader and define minimal Drupal/PSR stubs
 * so that unit tests can run without a full Drupal installation.
 */

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// ─── PSR-3 Logger ────────────────────────────────────────────────────────

namespace Psr\Log {
    if (!interface_exists('Psr\Log\LoggerInterface', false)) {
        interface LoggerInterface
        {
            public function emergency(string|\Stringable $message, array $context = []): void;
            public function alert(string|\Stringable $message, array $context = []): void;
            public function critical(string|\Stringable $message, array $context = []): void;
            public function error(string|\Stringable $message, array $context = []): void;
            public function warning(string|\Stringable $message, array $context = []): void;
            public function notice(string|\Stringable $message, array $context = []): void;
            public function info(string|\Stringable $message, array $context = []): void;
            public function debug(string|\Stringable $message, array $context = []): void;
            public function log($level, string|\Stringable $message, array $context = []): void;
        }
    }
}

// ─── Drupal Core Logger ──────────────────────────────────────────────────────

namespace Drupal\Core\Logger {
    if (!interface_exists('Drupal\Core\Logger\LoggerChannelInterface', false)) {
        interface LoggerChannelInterface extends \Psr\Log\LoggerInterface {}
    }

    if (!interface_exists('Drupal\Core\Logger\LoggerChannelFactoryInterface', false)) {
        interface LoggerChannelFactoryInterface
        {
            public function get(string $channel): LoggerChannelInterface;
        }
    }
}

// ─── Drupal Core State ───────────────────────────────────────────────────────

namespace Drupal\Core\State {
    if (!interface_exists('Drupal\Core\State\StateInterface', false)) {
        interface StateInterface
        {
            public function get(string $key, mixed $default = null): mixed;
            public function set(string $key, mixed $value): void;
            public function delete(string $key): void;
        }
    }
}

// ─── Drupal Core Entity ──────────────────────────────────────────────────────

namespace Drupal\Core\Entity {
    if (!interface_exists('Drupal\Core\Entity\EntityStorageInterface', false)) {
        interface EntityStorageInterface
        {
            public function loadByProperties(array $values = []): array;
            public function load(mixed $id): mixed;
        }
    }
    if (!interface_exists('Drupal\Core\Entity\EntityTypeManagerInterface', false)) {
        interface EntityTypeManagerInterface
        {
            public function getStorage(string $entity_type_id): EntityStorageInterface;
        }
    }
    if (!interface_exists('Drupal\Core\Entity\EntityInterface', false)) {
        interface EntityInterface {}
    }
}
