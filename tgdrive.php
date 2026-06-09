#!/usr/bin/env php
<?php

const PAGE_SIZE = 10;
const MAX_FILE_SIZE = 52428800;
const SHELL_TIMEOUT = 30;
const API_BASE = 'https://api.telegram.org/bot';

$tg_token = '';
$proxy_url = '';
$user_ids = [];

$home_dir = posix_getpwuid(posix_getuid())['dir'] ?: '/root';
$config_dir = $home_dir . '/.config/tgdrive';
$config_file = $config_dir . '/config.php';

if (!file_exists($config_file)) {
    if (!is_dir($config_dir)) {
        mkdir($config_dir, 0755, true);
    }
    $template = "<?php\n// \$tg_token = '';\n// \$proxy_url = '';\n// \$user_ids = [];\n";
    file_put_contents($config_file, $template);
    echo "Config created at {$config_file}\nPlease fill in the configuration and restart.\n";
    exit(1);
}

require $config_file;

if (empty($tg_token) || empty($user_ids)) {
    echo "Please configure {$config_file}\n";
    exit(1);
}

$states = [];
$last_update_id = 0;

function tg(string $method, array $params = []): mixed
{
    global $tg_token, $proxy_url;

    $ch = curl_init(API_BASE . $tg_token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => $params,
    ]);

    if (!empty($proxy_url)) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy_url);
    }

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    return $data['ok'] ? $data['result'] : null;
}

function tg_send_document(int $chat_id, string $file_path, ?int $reply_to = null): void
{
    global $tg_token, $proxy_url;

    if (!file_exists($file_path)) {
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => 'File not found: ' . basename($file_path),
        ]);
        return;
    }

    $fsize = filesize($file_path);
    if ($fsize > MAX_FILE_SIZE) {
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => sprintf('File too large (%d MB). Limit: 50 MB', round($fsize / 1048576, 1)),
        ]);
        return;
    }

    $ch = curl_init(API_BASE . $tg_token . '/sendDocument');
    $params = [
        'chat_id' => $chat_id,
        'document' => new CURLFile($file_path),
    ];
    if ($reply_to !== null) {
        $params['reply_to_message_id'] = $reply_to;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => $params,
    ]);

    if (!empty($proxy_url)) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy_url);
    }

    curl_exec($ch);
    curl_close($ch);
}

function tg_download_file(string $file_id, string $save_path): bool
{
    global $tg_token, $proxy_url;

    $file_info = tg('getFile', ['file_id' => $file_id]);
    if ($file_info === null || !isset($file_info['file_path'])) {
        return false;
    }

    $url = 'https://api.telegram.org/file/bot' . $tg_token . '/' . $file_info['file_path'];

    $fp = fopen($save_path, 'wb');
    if ($fp === false) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    if (!empty($proxy_url)) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy_url);
    }

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$result || $http_code !== 200) {
        return false;
    }

    return true;
}

function send_long_message(int $chat_id, string $text): void
{
    $chunks = mb_str_split($text, 4096);
    foreach ($chunks as $chunk) {
        if ($chunk === '') continue;
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $chunk,
        ]);
    }
}

function &get_state(int $user_id): array
{
    global $states, $home_dir;
    if (!isset($states[$user_id])) {
        $states[$user_id] = [
            'cwd' => $home_dir,
            'show_hidden' => false,
            'page' => 0,
            'msg_id' => null,
            'entries' => [],
        ];
    }
    return $states[$user_id];
}

function resolve_path(string $base, string $target): string
{
    global $home_dir;
    if (str_starts_with($target, '~')) {
        $target = $home_dir . substr($target, 1);
    }
    if (!str_starts_with($target, '/')) {
        $target = $base . '/' . $target;
    }
    $real = realpath($target);
    return $real ?: $target;
}

function unique_path(string $dir, string $filename): string
{
    $path = $dir . '/' . $filename;
    if (!file_exists($path)) {
        return $filename;
    }

    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $name = $ext !== ''
        ? pathinfo($filename, PATHINFO_FILENAME)
        : $filename;

    $i = 1;
    while (true) {
        $candidate = $ext !== ''
            ? sprintf('%s (%d).%s', $name, $i, $ext)
            : sprintf('%s (%d)', $name, $i);
        if (!file_exists($dir . '/' . $candidate)) {
            return $candidate;
        }
        $i++;
    }
}

function scan_entries(string $cwd, bool $show_hidden): array
{
    $entries = [];
    if (!is_dir($cwd) || !is_readable($cwd)) {
        return $entries;
    }
    $items = scandir($cwd);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (!$show_hidden && str_starts_with($item, '.')) continue;
        $full = $cwd . '/' . $item;
        $real = realpath($full) ?: $full;
        $entries[] = ['name' => $item, 'path' => $real, 'is_dir' => is_dir($real)];
    }
    usort($entries, function ($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
        return strcasecmp($a['name'], $b['name']);
    });
    return $entries;
}

function build_keyboard(array $state): array
{
    global $home_dir;
    $cwd = $state['cwd'];
    $page = $state['page'];

    $entries = $state['entries'];
    $total = count($entries);
    $offset = $page * PAGE_SIZE;
    $slice = array_slice($entries, $offset, PAGE_SIZE);

    $rows = [];

    if ($cwd !== '/') {
        $rows[] = [
            ['text' => '..', 'callback_data' => 'up'],
        ];
    }

    foreach ($slice as $idx => $entry) {
        $global_idx = $offset + $idx;
        $prefix = $entry['is_dir'] ? '📁 ' : '📄 ';
        $cb_prefix = $entry['is_dir'] ? 'd:' : 'f:';
        $rows[] = [
            ['text' => $prefix . $entry['name'], 'callback_data' => $cb_prefix . $global_idx],
        ];
    }

    $nav_row = [];
    $has_prev = $offset > 0;
    $has_next = ($offset + PAGE_SIZE) < $total;

    if ($has_prev) {
        $nav_row[] = ['text' => '◀️', 'callback_data' => 'page:' . ($offset - PAGE_SIZE)];
    }
    $nav_row[] = ['text' => '🏠', 'callback_data' => 'home'];
    $nav_row[] = [
        'text' => $state['show_hidden'] ? '👁‍🗨' : '👁',
        'callback_data' => 'toggle_hidden',
    ];
    $nav_row[] = ['text' => '🔄', 'callback_data' => 'refresh'];
    if ($has_next) {
        $nav_row[] = ['text' => '▶️', 'callback_data' => 'page:' . ($offset + PAGE_SIZE)];
    }

    $rows[] = $nav_row;

    return ['inline_keyboard' => $rows];
}

function refresh_state(array &$state): void
{
    $state['entries'] = scan_entries($state['cwd'], $state['show_hidden']);
}

function show_listing(int $chat_id, array &$state, ?int $callback_msg_id = null): void
{
    refresh_state($state);
    $keyboard = build_keyboard($state);
    $text = '📂 ' . $state['cwd'];

    if ($callback_msg_id !== null) {
        $result = tg('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $callback_msg_id,
            'text' => $text,
            'reply_markup' => json_encode($keyboard),
        ]);
        if ($result !== null) {
            $state['msg_id'] = $callback_msg_id;
        }
    } elseif ($state['msg_id'] !== null) {
        $result = tg('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $state['msg_id'],
            'text' => $text,
            'reply_markup' => json_encode($keyboard),
        ]);
        if ($result === null) {
            $result = tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $text,
                'reply_markup' => json_encode($keyboard),
            ]);
            if ($result) {
                $state['msg_id'] = $result['message_id'];
            }
        }
    } else {
        $result = tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $text,
            'reply_markup' => json_encode($keyboard),
        ]);
        if ($result) {
            $state['msg_id'] = $result['message_id'];
        }
    }
}

function exec_shell(string $command, string $cwd): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd);

    if (!is_resource($process)) {
        return ['output' => 'Failed to execute command', 'exit_code' => -1];
    }

    fclose($pipes[0]);

    $stdout = '';
    $stderr = '';

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $start = time();

    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) break;
        if (time() - $start > SHELL_TIMEOUT) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return ['output' => 'Command timed out (> 30s)', 'exit_code' => -1];
        }

        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;

        if (stream_select($read, $write, $except, 1) > 0) {
            foreach ($read as $pipe) {
                $data = fread($pipe, 8192);
                if ($pipe === $pipes[1]) {
                    $stdout .= $data;
                } else {
                    $stderr .= $data;
                }
            }
        }
    }

    while (!feof($pipes[1])) {
        $data = fread($pipes[1], 8192);
        if ($data === false || $data === '') break;
        $stdout .= $data;
    }
    while (!feof($pipes[2])) {
        $data = fread($pipes[2], 8192);
        if ($data === false || $data === '') break;
        $stderr .= $data;
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit_code = $status['exitcode'];
    proc_close($process);

    $output = trim($stdout . "\n" . $stderr);

    return ['output' => $output, 'exit_code' => $exit_code];
}

function handle_callback(array $callback): void
{
    global $user_ids, $states, $home_dir;

    $chat_id = $callback['message']['chat']['id'];
    $user_id = $callback['from']['id'];
    $msg_id = $callback['message']['message_id'];
    $data = $callback['id'];

    if (!in_array($user_id, $user_ids)) {
        tg('answerCallbackQuery', ['callback_query_id' => $data, 'text' => 'Access denied']);
        return;
    }

    $cb_data = $callback['data'];
    $state = &get_state($user_id);

    if ($cb_data === 'up') {
        $path = dirname($state['cwd']);
        if ($path !== $state['cwd'] && is_dir($path) && is_readable($path)) {
            $state['cwd'] = realpath($path) ?: $path;
            $state['page'] = 0;
        }
        show_listing($chat_id, $state, $msg_id);
    } elseif ($cb_data === 'home') {
        $state['cwd'] = $home_dir;
        $state['page'] = 0;
        show_listing($chat_id, $state, $msg_id);
    } elseif ($cb_data === 'toggle_hidden') {
        $state['show_hidden'] = !$state['show_hidden'];
        $state['page'] = 0;
        show_listing($chat_id, $state, $msg_id);
    } elseif ($cb_data === 'refresh') {
        show_listing($chat_id, $state, $msg_id);
    } elseif (str_starts_with($cb_data, 'd:')) {
        $idx = (int) substr($cb_data, 2);
        $entries = $state['entries'] ?? [];
        if (!isset($entries[$idx])) {
            tg('answerCallbackQuery', ['callback_query_id' => $data, 'text' => 'Directory not found']);
            return;
        }
        $path = $entries[$idx]['path'];
        if (!is_dir($path) || !is_readable($path)) {
            tg('answerCallbackQuery', ['callback_query_id' => $data, 'text' => 'Access denied']);
            return;
        }
        $state['cwd'] = $path;
        $state['page'] = 0;
        show_listing($chat_id, $state, $msg_id);
    } elseif (str_starts_with($cb_data, 'f:')) {
        $idx = (int) substr($cb_data, 2);
        $entries = $state['entries'] ?? [];
        if (!isset($entries[$idx])) {
            tg('answerCallbackQuery', ['callback_query_id' => $data, 'text' => 'File not found']);
            return;
        }
        $path = $entries[$idx]['path'];
        tg('answerCallbackQuery', ['callback_query_id' => $data, 'text' => 'Sending file...']);
        tg_send_document($chat_id, $path);
        return;
    } elseif (str_starts_with($cb_data, 'page:')) {
        $offset = (int) substr($cb_data, 5);
        $state['page'] = (int) floor($offset / PAGE_SIZE);
        show_listing($chat_id, $state, $msg_id);
    }

    tg('answerCallbackQuery', ['callback_query_id' => $data]);
}

function handle_message(array $message): void
{
    global $user_ids, $states, $home_dir;

    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $text = $message['text'] ?? '';

    if (!in_array($user_id, $user_ids)) {
        tg('sendMessage', ['chat_id' => $chat_id, 'text' => 'Access denied']);
        return;
    }

    $state = &get_state($user_id);

    if (str_starts_with($text, '/start')) {
        $states[$user_id] = [
            'cwd' => $home_dir,
            'show_hidden' => false,
            'page' => 0,
            'msg_id' => null,
            'entries' => [],
        ];
        $state = &$states[$user_id];
        tg('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "📂 File Navigator\nHome: {$home_dir}",
        ]);
        show_listing($chat_id, $state);
        return;
    }

    if ($text === '/pwd') {
        tg('sendMessage', ['chat_id' => $chat_id, 'text' => $state['cwd']]);
        return;
    }

    if ($text === '/ls') {
        $entries = scan_entries($state['cwd'], $state['show_hidden']);
        $lines = [];
        foreach ($entries as $entry) {
            $prefix = $entry['is_dir'] ? '📁 ' : '📄 ';
            $lines[] = $prefix . $entry['name'];
        }
        $output = empty($lines) ? '(empty)' : implode("\n", $lines);
        send_long_message($chat_id, $output);
        return;
    }

    if (str_starts_with($text, '/cd ')) {
        $target = trim(substr($text, 4));
        if ($target === '') {
            tg('sendMessage', ['chat_id' => $chat_id, 'text' => 'Usage: /cd <path>']);
            return;
        }
        $path = resolve_path($state['cwd'], $target);
        if (!is_dir($path)) {
            tg('sendMessage', ['chat_id' => $chat_id, 'text' => 'Directory not found: ' . $path]);
            return;
        }
        if (!is_readable($path)) {
            tg('sendMessage', ['chat_id' => $chat_id, 'text' => 'Access denied: ' . $path]);
            return;
        }
        $state['cwd'] = $path;
        $state['page'] = 0;
        show_listing($chat_id, $state);
        return;
    }

    if (isset($message['document'])) {
        $doc = $message['document'];
        $file_id = $doc['file_id'];
        $filename = $doc['file_name'] ?? ('file_' . $message['message_id']);

        if (isset($doc['file_size']) && $doc['file_size'] > MAX_FILE_SIZE) {
            tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => sprintf('File too large (%d MB). Limit: 50 MB', round($doc['file_size'] / 1048576, 1)),
            ]);
            return;
        }

        $save_name = unique_path($state['cwd'], $filename);
        $save_path = $state['cwd'] . '/' . $save_name;

        $ok = tg_download_file($file_id, $save_path);

        if ($ok) {
            tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'Saved: ' . $save_name,
            ]);
            show_listing($chat_id, $state);
        } else {
            @unlink($save_path);
            tg('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'Failed to download file',
            ]);
        }
        return;
    }

    if ($text !== '') {
        $result = exec_shell($text, $state['cwd']);
        $output = $result['output'];
        if ($output === '') {
            $output = '(empty output)';
        }
        if ($result['exit_code'] !== 0) {
            $output .= "\nexit code: " . $result['exit_code'];
        }
        send_long_message($chat_id, $output);
    }
}

tg('deleteWebhook');
$res = tg('deleteMyCommands');
$res = tg('setMyCommands', [
    'commands' => json_encode([
        ['command' => 'start', 'description' => 'Reset to home directory'],
        ['command' => 'pwd', 'description' => 'Print current directory'],
        ['command' => 'ls', 'description' => 'List files in current directory'],
        ['command' => 'cd', 'description' => 'Change directory'],
    ]),
]);
if ($res === null) echo "Warning: setMyCommands failed\n";
else echo "Commands menu updated\n";

echo "tgdrive started. Home: {$home_dir}\n";

while (true) {
    $updates = tg('getUpdates', [
        'offset' => $last_update_id + 1,
        'timeout' => 30,
        'allowed_updates' => json_encode(['message', 'callback_query']),
    ]);

    if ($updates === null) {
        sleep(1);
        continue;
    }

    foreach ($updates as $update) {
        $last_update_id = $update['update_id'];

        if (isset($update['callback_query'])) {
            handle_callback($update['callback_query']);
        } elseif (isset($update['message'])) {
            handle_message($update['message']);
        }
    }
}
