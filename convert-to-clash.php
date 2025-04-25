<?php
// convert-to-clash.php
$inputFile = 'config.txt';
$outputFile = 'clash-config.yaml';

// خواندن config.txt
$configs = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$configs) {
    die("Error: config.txt is empty or not found.\n");
}

// آماده‌سازی ساختار YAML
$yaml = [
    'mixed-port' => 7890,
    'external-controller' => '0.0.0.0:6756',
    'proxies' => []
];

// تابع برای تجزیه query string
function parseQuery($query) {
    $params = [];
    parse_str($query, $params);
    return $params;
}

// پردازش هر خط از config.txt
foreach ($configs as $config) {
    // جدا کردن URI و نام
    if (!preg_match('/^(.*?)(#.*)$/', $config, $matches)) continue;
    $uri = $matches[1];
    $nameParts = explode('|', trim($matches[2], '#'));
    if (count($nameParts) < 5) continue;
    
    $name = trim($nameParts[0]);
    $type = trim($nameParts[2]);
    $channel = trim($nameParts[3]);
    $index = trim($nameParts[4]);

    // فقط پروتکل‌های پشتیبانی‌شده
    if (!in_array($type, ['vless', 'trojan', 'vmess', 'ss'])) continue;

    // تجزیه URI
    $proxy = ['name' => "$name | $type | $channel | $index"];
    
    if ($type === 'vless') {
        if (!preg_match('/^vless:\/\/([^@]+)@([^:]+):(\d+)\?(.*)$/', $uri, $matches)) continue;
        $uuid = $matches[1];
        $server = $matches[2];
        $port = (int)$matches[3];
        $query = parseQuery($matches[4]);

        $proxy = array_merge($proxy, [
            'type' => 'vless',
            'server' => $server,
            'port' => $port,
            'uuid' => $uuid,
            'tls' => isset($query['security']) && $query['security'] === 'tls',
            'network' => isset($query['type']) ? $query['type'] : 'tcp',
            'ws-opts' => isset($query['type']) && $query['type'] === 'ws' ? [
                'path' => $query['path'] ?? '/',
                'headers' => ['Host' => $query['host'] ?? '']
            ] : null,
            'grpc-opts' => isset($query['type']) && $query['type'] === 'grpc' ? [
                'grpc-service-name' => $query['serviceName'] ?? ''
            ] : null,
            'sni' => $query['sni'] ?? '',
            'alpn' => isset($query['alpn']) ? explode(',', $query['alpn']) : [],
            'flow' => $query['flow'] ?? ''
        ]);
    } elseif ($type === 'trojan') {
        if (!preg_match('/^trojan:\/\/([^@]+)@([^:]+):(\d+)\?(.*)$/', $uri, $matches)) continue;
        $password = $matches[1];
        $server = $matches[2];
        $port = (int)$matches[3];
        $query = parseQuery($matches[4]);

        $proxy = array_merge($proxy, [
            'type' => 'trojan',
            'server' => $server,
            'port' => $port,
            'password' => $password,
            'tls' => isset($query['security']) && $query['security'] === 'tls',
            'network' => isset($query['type']) ? $query['type'] : 'tcp',
            'ws-opts' => isset($query['type']) && $query['type'] === 'ws' ? [
                'path' => $query['path'] ?? '/',
                'headers' => ['Host' => $query['host'] ?? '']
            ] : null,
            'sni' => $query['sni'] ?? '',
            'alpn' => isset($query['alpn']) ? explode(',', $query['alpn']) : []
        ]);
    } elseif ($type === 'vmess') {
        if (!preg_match('/^vmess:\/\/(.+)$/', $uri, $matches)) continue;
        $vmessData = json_decode(base64_decode($matches[1]), true);
        if (!$vmessData) continue;

        $proxy = array_merge($proxy, [
            'type' => 'vmess',
            'server' => $vmessData['add'],
            'port' => (int)$vmessData['port'],
            'uuid' => $vmessData['id'],
            'alterId' => 0,
            'cipher' => $vmessData['scy'] ?? 'auto',
            'tls' => isset($vmessData['tls']) && $vmessData['tls'] === 'tls',
            'network' => $vmessData['net'] ?? 'tcp',
            'ws-opts' => isset($vmessData['net']) && $vmessData['net'] === 'ws' ? [
                'path' => $vmessData['path'] ?? '/',
                'headers' => ['Host' => $vmessData['host'] ?? '']
            ] : null,
            'sni' => $vmessData['sni'] ?? ''
        ]);
    } elseif ($type === 'ss') {
        if (!preg_match('/^ss:\/\/(.+)@([^:]+):(\d+)\?(.*)$/', $uri, $matches)) continue;
        $cipherPass = base64_decode($matches[1]);
        if (!preg_match('/^(.+):(.+)$/', $cipherPass, $passMatches)) continue;
        $cipher = $passMatches[1];
        $password = $passMatches[2];
        $server = $matches[2];
        $port = (int)$matches[3];

        $proxy = array_merge($proxy, [
            'type' => 'ss',
            'server' => $server,
            'port' => $port,
            'cipher' => $cipher,
            'password' => $password
        ]);
    }

    $yaml['proxies'][] = array_filter($proxy, fn($v) => !is_null($v));
}

// تبدیل به YAML
if (!function_exists('yaml_emit')) {
    die("Error: PHP YAML extension is required.\n");
}
$yamlContent = yaml_emit($yaml, YAML_UTF8_ENCODING);

// نوشتن به فایل
file_put_contents($outputFile, $yamlContent);
echo "Clash config generated: $outputFile\n";
?>
