import { readFile, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';

const VERSION_PATTERN = /^\d+\.\d+\.\d+$/;

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

function replaceExactlyOne(text, pattern, replacement, label) {
  const globalFlags = pattern.flags.includes('g') ? pattern.flags : `${pattern.flags}g`;
  const matchCount = [...text.matchAll(new RegExp(pattern.source, globalFlags))].length;
  if (matchCount !== 1) {
    throw new Error(`Expected exactly one ${label}, found ${matchCount}.`);
  }

  return text.replace(pattern, replacement);
}

function updateReadmeChangelog(readmeText, version) {
  const lines = readmeText.split(/\r?\n/);
  const changelogIndex = lines.findIndex((line) => line.trim() === '== Changelog ==');
  if (changelogIndex === -1) {
    throw new Error('Could not find "== Changelog ==" section in readme.txt.');
  }

  let firstHeadingIndex = -1;
  let firstHeadingVersion = '';
  for (let index = changelogIndex + 1; index < lines.length; index += 1) {
    const trimmed = lines[index].trim();
    if (trimmed === '') {
      continue;
    }

    const headingMatch = trimmed.match(/^=\s*(\d+\.\d+\.\d+)\s*=$/);
    if (headingMatch) {
      firstHeadingIndex = index;
      firstHeadingVersion = headingMatch[1];
      break;
    }

    if (/^==\s*[^=].*==$/.test(trimmed)) {
      break;
    }
  }

  if (firstHeadingIndex === -1) {
    lines.splice(changelogIndex + 1, 0, `= ${version} =`, '* TBD', '');
    return lines.join('\n');
  }

  if (firstHeadingVersion === version) {
    return lines.join('\n');
  }

  lines.splice(firstHeadingIndex, 0, `= ${version} =`, '* TBD', '');
  return lines.join('\n');
}

async function updatePhpAndReadme(version) {
  const [codelliaPhpRaw, readmeRaw] = await Promise.all([
    readFile('codellia.php', 'utf8'),
    readFile('readme.txt', 'utf8'),
  ]);

  let codelliaPhp = codelliaPhpRaw;
  codelliaPhp = replaceExactlyOne(
    codelliaPhp,
    /^(\s*\*\s+Version:\s*)\d+\.\d+\.\d+\s*$/m,
    `$1${version}`,
    'plugin header Version'
  );
  codelliaPhp = replaceExactlyOne(
    codelliaPhp,
    /(define\(\s*'CODELLIA_VERSION',\s*')\d+\.\d+\.\d+('\s*\);)/,
    `$1${version}$2`,
    'CODELLIA_VERSION'
  );

  let readme = readmeRaw;
  readme = replaceExactlyOne(
    readme,
    /^(Stable tag:\s*)\d+\.\d+\.\d+\s*$/m,
    `$1${version}`,
    'Stable tag'
  );
  readme = updateReadmeChangelog(readme, version);

  const writeTasks = [];
  if (codelliaPhp !== codelliaPhpRaw) {
    writeTasks.push(writeFile('codellia.php', codelliaPhp, 'utf8'));
  }
  if (readme !== readmeRaw) {
    writeTasks.push(writeFile('readme.txt', readme, 'utf8'));
  }
  await Promise.all(writeTasks);
}

async function main() {
  const version = process.argv[2];
  if (!VERSION_PATTERN.test(version ?? '')) {
    throw new Error('Usage: npm run version:set -- <x.y.z>');
  }

  runNpm(['version', '--no-git-tag-version', '--allow-same-version', version]);
  await updatePhpAndReadme(version);
  runCommand(process.execPath, ['scripts/version-check.mjs', version]);
  process.stdout.write(`Version synchronized to ${version}\n`);
}

main().catch((error) => {
  fail(error instanceof Error ? error.message : String(error));
});
