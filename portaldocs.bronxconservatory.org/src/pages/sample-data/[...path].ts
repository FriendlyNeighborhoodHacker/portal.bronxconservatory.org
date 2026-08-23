/**
 * Static download endpoint for the sample CSVs: at build time each file in
 * the repo's sample_data/ directory becomes /sample-data/<dir>/<file>.csv,
 * byte-for-byte. Paths come only from getStaticPaths, so nothing outside
 * sample_data/ is ever served.
 */
import type { APIRoute, GetStaticPaths } from 'astro';
import { listSampleDirectories, readSampleFile } from '../../lib/sample-data';

export const getStaticPaths = (() =>
  listSampleDirectories().flatMap((dir) =>
    dir.files.map((file) => ({ params: { path: file.path } }))
  )) satisfies GetStaticPaths;

export const GET: APIRoute = ({ params }) => {
  return new Response(readSampleFile(params.path!), {
    headers: { 'Content-Type': 'text/csv; charset=utf-8' },
  });
};
