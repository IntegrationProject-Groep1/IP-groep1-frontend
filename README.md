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
├── worker/
│   └── heartbeat.php                   # Sends heartbeat signal every second
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

Tests are also run automatically on every push and pull request via the CI pipeline.

## CI/CD

- **CI (`ci.yml`)**: Runs PHPUnit tests on every push to `main`, `dev`, `prod` and on pull requests.
- **CD (`deploy.yml`)**: Builds and pushes to GHCR only for `v*` tags/releases (for example `v1.0.0`), not for normal pushes.

### Creating a Release

```bash
git tag v1.0.0
git push origin v1.0.0
```

This triggers the deploy pipeline which builds and publishes the Docker image.

## Team

| Name             | Role                |
|------------------|---------------------|
| Charles Wong     | Team Lead           |
| Jarno Janssens   | Developer/Tester    |
| Ilyas Fariss     | Developer/Tester    |
| Dries Michiels   | Developer/Tester    |

## Related Repositories

- [Infra](https://github.com/IntegrationProject-Groep1/Infra) — VM management, pipelines, and deployment documentation
