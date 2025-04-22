<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

function getRandomIp($ipRange) {
    $ipParts = explode(".", $ipRange);
    $ipParts[3] = rand(0, 255);
    return implode(".", $ipParts);
}

$ipRanges = [
    "162.159.192.0",
    "162.159.193.0",
    "162.159.195.0",
    "188.114.96.0",
    "188.114.97.0",
    "188.114.98.0",
    "188.114.99.0"
];

$ports = explode(",", "500,854,859,864,878,880,890,891,894,903,908,928,934,939,942,943,945,946,955,968,987,988,1002,1010,1014,1018,1070,1074,1180,1387,1701,1843,2371,2408,2506,3138,3476,3581,384,4177,4198,4233,4500,5279,5956,7103,7152,7156,7281,7559,8319,8742,8854,8886");

$chosenRanges = [
    $ipRanges[array_rand($ipRanges)],
    $ipRanges[array_rand($ipRanges)]
];

$chosenIps = [
    getRandomIp($chosenRanges[0]),
    getRandomIp($chosenRanges[1])
];

$chosenPort = $ports[array_rand($ports)];

$profileConfigs = [
    "warp://{$chosenIps[0]}:{$chosenPort}?ifp=5-10#WiW-🟢&&detour=warp://{$chosenIps[1]}:{$chosenPort}?ifp=5-10#WARP-🟢",
    "warp://{$chosenIps[1]}:{$chosenPort}?ifp=5-10#WiW-🔵&&detour=warp://{$chosenIps[0]}:{$chosenPort}?ifp=5-10#WARP-🔵"
];

$profileHeader = "#profile-title: base64:" . base64_encode("SiNAVM | WARP") . "\n" .
"#profile-update-interval: 1\n" .
"#subscription-userinfo: upload=0; download=0; total=10737418240000000; expire=2546249531\n" .
"#support-url: https://t.me/sinavm\n" .
"#profile-web-page-url: https://github.com/sinabigo/SBO\n";

$profileOutput = $profileHeader . "\n" . implode("\n", $profileConfigs);

// اطمینان از وجود پوشه
$savePath = "subscriptions/warp";
if (!is_dir($savePath)) {
    mkdir($savePath, 0777, true);
}

file_put_contents("$savePath/config", $profileOutput);

echo "\nWARP Configuration created!\n";
