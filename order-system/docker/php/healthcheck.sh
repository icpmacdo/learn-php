#!/bin/sh
# Healthy once php-fpm is listening on port 9000.
php -r 'exit((int) !@fsockopen("127.0.0.1", 9000));'
