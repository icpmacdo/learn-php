#!/bin/sh
# Entrypoint for the php service.
#
# When starting php-fpm (i.e. `docker compose up`, not a one-off `compose run`
# command) it installs Composer dependencies if vendor/ is missing (fresh
# clone), waits until MySQL accepts connections, then runs the Doctrine
# migrations for both the dev database (app) and the test database (app_test),
# so a plain `docker compose up` yields a fully migrated, working API.
set -e

if [ "$1" = "php-fpm" ]; then
    if [ ! -f /app/vendor/autoload_runtime.php ]; then
        echo "entrypoint: vendor/ missing, running composer install ..."
        composer install --working-dir=/app --no-interaction
    fi

    echo "entrypoint: waiting for MySQL ..."
    until php -r '
        try {
            new PDO("mysql:host=mysql;port=3306;dbname=app", "app", "app");
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    '; do
        sleep 1
    done
    echo "entrypoint: MySQL is up."

    echo "entrypoint: running migrations (dev database) ..."
    php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    echo "entrypoint: running migrations (test database) ..."
    php /app/bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
fi

exec docker-php-entrypoint "$@"
