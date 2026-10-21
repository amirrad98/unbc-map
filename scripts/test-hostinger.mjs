import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { buildReceipt } from './prepare-hostinger.mjs';
import { resolveManifest } from './manifest.mjs';

const prepare = resolve('scripts/prepare-hostinger.mjs');
const publish = resolve('scripts/publish-hostinger.sh');
const manifest = resolveManifest({ all: false });

function command(cwd, executable, args, { fail = false, env = {} } = {}) {
  const result = spawnSync(executable, args, {
    cwd, encoding: 'utf8',
    env: { ...process.env, GITHUB_SHA: '', ATLAS_MANIFEST: '', ...env },
  });
  if (fail) assert.notEqual(result.status, 0, result.stdout);
  else assert.equal(result.status, 0, result.stderr + result.stdout);
  return result.stdout.trim();
}

function initialize(root) {
  command(root, 'git', ['init', '-b', 'main']);
  command(root, 'git', ['config', 'user.name', 'Deployment test']);
  command(root, 'git', ['config', 'user.email', 'test@example.invalid']);
  writeFileSync(join(root, 'source-only.txt'), 'must not be published');
  command(root, 'git', ['add', 'source-only.txt']);
  command(root, 'git', ['commit', '-m', 'source one']);
}

function artifact(root, { base = '/nhhr/', all = false } = {}) {
  const output = join(root, 'dist');
  rmSync(output, { recursive: true, force: true });
  const { flags, enabledStoryMaps } = manifest;
  const stories = [...enabledStoryMaps];
  const routes = [
    ...(flags.projects.atlas ? [...(flags.pages.home ? ['stories'] : []), ...stories] : []),
    ...(flags.projects.workspace ? ['dev/projects', ...stories.map(id => `dev/projects/${id}`)] : []),
    ...(flags.projects.campus ? ['campus'] : []),
  ];
  for (const path of ['assets', ...routes]) mkdirSync(join(output, path), { recursive: true });
  const html = `<script type="module" src="${base}assets/app.js"></script>`;
  for (const path of ['index.html', '404.html', ...routes.map(route => `${route}/index.html`)])
    writeFileSync(join(output, path), html);
  writeFileSync(join(output, 'assets/app.js'), 'console.log("fixture");');
  const source = command(root, 'git', ['rev-parse', 'HEAD']);
  writeFileSync(join(output, 'atlas-build.json'), JSON.stringify(buildReceipt({ ...manifest, all }, base, source)));
}

test('packaging enforces source, actual base, published manifest, routes and safe static files', () => {
  const root = mkdtempSync(join(tmpdir(), 'nhhr-prepare-test-'));
  const write = (path, text) => writeFileSync(join(root, 'dist', path), text);
  const reject = (env = {}) => command(root, process.execPath, [prepare], { fail: true, env });
  try {
    initialize(root);
    artifact(root, { base: '/wrong/' });
    reject();
    artifact(root);
    write('index.html', '<script src="/wrong/assets/app.js"></script>');
    reject();
    artifact(root, { all: true });
    reject(); // The build environment is now unset; the receipt still catches it.
    artifact(root);
    reject({ ATLAS_MANIFEST: 'all' });
    reject({ GITHUB_SHA: 'a'.repeat(40) });

    const receipt = JSON.parse(readFileSync(join(root, 'dist/atlas-build.json')));
    for (const change of [{ source: 'b'.repeat(40) }, { manifestSha256: '0'.repeat(64) }]) {
      write('atlas-build.json', JSON.stringify({ ...receipt, ...change }));
      reject();
    }
    for (const unsafe of ['.env', 'gate.php', 'stale.PHP', '__clerk', 'package.json', 'private.pem', 'src', 'node_modules']) {
      artifact(root);
      write(unsafe, 'must not ship');
      reject();
    }
    artifact(root);
    symlinkSync('index.html', join(root, 'dist/unsafe'));
    reject();
    artifact(root);
    mkdirSync(join(root, 'dist/disabled-story'));
    write('disabled-story/index.html', readFileSync(join(root, 'dist/index.html')));
    reject();
    artifact(root);
    rmSync(join(root, 'dist/assets/app.js'));
    reject();
    artifact(root);
    write('404.html', 'different entry page');
    reject();

    artifact(root);
    command(root, process.execPath, [prepare]);
    command(root, process.execPath, [prepare, '--check']);
    assert.deepEqual(JSON.parse(readFileSync(join(root, 'dist/release.json'))), receipt);
    const rules = readFileSync(join(root, 'dist/.htaccess'), 'utf8');
    assert.match(rules, /DirectoryIndex index\.html/);
    assert.match(rules, /RewriteBase \/nhhr\//);
    assert.match(rules, /RewriteRule \^__clerk/);
    assert.match(rules, /Require all denied/);
  } finally { rmSync(root, { recursive: true, force: true }); }
});

test('publisher preserves deployment history, removes stale files, and leaves refs intact after rejection', () => {
  const root = mkdtempSync(join(tmpdir(), 'nhhr-publish-test-'));
  const source = join(root, 'source');
  const remote = join(root, 'remote.git');
  const legacy = join(root, 'legacy');
  mkdirSync(source);
  const git = (...args) => command(source, 'git', args);
  const deployRef = () => git('ls-remote', 'origin', 'refs/heads/hostinger-nhhr').split(/\s/)[0];
  const build = () => { artifact(source); command(source, process.execPath, [prepare]); };
  const push = (options = {}) => command(source, 'bash', [publish], options);
  try {
    command(root, 'git', ['init', '--bare', remote]);
    initialize(source);
    git('remote', 'add', 'origin', remote);
    git('push', 'origin', 'main');
    const main = git('rev-parse', 'HEAD');
    build();
    push(); // First publication creates a separate orphan branch.
    const first = deployRef();
    git('fetch', 'origin', 'hostinger-nhhr');
    const tree = git('ls-tree', '-r', '--name-only', first).split('\n');
    assert(tree.includes('.htaccess') && tree.includes('index.html') && tree.includes('release.json'));
    assert(!tree.includes('source-only.txt'));
    assert.equal(git('rev-parse', 'HEAD'), main);
    push(); // Repeating the exact artifact is a successful no-op.
    assert.equal(deployRef(), first);

    command(root, 'git', ['clone', '--branch', 'hostinger-nhhr', remote, legacy]);
    mkdirSync(join(legacy, '.atlas-auth'));
    writeFileSync(join(legacy, '.atlas-auth/config.json'), '{}');
    writeFileSync(join(legacy, 'gate.php'), '<?php echo "obsolete";');
    command(legacy, 'git', ['add', '--all']);
    command(legacy, 'git', ['-c', 'user.name=Deployment test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'legacy serving files']);
    command(legacy, 'git', ['push', 'origin', 'hostinger-nhhr']);
    const stale = deployRef();

    git('commit', '--allow-empty', '-m', 'source two');
    build();
    push();
    const second = deployRef();
    git('fetch', 'origin', 'hostinger-nhhr');
    git('merge-base', '--is-ancestor', stale, second);
    assert.equal(git('rev-list', '--count', second), '3');
    const updated = git('ls-tree', '-r', '--name-only', second);
    assert(!updated.includes('.atlas-auth') && !updated.includes('gate.php'));
    const unchanged = () => {
      assert.equal(deployRef(), second);
      assert.equal(git('ls-remote', 'origin', 'refs/heads/main').split(/\s/)[0], main);
    };
    const metadata = JSON.parse(readFileSync(join(source, 'dist/release.json')));
    writeFileSync(join(source, 'dist/release.json'), JSON.stringify({ ...metadata, source: 'a'.repeat(40) }));
    push({ fail: true });
    unchanged();
    build();
    command(source, 'bash', [publish, 'public_html'], { fail: true });
    push({ fail: true, env: { ATLAS_MANIFEST: 'all' } });
    unchanged();
    writeFileSync(join(source, 'dist/gate.php'), 'unsafe');
    push({ fail: true });
    unchanged();

    git('commit', '--allow-empty', '-m', 'source three');
    build();
    writeFileSync(join(remote, 'hooks/pre-receive'), '#!/bin/sh\nexit 1\n', { mode: 0o755 });
    push({ fail: true });
    unchanged();
    assert.equal(git('worktree', 'list', '--porcelain').match(/^worktree /gm).length, 1);
    assert.equal(git('branch', '--list', 'codex/nhhr-release.*'), '');
  } finally { rmSync(root, { recursive: true, force: true }); }
});
