import { access, copyFile, cp, mkdir, readdir, readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(scriptDir, '..');

const monacoDir = path.join(rootDir, 'node_modules', 'monaco-editor');
const sourceVsDir = path.join(monacoDir, 'dev', 'vs');

const outputDir = path.join(rootDir, 'assets', 'monaco');
const outputVsDir = path.join(outputDir, 'vs');

// BEGIN: Temporary (remove this block once no longer needed).
const TEMP_WPORG_REVIEW_WORKAROUND = {
  enabled: true,
  async apply(vsDir) {
    const editorApiDocImagePattern = /^.*raw\.githubusercontent\.com\/microsoft\/vscode\/.*\r?\n?/gm;
    const kendoBlockPattern = /^[ \t]*"Kendo":\s*\{\r?\n[\s\S]*?^[ \t]*\},?\r?\n?/gm;
    const forbiddenReviewPatterns = [
      {
        label: 'raw.githubusercontent.com reference',
        pattern: /raw\.githubusercontent\.com\/microsoft\/vscode/,
      },
      {
        label: 'Kendo auto-type block',
        pattern: /"Kendo"\s*:/,
      },
      {
        label: 'kendo-ui type package reference',
        pattern: /types:\s*\[\s*["']kendo-ui["']\s*\]/,
      },
    ];

    const normalizeRelativePath = (inputPath) => inputPath.replace(/\\/g, '/');
    const countMatches = (content, pattern) => {
      const matches = content.match(pattern);
      return matches ? matches.length : 0;
    };
    const collectJsFiles = async (currentDir = vsDir) => {
      const entries = await readdir(currentDir, { withFileTypes: true });
      const files = [];

      for (const entry of entries) {
        const absolutePath = path.join(currentDir, entry.name);
        if (entry.isDirectory()) {
          files.push(...(await collectJsFiles(absolutePath)));
          continue;
        }
        if (entry.isFile() && entry.name.endsWith('.js')) {
          files.push(absolutePath);
        }
      }

      return files;
    };
    const assertForbiddenPatternsRemoved = async () => {
      const jsFiles = await collectJsFiles();
      const findings = [];

      for (const filePath of jsFiles) {
        const content = await readFile(filePath, 'utf8');
        for (const rule of forbiddenReviewPatterns) {
          if (rule.pattern.test(content)) {
            findings.push(`${normalizeRelativePath(path.relative(rootDir, filePath))}: ${rule.label}`);
          }
        }
      }

      if (findings.length > 0) {
        throw new Error(
          `Temporary WordPress.org review workaround failed. Forbidden patterns remain:\n${findings.join('\n')}`
        );
      }
    };

    const jsFiles = await collectJsFiles();
    const stats = {
      scanned: jsFiles.length,
      changed: 0,
      removedDocImageLines: 0,
      removedKendoBlocks: 0,
    };

    for (const filePath of jsFiles) {
      const fileName = path.basename(filePath);
      const isEditorApiFile = fileName.startsWith('editor.api-');
      const isTsWorkerFile = fileName.startsWith('ts.worker-');
      if (!isEditorApiFile && !isTsWorkerFile) {
        continue;
      }

      const source = await readFile(filePath, 'utf8');
      let next = source;

      if (isEditorApiFile) {
        const removedDocLines = countMatches(next, editorApiDocImagePattern);
        if (removedDocLines > 0) {
          next = next.replace(editorApiDocImagePattern, '');
          stats.removedDocImageLines += removedDocLines;
        }
      }

      if (isTsWorkerFile) {
        const removedKendoBlocks = countMatches(next, kendoBlockPattern);
        if (removedKendoBlocks > 0) {
          next = next.replace(kendoBlockPattern, '');
          stats.removedKendoBlocks += removedKendoBlocks;
        }
      }

      if (next !== source) {
        await writeFile(filePath, next, 'utf8');
        stats.changed += 1;
      }
    }

    await assertForbiddenPatternsRemoved();
    process.stdout.write(
      `Applied temporary WordPress.org review workaround (scanned=${stats.scanned}, changed=${stats.changed}, removedDocImageLines=${stats.removedDocImageLines}, removedKendoBlocks=${stats.removedKendoBlocks}).\n`
    );
  },
};
// END: Temporary.

async function fileExists(targetPath) {
  try {
    await access(targetPath);
    return true;
  } catch {
    return false;
  }
}

async function syncOptionalFile(sourcePath, destPath) {
  if (await fileExists(sourcePath)) {
    await copyFile(sourcePath, destPath);
  }
}

async function main() {
  if (!(await fileExists(sourceVsDir))) {
    throw new Error(
      'Monaco AMD assets were not found at node_modules/monaco-editor/dev/vs. Run "npm install" first.'
    );
  }

  await rm(outputVsDir, { recursive: true, force: true });
  await mkdir(outputDir, { recursive: true });
  await cp(sourceVsDir, outputVsDir, { recursive: true });

  await syncOptionalFile(path.join(monacoDir, 'LICENSE'), path.join(outputDir, 'LICENSE'));
  await syncOptionalFile(
    path.join(monacoDir, 'ThirdPartyNotices.txt'),
    path.join(outputDir, 'ThirdPartyNotices.txt')
  );

  if (TEMP_WPORG_REVIEW_WORKAROUND.enabled) {
    await TEMP_WPORG_REVIEW_WORKAROUND.apply(outputVsDir);
  }

  process.stdout.write('Prepared Monaco assets in assets/monaco.\n');
}

main().catch((error) => {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
  process.exitCode = 1;
});
