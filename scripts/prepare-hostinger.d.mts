import type { ResolvedManifest } from './manifest.mjs';
export function buildReceipt(manifest: ResolvedManifest, base: string, source?: string): {
  source: string;
  base: string;
  all: boolean;
  manifestSha256: string;
};
