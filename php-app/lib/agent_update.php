<?php
/**
 * WorkFolio PHP - agent self-update.
 *
 * The desktop agent asks what the current build is; if the server's is newer it
 * downloads it and replaces itself on the far machine, so an employee never has
 * to reinstall anything by hand.
 *
 * The release lives as two plain files beside this app, served directly by the
 * web server:
 *   agent/WorkFolioAgent.exe   the installed binary
 *   agent/version.json         {"version":"1.3.0","notes":"..."}
 * version.json is the source of truth, so publishing a build is a copy of those
 * two files - no code change, no database row.
 */
require_once __DIR__ . '/core.php';

/** Where the release files live on disk and how they are reached over HTTP. */
function agent_release_dir(): string {
    return dirname(__DIR__) . '/agent';
}

function agent_release_info(): array {
    $dir = agent_release_dir();
    $meta = $dir . '/version.json';
    $exe = $dir . '/WorkFolioAgent.exe';

    $version = '';
    $notes = '';
    if (is_file($meta)) {
        $decoded = json_decode((string) file_get_contents($meta), true);
        if (is_array($decoded)) {
            $version = trim((string) ($decoded['version'] ?? ''));
            $notes = (string) ($decoded['notes'] ?? '');
        }
    }
    $hasExe = is_file($exe);

    return [
        'version'   => $version,
        'url'       => base_url('/agent/WorkFolioAgent.exe'),
        'size'      => $hasExe ? (int) filesize($exe) : 0,
        'sha256'    => $hasExe ? (string) hash_file('sha256', $exe) : '',
        'notes'     => $notes,
        'available' => $hasExe && $version !== '',
    ];
}

/** The agent checks this on every sign-in; no token, because it is not secret. */
function api_agent_update(): array {
    return array_merge(['ok' => true], agent_release_info());
}
