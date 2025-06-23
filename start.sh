#!/bin/sh
echo "Starting services..."
service php8.3-fpm start
nginx -g "daemon off;" &
echo "Ready."
chown -R www-data:www-data /var/www/html
tail -s 1 /var/log/nginx/*.log -f