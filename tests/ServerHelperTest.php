<?php

declare(strict_types=1);

use DeployTool\Fingerprint;

/** One request to the SSH helper (src/remote-agent.php), run here with PHP as ssh would. */
function agentCall(array $request, string $body = ''): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/src/remote-agent.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($request) . "\n" . $body);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    [$marker, $json, $raw] = explode("\n", $out, 3) + ['', '', ''];
    return [$marker === 'DTAGENT1' ? json_decode($json, true) : null, $raw];
}

test('server helper: writes, reads, hashes like git, deletes, and refuses paths outside', function () {
    $root = Tests::folder('server');
    [$answer] = agentCall(['op' => 'write', 'root' => $root, 'files' => [['app/a.php', 5], ['b.txt', 4]]], "hello\r\nok");
    check($answer['errors'] === [] && file_get_contents("$root/app/a.php") === "hello" && file_get_contents("$root/b.txt") === "\r\nok", 'files written byte for byte');
    [$answer, $raw] = agentCall(['op' => 'read', 'root' => $root, 'paths' => ['app/a.php', 'missing.php']]);
    check($answer['sizes'] === [5, -1] && $raw === 'hello', 'read back; a missing file says so');
    [$answer] = agentCall(['op' => 'hash', 'root' => $root, 'paths' => ['b.txt', 'missing.php']]);
    check($answer['hashes'] === [Fingerprint::blobSha("\r\nok"), null], 'hashes equal git\'s blob hash of the exact bytes');
    [$answer] = agentCall(['op' => 'hash', 'root' => $root, 'paths' => ['../outside.php']]);
    check(str_contains((string) ($answer['error'] ?? ''), 'Not a valid path'), '../ is refused');
    [$answer] = agentCall(['op' => 'delete', 'root' => $root, 'paths' => ['b.txt']]);
    check($answer['deleted'] === ['b.txt'] && !is_file("$root/b.txt"), 'deleted');
});
