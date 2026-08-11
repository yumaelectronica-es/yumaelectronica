<?php
/**
 * Resolves credential config files (db-config.php, mail-config.php) from a
 * folder OUTSIDE the Git-deployed public_html tree, so they survive future
 * redeploys — Hostinger's Git deployment resets public_html to match the
 * repo, wiping any file that isn't tracked in Git (which credential files
 * deliberately never are). Falls back to the old in-repo location so
 * nothing breaks if a file hasn't been moved to the new spot yet.
 */
function ye_config_path($filename) {
    $outside = dirname(__DIR__, 3) . '/ye-secrets/' . $filename;
    if (is_file($outside)) return $outside;
    return __DIR__ . '/' . $filename;
}
