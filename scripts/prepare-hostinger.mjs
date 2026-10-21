import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { copyFile, lstat, readFile, readdir, writeFile } from 'node:fs/promises';
import { basename, join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { manifestFiles, resolveManifest } from './manifest.mjs';

const base = '/nhhr/';
const rules = new URL('../deploy/hostinger.htaccess', import.meta.url);

// Vite records the manifest used to compile the application, so an earlier
// build:all cannot be relabelled as the published build during packaging.
export function buildReceipt(manifest, buildBase, source) {
  return {
    source: source || process.env.GITHUB_SHA || execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(),
    base: buildBase,
    all: manifest.all,
    manifestSha256: createHash('sha256').update(JSON.stringify({
      files: manifestFiles.map(path => [basename(path), readFileSync(path, 'utf8')])
        .sort(([a], [b]) => a.localeCompare(b)),
      flags: manifest.flags,
      stories: [...manifest.enabledStories].sort(),
      storyMaps: [...manifest.enabledStoryMaps].sort(),
      datasets: [...manifest.datasets].sort(),
    })).digest('hex'),
  };
}

function sameReceipt(actual, expected) {
  return ['source', 'base', 'all', 'manifestSha256'].every(key => actual[key] === expected[key]);
}

async function inspect(directory, prefix = '') {
  const files = [];
  for (const name of await readdir(directory)) {
    const path = prefix ? `${prefix}/${name}` : name;
    if ((name.startsWith('.') && path !== '.htaccess') || name === '__clerk'
      || ['src', 'scripts', 'deploy', 'manifest', 'node_modules'].includes(name)
      || /^(?:package(?:-lock)?\.json|(?:tsconfig|vite\.config)(?:\..+)?)$/i.test(name)
      || /\.(?:php\d*|phtml|phar)(?:\.|$)/i.test(name)
      || /\.(?:pem|key|p12|pfx|tsx?|jsx)$/i.test(name)) {
      throw new Error(`Unsafe release path: ${path}`);
    }
    const info = await lstat(join(directory, name));
    if (info.isSymbolicLink() || (!info.isDirectory() && !info.isFile()))
      throw new Error(`Release paths must be regular files or directories: ${path}`);
    if (info.isDirectory()) files.push(...await inspect(join(directory, name), path));
    else files.push(path);
  }
  return files;
}

async function prepare(output, check) {
  if (process.env.ATLAS_MANIFEST === 'all') throw new Error('Hostinger requires the published manifest, never ATLAS_MANIFEST=all');
  const outputInfo = await lstat(output);
  if (!outputInfo.isDirectory() || outputInfo.isSymbolicLink()) throw new Error('Release output must be a real directory');
  const source = execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
  if (!/^[0-9a-f]{40}$/.test(source) || (process.env.GITHUB_SHA && process.env.GITHUB_SHA !== source))
    throw new Error('Release source must match the checked-out commit');
  const manifest = resolveManifest({ all: false });
  const expected = buildReceipt(manifest, base, source);
  const receipt = JSON.parse(await readFile(join(output, 'atlas-build.json'), 'utf8'));
  if (!sameReceipt(receipt, expected))
    throw new Error('Build receipt does not match the source, /nhhr/ base and published manifest; rebuild with npm run build');

  const files = await inspect(output);
  const html = await readFile(join(output, 'index.html'), 'utf8');
  const urls = [...html.matchAll(/(?:src|href)=["'](\/[^"'?#]*)/g)].map(match => match[1]);
  if (!urls.some(url => url.startsWith(`${base}assets/`)) || urls.some(url => !url.startsWith(base)))
    throw new Error('Release assets must use ATLAS_BASE_PATH=/nhhr/');
  for (const url of urls) {
    const path = decodeURIComponent(url.slice(base.length));
    if (path.split('/').some(part => part === '.' || part === '..') || !files.includes(path))
      throw new Error(`Missing or unsafe release asset: ${url}`);
  }

  const { flags, enabledStoryMaps } = manifest;
  const stories = [...enabledStoryMaps];
  const routes = [
    ...(flags.projects.atlas ? [...(flags.pages.home ? ['stories'] : []), ...stories] : []),
    ...(flags.projects.workspace ? ['dev/projects', ...stories.map(id => `dev/projects/${id}`)] : []),
    ...(flags.projects.campus ? ['campus'] : []),
  ];
  const pages = ['index.html', '404.html', ...routes.map(path => `${path}/index.html`)].sort();
  if (JSON.stringify(files.filter(path => path.endsWith('.html')).sort()) !== JSON.stringify(pages))
    throw new Error('Release entry pages do not match the published manifest');
  for (const page of pages) {
    if (await readFile(join(output, page), 'utf8') !== html)
      throw new Error(`Release entry page differs from index.html: ${page}`);
  }

  if (check) {
    const release = JSON.parse(await readFile(join(output, 'release.json'), 'utf8'));
    if (!sameReceipt(release, expected)) throw new Error('Packaged release metadata does not match this deployment');
    if (await readFile(join(output, '.htaccess'), 'utf8') !== await readFile(rules, 'utf8'))
      throw new Error('Packaged serving rules do not match this deployment');
  } else {
    await copyFile(rules, join(output, '.htaccess'));
    await writeFile(join(output, 'release.json'), `${JSON.stringify(expected, null, 2)}\n`);
  }
  console.log(`Validated published /nhhr/ release for ${source}`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const args = process.argv.slice(2);
  const check = args[0] === '--check';
  if (check) args.shift();
  if (args.length > 1 || args[0]?.startsWith('-')) throw new Error('Usage: node scripts/prepare-hostinger.mjs [--check] [dist]');
  await prepare(resolve(args[0] || 'dist'), check);
}
