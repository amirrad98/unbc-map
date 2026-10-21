import { defineConfig, type Plugin } from "vite";
import react from "@vitejs/plugin-react";
import { flagDefines, manifestFiles, resolveManifest, stripContent, stripHome, stripSnapshot } from "./scripts/manifest.mjs";
import { buildReceipt } from "./scripts/prepare-hostinger.mjs";

/**
 * manifest/ decides what a build contains. Its switches become literal FLAGS
 * (so switched-off code minifies away), and disabled stories, story maps and
 * their datasets are cut out of the sources before they are bundled.
 */
function atlasManifest(): Plugin {
  const manifest = resolveManifest();
  let base = "/";
  return {
    name: "atlas-manifest",
    enforce: "pre",
    config: () => ({ define: flagDefines(manifest.flags) }),
    configResolved(config) { base = config.base; },
    generateBundle() {
      // Deployment checks the build's actual settings, including build:all.
      this.emitFile({ type: "asset", fileName: "atlas-build.json", source: JSON.stringify(buildReceipt(manifest, base)) });
    },
    configureServer(server) {
      server.watcher.add(manifestFiles);
      server.watcher.on("change", file => { if (manifestFiles.includes(file)) server.restart(); });
    },
    transform(code, id) {
      const path = id.split("?")[0];
      if (/\/src\/atlas\/snapshots\/[^/]+\.json$/.test(path)) return { code: stripSnapshot(code, manifest), map: null };
      if (path.endsWith("/manifest/home.json")) return { code: stripHome(code, manifest), map: null };
      if (/\/src\/atlas\/[^/]+\.ts$/.test(path)) { const stripped = stripContent(code, path, manifest); return stripped === undefined ? undefined : { code: stripped, map: null }; }
    },
  };
}

export default defineConfig({ plugins: [atlasManifest(), react()], base: process.env.ATLAS_BASE_PATH || "/" });
