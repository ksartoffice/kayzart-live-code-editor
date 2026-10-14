import { afterAll, describe, expect, it } from 'vitest';
import { existsSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';

const bash = process.platform === 'win32' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash';
const root = mkdtempSync(path.join(tmpdir(), 'kayzart-installer-test-'));
mkdirSync(path.join(root, 'wp-includes'));
writeFileSync(path.join(root, 'wp-includes/version.php'), "<?php\n$wp_version = '6.9.3';\n");
const installer = readFileSync('bin/install-wp-tests.sh', 'utf8');
const definition = installer.match(/install_test_suite\(\) \{[\s\S]*?\n\}/)![0];
afterAll(() => {
  if (!root.startsWith(path.join(tmpdir(), 'kayzart-installer-test-'))) throw new Error('Unexpected fixture path');
  rmSync(root, { recursive: true, force: true });
});

describe.skipIf(process.platform === 'win32' && !existsSync(bash))('WordPress test archive selection', () => {
  it.each([
    ['latest', 'tags/6.9.3'],
    ['5.9', 'tags/5.9'],
    ['nightly', 'heads/trunk'],
    ['trunk', 'heads/trunk'],
  ])('pairs %s with the correct tests', (version, ref) => {
    // Exercise the real selector, stopping at download. No network or deletion.
    const harness = `WP_VERSION="$1"\nWP_CORE_DIR="$INSTALLER_TEST_CORE"\nWP_TESTS_DIR="$INSTALLER_TEST_CORE/not-installed"\nTMPDIR="$INSTALLER_TEST_CORE"\nrm() { :; }\nmkdir() { :; }\ndownload() { printf '%s' "$1"; exit 0; }\n${definition}\ninstall_test_suite`;
    const output = execFileSync(bash, ['-c', harness, 'installer-test', version], {
      encoding: 'utf8', env: { ...process.env, INSTALLER_TEST_CORE: root.replaceAll('\\', '/') },
    });
    expect(output).toContain(`https://github.com/WordPress/wordpress-develop/archive/refs/${ref}.zip`);
    if (version === 'latest') expect(output).not.toContain('heads/trunk');
  });
});
