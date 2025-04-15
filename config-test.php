<?php

// Load environment variables
$API_URL = "http://127.0.0.1:6756";
$BEARER_TOKEN = "xXxTestxXx";
$TEST_URL = "http://cp.cloudflare.com";
$TIMEOUT = 1000; // in milliseconds
$BATCH_SIZE = 10; // Number of proxies to process in parallel

function runHiddify()
{
    // 0. cd to hiddify-cli folder
    $hiddifyDir = __DIR__ . '/hiddify-cli';
    if (!is_dir($hiddifyDir)) {
        mkdir($hiddifyDir);
    }
    chdir($hiddifyDir);

    // 1. Download hiddify-cli
    $downloadUrl = 'https://github.com/hiddify/hiddify-core/releases/download/v1.3.6/hiddify-cli-linux-amd64.tar.gz';
    $downloadedFile = 'hiddify-cli.tar.gz';
    file_put_contents($downloadedFile, fopen($downloadUrl, 'r'));

    // 2. Extract the archive
    $command = "tar -zxvf {$downloadedFile}";
    shell_exec($command);

    // 3. Make HiddifyCli executable
    chmod('HiddifyCli', 0755);

    // 4. Run HiddifyCli in the background and get the process ID
    $configPath = escapeshellarg('../config.txt');
    $hiddifyConfigPath = escapeshellarg('../hiddify-conf.json');
    $command = "./HiddifyCli run -c {$configPath} --hiddify {$hiddifyConfigPath} > /dev/null 2>&1 & echo $!";
    $pid = (int)shell_exec($command);

    // Store the process ID in a file
    $pidFile = 'hiddify.pid';
    file_put_contents($pidFile, $pid);

    // Move Back to Parent Directory
    chdir('../');

    echo "Hiddify started in background with PID: $pid\n";
}

// Function to kill the Hiddify process
function stopHiddify()
{
    $pidFile = 'hiddify-cli/hiddify.pid';
    if (file_exists($pidFile)) {
        $pid = (int)file_get_contents($pidFile);
        if ($pid) {
            posix_kill($pid, 9); // Send SigKill
            echo "Hiddify process (PID: $pid) stopped.\n";
        }
        unlink($pidFile);
    }
}

// Function to get headers with the Bearer token
function get_custom_headers()
{
    global $BEARER_TOKEN;
    return [
        "Authorization: Bearer $BEARER_TOKEN"
    ];
}

// Function to get all proxies from the Clash API
function get_proxies()
{
    global $API_URL;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$API_URL/proxies");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, get_custom_headers());

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 200) {
        return json_decode($response, true)["proxies"];
    } else {
        throw new Exception("Failed to get proxies: $httpcode");
    }
}

// Function to get the real delay of a batch of proxies by sending requests concurrently
function get_real_delay_batch($proxy_names)
{
    global $API_URL, $TIMEOUT, $TEST_URL;

    $multiHandle = curl_multi_init();
    $curlHandles = [];

    foreach ($proxy_names as $proxy_name) {
        $ch = curl_init();
        $encoded_proxy_name = str_replace('+', '%20', urlencode($proxy_name));
        curl_setopt($ch, CURLOPT_URL, "$API_URL/proxies/$encoded_proxy_name/delay?timeout=$TIMEOUT&url=$TEST_URL");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $TIMEOUT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, get_custom_headers());

        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[$proxy_name] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
    } while ($running);

    $results = [];
    foreach ($curlHandles as $proxy_name => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpcode === 200) {
            $results[$proxy_name] = json_decode($response, true)["delay"];
        } else {
            $results[$proxy_name] = $TIMEOUT;
        }
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiHandle);
    return $results;
}

// Function to update the delay information for all proxies in batches
function update_delay_info($proxies, $sampling_type)
{
    global $BATCH_SIZE;

    $proxy_names = array_keys($proxies);
    $batches = array_chunk($proxy_names, $BATCH_SIZE);

    foreach ($batches as $batch) {
        if ($sampling_type === "single") {
            $delays = get_real_delay_batch($batch);
            foreach ($delays as $proxy_name => $delay) {
                $proxies[$proxy_name]["delay_single"] = $delay;
            }
        }
    }
    return $proxies;
}

// Function to filter single working proxies
function filter_single_working_proxies($proxies)
{
    global $TIMEOUT;

    $working_proxies = array_filter($proxies, function ($proxy_data) use ($TIMEOUT) {
        return in_array($proxy_data["type"], ["VLESS", "Trojan", "Shadowsocks", "VMess", "TUIC"]) &&
            isset($proxy_data["delay_single"]) &&
            $proxy_data["delay_single"] < $TIMEOUT;
    });

    usort($working_proxies, function ($a, $b) {
        return $a["delay_single"] - $b["delay_single"];
    });

    return $working_proxies;
}

function filterConfigs($names, $configFile)
{
    // Read the config file into an array
    $configs = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // Array to store the filtered configs
    $filteredConfigs = [];

    // Iterate through each name
    foreach ($names as $name) {
        // Extract the relevant part for comparison
        $parts = explode("|", $name);
        // Get only the numeric part of the key
        $key = explode(" § ", trim($parts[3]))[0];
        // Create comparison string with spaces replaced by %20
        $comparisonString =
            str_replace(' ', '%20', trim($parts[0])) . "%20|%20" .
            str_replace(' ', '%20', trim($parts[1])) . "%20|%20" .
            str_replace(' ', '%20', trim($parts[2])) . "%20|%20" .
            $key;

        // Iterate through each config
        foreach ($configs as $config) {
            // Check if the comparison string is present in the config
            if (strpos($config, $comparisonString) !== false) {
                // Add the matching config to the filtered array
                $filteredConfigs[] = $config;
                break; // Move on to the next name after finding a match
            }
        }
    }

    // Write the filtered configs back to the file only if it's not empty
    if (!empty($filteredConfigs)) {
        file_put_contents("config.txt", implode("\n", $filteredConfigs));
        echo "\nConfig file written successfully.\n";
    } else {
        echo "\nNo valid new configurations found. Config file not written.\n";
    }
}

// Main function to run the script based on the mode
function main()
{
    try {
        // Run Hiddify
        runHiddify();
        // Sleep Until Hiddify Loads
        sleep(120);

        // Check Proxies
        $proxies = get_proxies();
        $proxies = update_delay_info($proxies, "single");
        $working_proxies = filter_single_working_proxies($proxies);
        // print_r($working_proxies);

        // Initialize an array to hold the names
        $names = [];
        // Loop through the original array and extract the names
        foreach ($working_proxies as $item) {
            if (isset($item['name'])) {
                $names[] = $item['name'];
            }
        }

        // Filter Configs
        // print_r($names);
        $configFile = 'config.txt';
        filterConfigs($names, $configFile);

        echo "\nTesting Configs Done!\n";
    } catch (Exception $e) {
        echo "An error occurred: " . $e->getMessage();
    }
}

echo "Running The Config-Test Script... \n";
// Register the shutdown function to stop Hiddify when the script exits
register_shutdown_function('stopHiddify');
main();
