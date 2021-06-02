# IP-to-Country

This script will download a CSV database from ip2location.com and convert it to SQLite3 database for easy access.

Retreve country by providing the IP in the format: https://example.com/index.php?ip=1.1.1.1

Update the database weekly by running cron job:

```
wget -qO- https://example.com/index.php?update
```
