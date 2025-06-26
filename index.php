<?php

/**
 * Author: Somik Khan
 * @copyright 2025
 * Permissions: Editing or non-commercial usage
 * Prohibition: Sale or commercial usage.
 */

set_time_limit(300);
header('X-Accel-Buffering: no');
ob_implicit_flush(1);


class ip2country
{
    // Actual DB file
    var $db_file = "./db/ip2country.sqlite";

    // CSV files go here
    var $ipv4_db = "./db/ipv4/";
    var $ipv6_db = "./db/ipv6/";

    // Tmp dir for extracting files
    var $tmp_dir = "./db/tmp/";

    // Temporary file for IP database zip
    var $tmp_zip = "./db/tmp.zip";

    // Link to ip2loc download
    var $csv_links = array(
        "ipv4" => array(
            "https://download.ip2location.com/lite/IP2LOCATION-LITE-DB1.CSV.ZIP",
            "https://cdn.jsdelivr.net/npm/@ip-location-db/geo-whois-asn-country/geo-whois-asn-country-ipv4-num.csv",
            "https://cdn.jsdelivr.net/npm/@ip-location-db/iptoasn-country/iptoasn-country-ipv4-num.csv",
            "https://cdn.jsdelivr.net/npm/@ip-location-db/iplocate-country/iplocate-country-ipv4-num.csv",
            "https://cdn.jsdelivr.net/npm/@ip-location-db/dbip-country/dbip-country-ipv4-num.csv",
            "https://cdn.jsdelivr.net/npm/@ip-location-db/geolite2-geo-whois-asn-country/geolite2-geo-whois-asn-country-ipv4-num.csv",
        ),
        "ipv6" => array(
            "https://download.ip2location.com/lite/IP2LOCATION-LITE-DB1.IPV6.CSV.ZIP",
            "https://cdn.jsdelivr.net/npm/@ip-location-db/geo-asn-country/geo-asn-country-ipv6-num.csv",
            "https://cdn.jsdelivr.net/npm/@ip-location-db/iptoasn-country/iptoasn-country-ipv6-num.csv",
            "https://cdn.jsdelivr.net/npm/@ip-location-db/iplocate-country/iplocate-country-ipv6-num.csv",
            "https://cdn.jsdelivr.net/npm/@ip-location-db/dbip-country/dbip-country-ipv6-num.csv",
            "https://cdn.jsdelivr.net/npm/@ip-location-db/geolite2-geo-whois-asn-country/geolite2-geo-whois-asn-country-ipv6-num.csv",
        ),
    );

    function __construct()
    {
        if (!file_exists($this->ipv4_db) || !file_exists($this->ipv6_db) || !file_exists($this->tmp_dir)) {
            try {
                mkdir($this->ipv4_db, 0755, true);
                mkdir($this->ipv6_db, 0755, true);
                mkdir($this->tmp_dir, 0755, true);
            } catch (Exception $ex) {
                echo "Error creating directories: " . $ex->getMessage() . "\n";
                die("Cannot create directories. Please create 'db' dirctory manually and ensure it is writable by php.");
            }
            echo "Directories created successfully.\n";
        }
    }

    function update()
    {
        $ipv4_links = $this->csv_links['ipv4'];
        $ipv6_links = $this->csv_links['ipv6'];

        echo "Downloading ip to country CSV files...\n";
        $this->download_csv_common($ipv4_links, $this->ipv4_db);
        $this->download_csv_common($ipv6_links, $this->ipv6_db);

        if (!empty($this->db_file)) {
            $this->populate_db();
        }

        echo "All done!";
    }

    function download_csv_common($links, $db_dir)
    {
        // Download all CSVs
        for ($i = 1; $i <= count($links); $i++) {
            $link = $links[$i - 1];
            $filename = $db_dir . basename($link);
            // Download the file
            file_put_contents($filename, file_get_contents($link));
            echo file_exists($filename) ? "Downloaded: $filename\n" : "Failed to download: $filename\n";
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if (strtolower($ext) === "zip") {
                $zip = new ZipArchive;
                if ($zip->open($filename) === true) {
                    $zip->extractTo($this->tmp_dir);
                    $zip->close();

                    // Rename the downloaded CSV and remove unused files
                    $csv_file = $this->tmp_dir  . strtoupper(basename($link, ".ZIP"));

                    if (file_exists($csv_file)) {
                        rename($csv_file, $db_dir . "{$i}.csv");
                        // Remove extracted files
                        foreach (glob($this->tmp_dir . "*") as $file) {
                            if (is_file($file)) @unlink($file);
                        }
                        unlink($filename);
                    } else {
                        print_r(scandir($this->tmp_dir));
                        die("Cannot find");
                    }
                } else {
                    die("Failed to open {$filename}");
                }
            } else {
                rename($filename, $db_dir . "{$i}.csv");
            }
        }
    }


    function populate_db()
    {
        @unlink($this->db_file);
        foreach (glob("{$this->ipv4_db}*.csv") as $file) {
            echo "Processing file: {$file}\n";
            $this->save_to_db($file, false);
        }
        foreach (glob("{$this->ipv6_db}*.csv") as $file) {
            echo "Processing file: {$file}\n";
            $this->save_to_db($file, true);
        }
    }

    function get_country($ip_raw)
    {
        if (file_exists($this->db_file))
            return $this->get_country_db($ip_raw);
        else
            return $this->get_country_csv($ip_raw);
    }

    function get_country_db($ip_raw)
    {
        $req_start = microtime(true);

        if (stristr($ip_raw, ":") === false) {
            $ip = ip2long($ip_raw);
            $is_ipv6 = false;
        } else {
            $ip_str = gmp_import(inet_pton($ip_raw));
            $ip = gmp_strval($ip_str);
            $is_ipv6 = true;
        }

        $db = new SQLite3($this->db_file, SQLITE3_OPEN_READONLY);
        if (!$db)
            die("Cannot open database file: {$this->db_file}");
        $db->busyTimeout(5000);

        $prefix = $is_ipv6 ? "ipv6" : "ipv4";

        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '{$prefix}_%'");

        $db_tables = array();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $db_tables[] = $row['name'];
        }

        $country_list = array();
        foreach ($db_tables as $db_table) {
            $stmt = $db->prepare("
                SELECT start, end, country FROM {$db_table}
                WHERE start <= :ip AND end >= :ip
                LIMIT 100
            ");

            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $result = $stmt->execute();


            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (gmp_cmp($ip, $row['start']) >= 0 && gmp_cmp($ip, $row['end']) <= 0) {
                    $country_list[] = $row['country'];
                    break; // stop on first correct match
                }
            }
        }

        // Step 1: Count occurrences
        if (!empty($country_list)) {
            $counts = array_count_values($country_list);

            // Step 2: Get most common country and total items
            $mostCommonCountry = array_keys($counts, max($counts))[0];
            $total = count($country_list);
            $confidence = ($counts[$mostCommonCountry] / $total) * 100;
        }

        $req_end = microtime(true);
        $time_taken = round(($req_end - $req_start) * 1000, 2); // in milliseconds

        return array(
            "ip" => $ip_raw,
            "dec" => $ip,
            "country" => $mostCommonCountry,
            "confidence" => round($confidence, 2) . "%",
            "raw_country" => $country_list,
            "time_taken" => $time_taken . " ms",
        );
    }

    function get_country_csv($ip_raw)
    {
        $req_start = microtime(true);

        if (stristr($ip_raw, ":") === false) {
            $ip = ip2long($ip_raw);
            $db = $this->ipv4_db;
        } else {
            $ip_str = gmp_import(inet_pton($ip_raw));
            $ip = gmp_strval($ip_str);
            $db = $this->ipv6_db;
        }
        if ($ip === false)
            return "Invalid";
        $country_list = array();
        foreach (glob("{$db}*.csv") as $file) {
            foreach ($this->readLines($file) as $line) {
                $line = str_replace('"', '', trim($line));

                $parts = explode(",", $line);
                if (count($parts) < 3) continue;

                if ($ip >= $parts[0] && $ip <= $parts[1]) {
                    $country_list[] = $parts[2];
                }
            }
        }

        // Step 1: Count occurrences
        if (!empty($country_list)) {
            $counts = array_count_values($country_list);

            // Step 2: Get most common country and total items
            $mostCommonCountry = array_keys($counts, max($counts))[0];
            $total = count($country_list);
            $confidence = ($counts[$mostCommonCountry] / $total) * 100;
        }

        $req_end = microtime(true);
        $time_taken = round(($req_end - $req_start) * 1000, 2); // in milliseconds

        return array(
            "ip" => $ip_raw,
            "dec" => $ip,
            "country" => $mostCommonCountry,
            "confidence" => round($confidence, 2) . "%",
            "raw_country" => $country_list,
            "time_taken" => $time_taken . " ms",
        );
    }


    function readLines($path)
    {
        $file = fopen($path, 'r');

        if ($file) {
            while (($line = fgets($file)) !== false) {
                yield $line;
            }

            fclose($file);
        } else {
            throw new Exception('Could not open the file!');
        }
    }

    function save_to_db($csv_file, $is_ipv6 = false)
    {
        $db = new SQLite3($this->db_file, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
        if (!$db)
            die("Cannot open database file: {$this->db_file}");
        $db->busyTimeout(5000);

        $prefix = $is_ipv6 ? "ipv6" : "ipv4";

        $db_table = "{$prefix}_" . basename($csv_file, ".csv");

        // Step 1: Create table (TEXT to handle big integers as strings)
        $db->exec("CREATE TABLE IF NOT EXISTS {$db_table} (
            start TEXT NOT NULL,
            end TEXT NOT NULL,
            country TEXT NOT NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_start_end ON {$db_table} (start, end)");

        if (!file_exists($csv_file)) {
            die("CSV file not found.\n");
        }

        $stmt = $db->prepare("INSERT INTO {$db_table} (start, end, country) VALUES (:start, :end, :country)");

        $db->exec('BEGIN');
        foreach ($this->readLines($csv_file) as $line) {
            $line = str_replace('"', '', trim($line));

            $parts = explode(",", $line);
            if (count($parts) < 3) continue;

            $stmt->bindValue(':start', $parts[0], SQLITE3_TEXT);
            $stmt->bindValue(':end', $parts[1], SQLITE3_TEXT);
            $stmt->bindValue(':country', $parts[2], SQLITE3_TEXT);
            $stmt->execute();
        }
        $db->exec('COMMIT');
        $db->close();
    }
}


// ----------
// Usage
// ----------


if ($_REQUEST['ip']) {
    $ip2c = new ip2country;
    header('Content-Type: application/json');
    echo json_encode($ip2c->get_country($_REQUEST['ip']), JSON_PRETTY_PRINT);
    die();
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IP2Country Finder</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
    <script>
        function post_data() {
            let ip = document.getElementById("ip").value;
            if(ip.length <8) return false;

            document.getElementById("raw_data").style.display = "";
            document.getElementById("raw_data").innerHTML = "Loading...";

            let http = new XMLHttpRequest();
            http.open("GET", "./?ip=" + ip, true);
            http.send();
            http.onload = function() {
                document.getElementById("raw_data").innerHTML = http.responseText;
            }
            return false;
        }
    </script>
</head>

<body data-bs-theme="dark">
    <div class="container-sm mt-3">
        <h3><a href="./">IP2Country Finder</a></h3>
        <form method="POST" action="./">
            Search IP:
            <div class="input-group mb-3">
                <input type="text" name="ip" id="ip" class="form-control" placeholder="IP address" aria-label="IPv4 or IPv6 address" aria-describedby="button-addon2">
                <button class="btn btn-outline-secondary" type="submit" onclick="return post_data();">Search</button>
            </div>
            <p class="fs-6"><a href="./?update">Update Database</a></p>
        </form>

        <div>
            <pre id="raw_data" class="border p-3" style="display: none;"></pre>
            <?php
            if (isset($_REQUEST['update'])) {
                echo '<pre id="raw_data" class="border p-3">';
                $ip2c = new ip2country;
                $ip2c->update();
                echo "Done! Redirecting in 3 seconds...";
                echo "</pre>";
                echo '<meta http-equiv="refresh" content="3; url=./" />';
            }
            ?>

        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
</body>

</html>