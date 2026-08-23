/**
 * Build-time listing of the repo's sample_data/ CSVs.
 *
 * The files are read straight out of ../sample_data at build time — the docs
 * site serves the same bytes the repo carries, with no copies to keep in
 * sync. Used by the /sample-data listing page and the download endpoint that
 * emits each file.
 */
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';

/**
 * The repo's sample_data directory, found by walking up from the build's
 * working directory (import.meta.url is useless here — the module is bundled
 * into dist/ before prerendering runs).
 */
function findSampleDataDir(): string {
  let dir = process.cwd();
  for (let i = 0; i < 5; i++) {
    const candidate = join(dir, 'sample_data');
    if (existsSync(candidate)) return candidate;
    dir = dirname(dir);
  }
  throw new Error('sample_data directory not found above ' + process.cwd());
}

export const SAMPLE_DATA_DIR = findSampleDataDir();

export interface SampleFile {
  /** "fall_semester/location_dates.csv" — also the download path segment. */
  path: string;
  name: string;
  /** Data rows, excluding the header. */
  rows: number;
  bytes: number;
}

export interface SampleDirectory {
  name: string;
  files: SampleFile[];
}

/** The walkthrough order sample_data/README.md prescribes. */
const DIRECTORY_ORDER = ['general', 'fall_semester', 'spring_semester'];

export function listSampleDirectories(): SampleDirectory[] {
  const found = readdirSync(SAMPLE_DATA_DIR, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => entry.name);
  const ordered = [
    ...DIRECTORY_ORDER.filter((name) => found.includes(name)),
    ...found.filter((name) => !DIRECTORY_ORDER.includes(name)).sort(),
  ];
  return ordered.map((name) => ({
    name,
    files: readdirSync(join(SAMPLE_DATA_DIR, name))
      .filter((file) => file.endsWith('.csv'))
      .sort()
      .map((file) => {
        const full = join(SAMPLE_DATA_DIR, name, file);
        const lines = readFileSync(full, 'utf8').split('\n').filter((line) => line.trim() !== '');
        return {
          path: `${name}/${file}`,
          name: file,
          rows: Math.max(0, lines.length - 1),
          bytes: statSync(full).size,
        };
      }),
  }));
}

export function readSampleFile(path: string): Buffer {
  return readFileSync(join(SAMPLE_DATA_DIR, path));
}
