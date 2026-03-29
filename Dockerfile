FROM drupal:10-apache

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    vim \
    && rm -rf /var/lib/apt/lists/*

# Install ext-sockets required by php-amqplib
RUN docker-php-ext-install sockets

# Add php-amqplib to the existing Drupal vendor.
# Using 'composer require' preserves all Drupal core dependencies.
# Never overwrite the Drupal image's composer.json.
WORKDIR /var/www/html
RUN composer require php-amqplib/php-amqplib:^3.7 --no-interaction --optimize-autoloader

# Copy custom modules and themes into the Drupal web root
COPY web/modules/custom /var/www/html/modules/custom
COPY web/themes/custom  /var/www/html/themes/custom

# Copy settings.php — uses getenv() so credentials come from environment variables
COPY web/sites/default/settings.php /var/www/html/sites/default/settings.php

# Create the public files directory and set correct ownership
RUN mkdir -p /var/www/html/sites/default/files \
    && chown -R www-data:www-data \
        /var/www/html/modules/custom \
        /var/www/html/themes/custom \
        /var/www/html/sites/default/settings.php \
        /var/www/html/sites/default/files \
    && chmod -R 775 /var/www/html/sites/default/files
