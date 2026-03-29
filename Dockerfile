FROM drupal:10-apache

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    vim \
    && rm -rf /var/lib/apt/lists/*