# IP-Groep1-Frontend

The frontend team's repository for the Desideriushogeschool Event Platform — an integration project where multiple systems communicate via RabbitMQ to manage event registrations, sessions, invoicing, and more.

## Tech Stack

- **CMS**: Drupal 10 (Apache)
- **Language**: PHP 8.2+
- **Message Broker**: RabbitMQ (AMQP via `php-amqplib`)
- **Containerization**: Docker
- **CI/CD**: GitHub Actions
- **Testing**: PHPUnit 13

## Project Structure

```
├── .github/workflows/
│   ├── ci.yml              # CI pipeline (runs tests on push/PR)
│   └── deploy.yml          # CD pipeline (builds & pushes Docker image on release tag)
├── tests/Unit/
│   ├── RabbitMQClientTest.php
│   ├── RetryTraitTest.php
│   ├── SessionUpdateReceiverTest.php
│   ├── UserCheckinSenderTest.php
│   └── UserRegisteredSenderTest.php
├── web/modules/custom/
│   ├── rabbitmq_receiver/src/
│   │   └── SessionUpdateReceiver.php   # Listens for session updates from Planning
│   └── rabbitmq_sender/src/
│       ├── RabbitMQClient.php          # AMQP connection wrapper
│       ├── RetryTrait.php              # Retry logic for failed messages
│       ├── UserCheckinSender.php       # Sends check-in events to other systems
│       └── UserRegisteredSender.php    # Sends registration events to CRM
├── .env.example
├── Dockerfile
├── composer.json
└── composer.lock
```

## Getting Started

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- Git

### Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/IntegrationProject-Groep1/IP-groep1-frontend.git
   cd IP-groep1-frontend
   ```

2. Create a `.env` file based on the example:
   ```bash
   cp .env.example .env
   ```

3. Fill in your `.env` values (RabbitMQ credentials come from Infra):
   ```
   FRONTEND_HTTP_PORT=30020

   RABBITMQ_HOST=<server-address>
   RABBITMQ_PORT=30000
   RABBITMQ_USER=<username>
   RABBITMQ_PASS=<password>
   RABBITMQ_VHOST=/
   RABBITMQ_PREFIX=frontend.

   DRUPAL_DB_HOST=frontend_db
   DRUPAL_DB_NAME=drupal
   DRUPAL_DB_USER=drupal_user
   DRUPAL_DB_PASS=<db-password>
   DRUPAL_DB_ROOT_PASS=<db-root-password>
   ```
   Use plain AMQP on port `30000` (no SSL) to avoid RabbitMQ `bad_header` errors.

4. Start the local stack:
   ```bash
   docker compose up -d --build
   ```

5. Access Drupal at `http://localhost:30020` and complete the Drupal installer.

6. For stopping/cleanup:
   ```bash
   docker compose down
   ```

## RabbitMQ Integration

### Senders (outgoing messages)

| Sender                    | Queue               | Description                              |
|---------------------------|----------------------|------------------------------------------|
| `UserRegisteredSender`    | `frontend.user.registered`    | Sends user registration data to CRM      |
| `UserCheckinSender`       | `frontend.user.checkin`       | Sends check-in events to Kassa/CRM       |

### Receivers (incoming messages)

| Receiver                  | Queue               | Description                              |
|---------------------------|----------------------|------------------------------------------|
| `SessionUpdateReceiver`   | `frontend.session.update`     | Receives session time/location updates from Planning |

Queue names are built with `RABBITMQ_PREFIX` (default: `frontend.`), so each team can keep its own namespace and avoid collisions in the shared `/` vhost.

### Heartbeat

The `worker/heartbeat.php` script sends a periodic heartbeat signal so the Monitoring team can track system uptime on their dashboard.

### Message Format

All messages use XML format with a header and payload structure. See the team's flow documentation for detailed message schemas.

## Testing

Run the unit tests locally:
```bash
composer install
vendor/bin/phpunit tests/
```

RabbitMQ smoke test (declares queue `test`, publishes 2 messages, then closes the connection):
```bash
composer rabbitmq:smoke
```

Optional custom queue name:
```bash
php scripts/rabbitmq_smoke_test.php test
```

Reset test users (keep admin accounts):
```powershell
# Preview only
pwsh ./scripts/reset_test_users.ps1 -DryRun

# Delete all non-admin users
pwsh ./scripts/reset_test_users.ps1 -ConfirmLocal -DestructiveApproval DELETE-LOCAL-TEST-USERS
```

Important safety rule:
- `reset_test_users.ps1` is local-only and includes hard safety guards.
- It refuses to run unless local database service `frontend_db` is running.
- It refuses to run if `.env` points `DRUPAL_DB_HOST` to a non-local host.
- It refuses custom DB service/database names.
- It refuses destructive mode unless `-ConfirmLocal` is explicitly provided.
- It also requires the explicit destructive approval phrase:
   - `-DestructiveApproval DELETE-LOCAL-TEST-USERS`
- This is intentionally designed to prevent accidental deletion against VM/production data.

Local release smoke test (registration + DB + queue + redirect):
```powershell
pwsh ./scripts/local_release_smoke.ps1
```

Optional: keep the generated smoke user for manual inspection:
```powershell
pwsh ./scripts/local_release_smoke.ps1 -KeepTestUser
```

## Local Testing Guide (Step by Step)

Use this exact flow to validate the full registration path locally before creating a release tag.

1. Install dependencies and validate Composer metadata:
```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
```

2. Start the local stack (including local override):
```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build
```

3. Verify local services are healthy:
```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml ps
```
Expected:
- `frontend_drupal` running
- `frontend_db` running
- `rabbitmq_local` running (if enabled in local override)

4. Run unit tests:
```bash
vendor/bin/phpunit tests/
```

5. Run dependency security audit:
```bash
composer audit
```

6. Optional safe cleanup of previous local test accounts:
```powershell
pwsh ./scripts/reset_test_users.ps1 -DryRun
```
Only when you intentionally want deletion of non-admin local test users:
```powershell
pwsh ./scripts/reset_test_users.ps1 -ConfirmLocal -DestructiveApproval DELETE-LOCAL-TEST-USERS
```

7. Run end-to-end local smoke test:
```powershell
pwsh ./scripts/local_release_smoke.ps1
```
What this validates:
- Registration endpoint accepts the form post.
- Redirect reaches the confirmation route.
- Queue message count increases for `crm.incoming`.
- User record exists in Drupal DB.

8. Manual browser verification (recommended):
```text
Open http://localhost:30020/register
Submit one registration
Confirm redirect is /register/confirmation and URL contains no personal data query parameters
```

9. Release-ready gate (all checks should pass):
```bash
composer validate --strict
composer audit
vendor/bin/phpunit tests/
pwsh ./scripts/local_release_smoke.ps1
```

If all checks pass, create and push a release tag:
```bash
git tag vX.Y.Z
git push origin vX.Y.Z
```

Tests are also run automatically on every push and pull request via the CI pipeline.

## CI/CD

- **CI (`ci.yml`)**: Runs PHPUnit tests on every push to `main`, `dev`, `prod`, `feature/**`, on `v*` tags, and on pull requests.
- **CD (`deploy.yml`)**: Builds and pushes to GHCR only for `v*` tags/releases (for example `v1.0.0`), not for normal pushes.

### Creating a Release

```bash
git tag v1.0.0
git push origin v1.0.0
```

This triggers the deploy pipeline which builds and publishes the Docker image.

## Deployment Readiness Checklist

Before creating a production tag (`v*`), verify all of the following:

1. Unit tests pass in CI (`CI Pipeline`).
2. Local smoke passes:
   - `pwsh ./scripts/reset_test_users.ps1`
   - `pwsh ./scripts/local_release_smoke.ps1`
3. Manual live check in browser:
   - Open `http://localhost:30020/register`
   - Submit one test registration
   - Confirm redirect goes to `/register/confirmation` without personal data in query params
4. RabbitMQ verification:
   - Queue `crm.incoming` increments by 1 after registration.
5. Only then create and push a version tag:
   - `git tag vX.Y.Z`
   - `git push origin vX.Y.Z`

## Security Notes

1. Password storage:
   - User passwords are stored as hashes in Drupal (`users_field_data.pass`), not plaintext.
2. RabbitMQ payload hygiene:
   - Password fields are excluded from CRM outbound payloads.
3. Dependency auditing:
   - Run `composer audit` before release.
   - Current vulnerable `phpseclib/phpseclib` advisory has been patched by upgrading to a non-affected release.
4. Recommended release gate:
   - `composer validate --strict`
   - `composer install --no-interaction --prefer-dist --no-progress`
   - `composer audit`
   - `vendor/bin/phpunit tests/`
   - `pwsh ./scripts/local_release_smoke.ps1`

## Team

| Name             | Role                |
|------------------|---------------------|
| Charles Wong     | Team Lead           |
| Jarno Janssens   | Developer/Tester    |
| Ilyas Fariss     | Developer/Tester    |
| Dries Michiels   | Developer/Tester    |

## Related Repositories

- [Infra](https://github.com/IntegrationProject-Groep1/Infra) — VM management, pipelines, and deployment documentation
