# SparkLab Hostinger deployment

Source is `amirrad98/unbc-map`, branch `main`. The existing Hostinger Git
integration deploys **`hostinger-nhhr`** to **`public_html/nhhr`**, serving
<https://sparklab.unbc.ca/nhhr/>. The WordPress site at the domain root is separate.
Do not point Hostinger at the source branch or at `public_html` itself.

## Publish

Push to `main`, or run **Deploy SparkLab NHHR** on `main`. The workflow installs
the pinned submodules and dependencies, checks the publisher, and runs
`ATLAS_BASE_PATH=/nhhr/ npm run build`. It tests the published manifest and actual
password entry before preparing and publishing the release. Only the publishing
job has repository write permission. No FTP password or SSH key is needed.

Set the repository Actions secret **`NHHR_TEST_PASSWORD`** to the password whose
SHA-256 is in `manifest/access.json`. A missing or mismatched secret fails the
deployment. To change the password, run `npm run manifest:password -- <password>`
and update that secret before pushing. The password is not committed.

All three projects now require the password: the Atlas home and story pages,
the workspace at `/dev/projects`, and campus. All other `manifest/` switches
retain their configured values. One unlock remembers access for four hours
across the projects in that browser; use a fresh private window to check the
initial prompt. This is the existing browser password gate described in
[the manifest guide](../manifest/README.md#accessjson-the-password), not server
authentication. The deployment replaces the earlier Clerk/PHP release; stale PHP,
proxy and hidden paths are denied by the subfolder's new `.htaccess`.

Never publish `npm run build:all`: it enables all options and removes passwords.
Vite emits `atlas-build.json` with the source, base, build mode and manifest digest.
Preparation and publication reject mismatched or all-feature builds. The artifact
includes `.htaccess`; it must not be omitted when downloading or uploading it.

The publisher replaces only its temporary checkout of `hostinger-nhhr`, removes
obsolete tracked release files, and makes a normal commit and push. It retains
release history and never force-pushes. A failed build, test or rejected push keeps
the published branch unchanged. A run for an outdated source is rejected before
publication. Hostinger controls checkout timing; workflow success alone does not
prove the new release is live.

## Verify

```sh
node --test scripts/test-hostinger.mjs
ATLAS_BASE_PATH=/nhhr/ npm run build
ATLAS_BASE_PATH=/nhhr/ ATLAS_TEST_PASSWORD='<password>' npm run test:production -- --workers=3
node scripts/prepare-hostinger.mjs
node scripts/prepare-hostinger.mjs --check dist
curl -fsSL https://sparklab.unbc.ca/nhhr/release.json
```

The live `source` must equal the successful workflow's source commit and `base`
must be `/nhhr/`. Check the home page, a directly loaded enabled story, an enabled
nested workspace route, campus, and the entry page's asset URLs. In fresh browser
sessions, check that every project asks for the password, rejects an incorrect
password, and accepts the intended password. Include the legacy `/#place=…`
campus link and confirm the configured options after unlocking.
Hidden paths such as `/nhhr/.git/config` and `/nhhr/.atlas-auth/config.json`, and
obsolete `gate.php`/`__clerk` paths, must be denied or absent. The root WordPress
homepage should keep its title and return HTTP 200.

## Recovery

If Hostinger has not picked up a successful release, inspect its Git deployment
for the configured branch and target, then use **Redeploy**. For a rollback, pause
automatic deployment, restore the complete tree of a known-good static release
as a new commit on `hostinger-nhhr`, and push normally. Verify the live source,
routes and password before resuming automatic deployment. Fix or revert `main`
as well so the next build does not republish the problem.
