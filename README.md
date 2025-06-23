# IP-to-Country

This script will download IP to Country CSV files from multiple location and convert it to SQLite3 database for easy access.

Retreve country by providing the IP in the format: https://example.com/?ip=1.1.1.1

Update the database weekly by running cron job:

```
wget -qO- https://example.com/?update
```

## How to use (with docker-compose)
1. Copy the `docker-compose.yml` to a folder of your choice.
1. Edit the file and change the container name.
1. Start the container with the following command:
   ```
   docker compose up -d
   ```

## How to use (with docker command)
Pull docker image: 
```
docker image pull somik123/ip2country:latest
```

Create a volume for the database files:
```
docker volume create ip2country_db
```

Start the container with the command:
```
docker run -d -p 8080:80 -v ip2country_db:/var/www/html --name ip2country somik123/ip2country_db
```

> **Note:** that this will run the container with port `8080` on host mapped to the container's nginx running on port `80`.
