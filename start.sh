#!/bin/sh
echo "Starting services..."
service php8.3-fpm start
nginx -g "daemon off;" &

echo "Setting permissions..."
chown -R www-data:www-data /var/www/html

echo "Waiting for services to start..."
sleep 5

echo "Running initial setup..."
curl http://127.0.0.1/

echo "Monitoring logs..."
tail -s 1 /var/log/nginx/*.log -f