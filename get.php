<?php
// Enable error reporting
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

// Include the functions file
require "functions.php";

// Fetch the JSON data from the API and decode it into an associative array
$sourcesArray = json_decode(
    file_get_contents("‏channels.json"),
    true
);

// Function to process a batch of sources
function processBatch($batchSources, $sourcesArray, &$configsList)
{
    // Initialize cURL multi handle
    $multiHandle = curl_multi_init();
    $curlHandles = [];

    // Add individual cURL handles to the multi handle
    foreach ($batchSources as $source) {
        $url = "https://t.me/s/" . $source;
        $curlHandle = curl_init($url);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        $curlHandles[$source] = $curlHandle;
        curl_multi_add_handle($multiHandle, $curlHandle);
    }

    // Execute the multi handle
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running);

    // Get the content from the individual cURL handles
    foreach ($curlHandles as $source => $curlHandle) {
        $tempData = curl_multi_getcontent($curlHandle);
        $type = implode("|", $sourcesArray[$source]);
        $tempExtract = extractLinksByType($tempData, $type);
        if (!is_null($tempExtract)) {
            $configsList[$source] = $tempExtract;
        }
        curl_multi_remove_handle($multiHandle, $curlHandle);
        curl_close($curlHandle);
    }

    // Close the multi handle
    curl_multi_close($multiHandle);
}

// Batch size
$batchSize = 10;
$totalSources = count($sourcesArray);
$sourceKeys = array_keys($sourcesArray);
$configsList = [];
echo "Fetching Configs\n";

// Process the sources in batches
for ($i = 0; $i < $totalSources; $i += $batchSize) {
    $batchSources = array_slice($sourceKeys, $i, min($batchSize, $totalSources - $i));
    processBatch($batchSources, $sourcesArray, $configsList);
    echo "\rProgress: " . round(($i + count($batchSources)) / $totalSources * 100) . "% \n";
}

echo "\nProcessing Configs\n";
$finalOutput = [];
// $locationBased = [];
$needleArray = ["amp%3B"];
$replaceArray = [""];

// Define the hash and IP keys for each type of configuration
$configsHash = [
    "vmess" => "ps",
    "vless" => "hash",
    "trojan" => "hash",
    "tuic" => "hash",
    "hy2" => "hash",
    "ss" => "name",
];
$configsIp = [
    "vmess" => "add",
    "vless" => "hostname",
    "trojan" => "hostname",
    "tuic" => "hostname",
    "hy2" => "hostname",
    "ss" => "server_address",
];

$totalSources = count($configsList);
$tempSource = 1;

// Loop through each source in the configs list
foreach ($configsList as $source => $configs) {
    $totalConfigs = count($configs);
    $tempCounter = 1;
    echo "\n" . strval($tempSource) . "/" . strval($totalSources) . "\n";

    // Loop through each config in the configs array
    $limitKey = count($configs) - 40;
    foreach (array_reverse($configs) as $key => $config) {
        // Calculate the percentage complete
        $percentage = ($tempCounter / $totalConfigs) * 100;

        // Print the progress bar
        echo "\rProgress: [";
        echo str_repeat("=", $tempCounter);
        echo str_repeat(" ", $totalConfigs - $tempCounter);
        echo "] $percentage%";
        $tempCounter++;

        // If the config is valid and the key is less than or equal to 15
        if (is_valid($config) && $key >= $limitKey) {
            $type = detect_type($config);
            $configHash = $configsHash[$type];
            $configIp = $configsIp[$type];
            $decodedConfig = configParse(explode("<", $config)[0]);
            // $configLocation =
            //     ip_info($decodedConfig[$configIp])->country ?? "XX";
            // $configFlag =
            //     $configLocation === "XX" ? "❔" : ($configLocation === "CF" ? "🚩" : getFlags($configLocation));
            $isEncrypted =
                isEncrypted($config) ? "🔒" : "🔓";
            $decodedConfig[$configHash] =
                // $configFlag .
                // $configLocation .
                // " | " .
                $isEncrypted .
                " | " .
                $type .
                " | @" .
                $source .
                " | " .
                strval($key);
            $encodedConfig = reparseConfig($decodedConfig, $type);
            if (substr($encodedConfig, 0, 10) !== "ss://Og==@") {
                $finalOutput[] = str_replace(
                    $needleArray,
                    $replaceArray,
                    $encodedConfig
                );
                // $locationBased[$configLocation][] = str_replace(
                //     $needleArray,
                //     $replaceArray,
                //     $encodedConfig
                // );
            }
        }
    }
    $tempSource++;
}

// deleteFolder("subscriptions/location/normal");
// deleteFolder("subscriptions/location/base64");
// mkdir("subscriptions/location/normal");
// mkdir("subscriptions/location/base64");

// Loop through each location in the location-based array
// foreach ($locationBased as $location => $configs) {
//     $tempConfig = urldecode(implode("\n", $configs));
//     $base64TempConfig = base64_encode($tempConfig);
//     file_put_contents(
//         "subscriptions/location/normal/" . $location,
//         $tempConfig
//     );
//     file_put_contents(
//         "subscriptions/location/base64/" . $location,
//         $base64TempConfig
//     );
// }

// Write the final output to a file only if it's not empty
if (!empty($finalOutput)) {
    file_put_contents("config.txt", implode("\n", $finalOutput));
    echo "\nConfig file written successfully.\n";
} else {
    echo "\nNo valid new configurations found. Config file not written.\n";
}

echo "\nGetting Configs Done!\n";
