FROM php:8.2-cli

# Composer (autoriser root) + dépendances minimales
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN apt-get update && apt-get install -y git unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Installer les deps d'après composer.json
COPY composer.json /app/composer.json
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress

# Copier l'app
COPY index.php /app/index.php

# Render passe le port via $PORT ; on l'utilise
EXPOSE 8080
CMD ["sh", "-lc", "php -S 0.0.0.0:${PORT:-8080} index.php"]
