import { copyFile, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';

const VERSION_PATTERN = /^\d+\.\d+\.\d+$/;
const ZIP_FILE = 'kayzart-live-code-editor.zip';

const trackedVersionFiles = [
  'package.json',
  'package-lock.json',
  'kayzart-live-code-editor.php',
  'readme.txt',
];

function fail(message) {
  process.stderr.write(`${message}\n`);
  process.exitCode = 1;
}

function runCommand(command, args) {
  const result = spawnSync(command, args, { stdio: 'inherit' });
  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    throw new Error(`Command failed: ${command} ${args.join(' ')}`);
  }
}

function runNpm(args) {
  if (typeof process.env.npm_execpath === 'string' && process.env.npm_execpath !== '') {
    runCommand(process.execPath, [process.env.npm_execpath, ...args]);
    return;
  }

  const npmCommand = process.platform === 'win32' ? 'npm.cmd' : 'npm';
  runCommand(npmCommand, args);
}

async function snapshotFiles(paths) {
  const entries = await Promise.all(
    paths.map(async (filePath) => [filePath, await readFile(filePath)])
  );
  return new Map(entries);
}

async function restoreFiles(snapshot) {
  await Promise.all(
    Array.from(snapshot.entries()).map(([filePath, content]) => writeFile(filePath, content))
  );
}

async function snapshotOptionalFile(filePath) {
  try {
    await stat(filePath);
    return await readFile(filePath);
  } catch (error) {
    if (error && error.code === 'ENOENT') {
      return null;
    }
    throw error;
  }
}

async function restoreOptionalFile(filePath, content) {
  if (content === null) {
    await rm(filePath, { force: true });
    return;
  }
  await writeFile(filePath, content);
}

async function main() {
  const version = process.argv[2];
  if (!VERSION_PATTERN.test(version ?? '')) {
    throw new Error('Usage: npm run plugin-zip:version -- <x.y.z>');
  }

  const outputZip = `kayzart-live-code-editor-${version}.zip`;
  const snapshot = await snapshotFiles(trackedVersionFiles);
  const originalZip = await snapshotOptionalFile(ZIP_FILE);
  let restoreError = null;

  try {
    runNpm(['run', 'version:set', '--', version]);
    runNpm(['run', 'plugin-zip']);
    await copyFile(ZIP_FILE, outputZip);
    process.stdout.write(`Created ${outputZip}\n`);
  } finally {
    try {
      await restoreFiles(snapshot);
      await restoreOptionalFile(ZIP_FILE, originalZip);
    } catch (error) {
      restoreError = error;
    }
  }

  if (restoreError) {
    throw restoreError;
  }
}

main().catch((error) => {
  fail(error instanceof Error ? error.message : String(error));
});
