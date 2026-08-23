/**
 * Build-time listing of the repo's sample_data/ CSVs.
 *
 * The files are read straight out of ../sample_data at build time — the docs
 * site serves the same bytes the repo carries, with no copies to keep in
 * sync. Used by the /sample-data listing page and the download endpoint that
 * emits each file.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

/** Absolute path of the repo's sample_data directory. */
export const SAMPLE_DATA_DIR = fileURLToPath(new URL('../../../sample_data', import.meta.url));

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
