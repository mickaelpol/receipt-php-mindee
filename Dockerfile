FROM php:8.2-cli

# Installer git & unzip pour composer
RUN apt-get update && apt-get install -y git unzip && rm -rf /var/lib/apt/lists/*

# Installer composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json /app/composer.json

# Installer dépendances
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

# Copier le code
COPY index.php /app/index.php

# Exposer le port
EXPOSE 8080

# Lancer le serveur PHP intégré
CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
