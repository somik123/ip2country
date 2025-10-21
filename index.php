<?php

/**
 * Author: Somik Khan
 * @copyright 2025
 * Permissions: Editing or non-commercial usage is allowed.
 * Redistribution: Allowed with proper attribution.
 * Prohibition: Sale or commercial usage.
 * This script is provided as-is, without any warranty.
 * Use at your own risk.
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

    var $local_ip_ranges;

    function __construct()
    {
        // Initialize local IP ranges
        $this->local_ip_ranges = array(
            "ipv4" => [
                [ip2long('10.0.0.0'),   ip2long('10.255.255.255')],
                [ip2long('172.16.0.0'),  ip2long('172.31.255.255')],
                [ip2long('192.168.0.0'),  ip2long('192.168.255.255')],
            ],

            "ipv6" => [
                [gmp_strval(gmp_import(inet_pton('fc00::'))),   gmp_strval(gmp_import(inet_pton('fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff')))],
                [gmp_strval(gmp_import(inet_pton('fe80::'))),   gmp_strval(gmp_import(inet_pton('febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff')))],
                [gmp_strval(gmp_import(inet_pton('::1'))),      gmp_strval(gmp_import(inet_pton('::1')))],
            ]
        );

        // Ensure directories exist
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

    function ensure_db_exists()
    {
        // Check if the database file exists
        if (!file_exists($this->db_file)) {
            echo "<pre>";
            $this->update();
            echo "</pre>";
            if (!file_exists($this->db_file)) {
                die("Database file does not exist after update. Please check the update process.");
            } else {
                echo "Database file created successfully.\n";
                die();
            }
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
            $link = trim($links[$i - 1]);
            $filename = $db_dir . basename($link);

            // Download the file
            echo "Downloading: $link\n";
            file_put_contents($filename, file_get_contents($link));
            echo file_exists($filename) ? " -- Done\n" : " -- Failed to download to: $filename\n";

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

    function is_ipv6($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            return false;
        else if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
            return true;
        else
            return null;
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

    function get_country($ip_raw, $from_csv = false)
    {
        $is_ipv6 = $this->is_ipv6($ip_raw);
        if ($is_ipv6 === null)
            return "Not valid IPv4 or IPv6 address.";

        if ($this->is_local_ip($ip_raw, $is_ipv6)) {
            return array(
                "ip" => $ip_raw,
                "dec" => "Local IP",
                "country" => "Local",
                "confidence" => "100%",
                "raw_country" => array("Local"),
                "time_taken" => "0 ms",
            );
        }

        if (file_exists($this->db_file) && !$from_csv)
            return $this->get_country_db($ip_raw, $is_ipv6);
        else
            return $this->get_country_csv($ip_raw, $is_ipv6);
    }

    function get_country_db($ip_raw, $ipv6 = false)
    {
        $req_start = microtime(true);

        if ($ipv6 === false) {
            $ip = ip2long($ip_raw);
            $prefix = "ipv4";
        } else {
            $ip_str = gmp_import(inet_pton($ip_raw));
            $ip = gmp_strval($ip_str);
            $prefix = "ipv6";
        }
        if ($ip === false)
            return "Invalid IP address.";

        $db = new SQLite3($this->db_file, SQLITE3_OPEN_READONLY);
        if (!$db)
            die("Cannot open database file: {$this->db_file}");
        $db->busyTimeout(5000);
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '{$prefix}_%'");

        $db_tables = array();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $db_tables[] = $row['name'];
        }

        $country_list = array();
        foreach ($db_tables as $db_table) {
            $stmt = $db->prepare("SELECT start, end, country FROM {$db_table} WHERE start <= :ip AND end >= :ip LIMIT 100");

            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $result = $stmt->execute();


            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (gmp_cmp($ip, $row['start']) >= 0 && gmp_cmp($ip, $row['end']) <= 0) {
                    $country_list[] = $row['country'];
                    break; // stop on first correct match
                }
            }
        }

        $reply = $this->calculate_confidence($country_list);
        $mostCommonCountry = $reply['country'];
        $confidence = $reply['confidence'];

        $req_end = microtime(true);
        $time_taken = round(($req_end - $req_start) * 1000, 2); // in milliseconds

        return array(
            "ip" => $ip_raw,
            "dec" => $ip,
            "country" => $mostCommonCountry,
            "confidence" => $confidence,
            "raw_country" => $country_list,
            "time_taken" => $time_taken . " ms",
        );
    }

    function get_country_csv($ip_raw, $ipv6 = false)
    {
        $req_start = microtime(true);

        if ($ipv6 === false) {
            $ip = ip2long($ip_raw);
            $db = $this->ipv4_db;
        } else {
            $ip_str = gmp_import(inet_pton($ip_raw));
            $ip = gmp_strval($ip_str);
            $db = $this->ipv6_db;
        }
        if ($ip === false)
            return "Invalid IP address.";

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

        $reply = $this->calculate_confidence($country_list);
        $mostCommonCountry = $reply['country'];
        $confidence = $reply['confidence'];

        $req_end = microtime(true);
        $time_taken = round(($req_end - $req_start) * 1000, 2); // in milliseconds

        return array(
            "ip" => $ip_raw,
            "dec" => $ip,
            "country" => $mostCommonCountry,
            "confidence" => $confidence,
            "raw_country" => $country_list,
            "time_taken" => $time_taken . " ms",
        );
    }

    function calculate_confidence($country_list)
    {
        $confidence = 0;
        $mostCommonCountry = "Unknown";

        if (!empty($country_list) && count($country_list) > 2) {
            $counts = array_count_values($country_list);

            // Step 2: Get most common country and total items
            $mostCommonCountry = array_keys($counts, max($counts))[0];
            $total = count($country_list);
            $confidence = ($counts[$mostCommonCountry] / $total) * 100;
        }

        return array(
            "country" => $mostCommonCountry,
            "confidence" => round($confidence, 2) . "%"
        );
    }

    function is_local_ip($ip_raw, $ipv6 = false)
    {

        if ($ipv6 === false) {
            $ip = ip2long($ip_raw);
            $db = $this->local_ip_ranges["ipv4"];
        } else {
            $ip_str = gmp_import(inet_pton($ip_raw));
            $ip = gmp_strval($ip_str);
            $db = $this->local_ip_ranges["ipv6"];
        }
        foreach ($db as $range) {
            if ($ip >= $range[0] && $ip <= $range[1]) {
                return true;
            }
        }
        return false;
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

if ($_REQUEST['mode']) {
    $mode = $_REQUEST['mode'];
    $ip2c = new ip2country;
    if ($mode === "setup") {
        $ip2c->ensure_db_exists();
        echo "Database is ready. You can now search IPs.";
    } elseif ($mode === 'update') {
        header("Content-Type: text/plain");
        $ip2c->update();
        echo "Database updated successfully.";
    } elseif ($mode === "ip") {
        header('Content-Type: application/json');
        $ip = get_remote_ip();
        $out = array("status" => "success", "ip" => $ip);
        echo json_encode($out);
    } elseif (in_array($mode, array("v1", "v2", "b64"))) {
        if ($_REQUEST['ip']) {
            $from_csv = $mode === "v1" ? true : false;

            if ($mode === "b64") {
                $ip = base64_decode($_REQUEST['ip']);
                if ($ip === false) {
                    echo json_encode(array("error" => "Invalid base64 encoded IP address."), JSON_PRETTY_PRINT);
                    die();
                }
            } else {
                $ip = $_REQUEST['ip'];
            }

            header('Content-Type: application/json');
            $data = $ip2c->get_country($ip, $from_csv);
            echo json_encode($data, JSON_PRETTY_PRINT);
        }
    }
    die();
}

?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IP2Country Finder</title>

    <link rel="apple-touch-icon" sizes="180x180" href="./ico/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="./ico/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="./ico/favicon-16x16.png">
    <link rel="manifest" href="./ico/site.webmanifest">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
    <script>
        function post_data(endpoint = "./api/v2/") {
            const rawDataDiv = document.getElementById("raw_data_div");
            const rawDataElem = document.getElementById("raw_data");

            let ip = document.getElementById("ip").value;
            if (ip.length < 8) return false;

            rawDataDiv.style.display = "";
            rawDataElem.innerHTML = "Loading...";

            let http = new XMLHttpRequest();
            http.open("GET", endpoint + ip, true);
            http.send();
            http.onload = function() {
                if (http.status >= 200 && http.status < 300) {
                    rawDataElem.innerHTML = http.responseText;
                } else {
                    post_data("?mode=v2&ip=");
                }

            }
            return false;
        }

        function update_db(endpoint = "./api/update") {
            const rawDataDiv = document.getElementById("raw_data_div");
            const rawDataElem = document.getElementById("raw_data");

            rawDataDiv.style.display = "";
            rawDataElem.innerHTML = "Updating database...\n";

            let http = new XMLHttpRequest();
            http.open("GET", endpoint, true);

            let lastIndex = 0;
            http.onprogress = function() {
                // Get new chunk of text
                let newText = http.responseText.substring(lastIndex);
                lastIndex = http.responseText.length;

                // Split by newline and append each new line
                let lines = newText.split("\n");
                lines.forEach(line => {
                    if (line.trim() !== "") {
                        rawDataElem.innerHTML += line + "\n";
                    }
                });
                rawDataElem.scrollTop = rawDataElem.scrollHeight;
            };

            http.onload = function() {
                if (http.status >= 200 && http.status < 300) {
                    rawDataElem.innerHTML += "Database update completed successfully.";
                } else {
                    location.href = "?mode=update";
                    rawDataElem.innerHTML += "Failed to update database. Please try again.";
                }
            };

            http.send();
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
                <input type="text" name="ip" id="ip" class="form-control" placeholder="IP address"
                    aria-label="IPv4 or IPv6 address" aria-describedby="button-addon2"
                    value="<?= $_REQUEST['ip'] ?? get_remote_ip() ?>" />
                <button class="btn btn-outline-secondary" type="submit" onclick="return post_data();">Search</button>
            </div>
            <p class="fs-6"><a href="./api/update" onclick="return update_db();">Update Database</a></p>
        </form>

        <div class="mb-3" id="raw_data_div" style="display: none;">
            <h4>API Response</h4>
            <pre id="raw_data" class="border p-3"></pre>
        </div>
        <div class="border p-3">
            <h4>How to use</h4>
            <p>To search an IP address, enter it in the input box above and click "Search". The response will be shown below.</p>
            <p>To update the database, click on the "Update Database" link. This will download the latest IP to country mappings.</p>
            <p>For API usage, you can access:</p>
            <ul>
                <li><code>/api/v1/{ip}</code> - Polls raw CSV files and returns JSON with country info</li>
                <li><code>/api/v2/{ip}</code> - Polls sqlite database and returns JSON with country info</li>
                <li><code>/api/b64/{base64_encoded_ip}</code> - Polls sqlite database and returns JSON with country info for base64 encoded IP</li>
                <li><code>/api/ip</code> - Returns the remote IP address of the client</li>
                <li><code>/api/setup</code> - Sets up folders, CSV files and database with the latest IP to country mappings (Runs on container startup)</li>
                <li><code>/api/update</code> - Updates the database with the latest IP to country mappings</li>
            </ul>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
</body>

</html>


<?php

function get_remote_ip()
{
    $ip_keys = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];

    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip_list = explode(',', $_SERVER[$key]);
            foreach ($ip_list as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
    }

    // fallback (may return local/private IP)
    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}
