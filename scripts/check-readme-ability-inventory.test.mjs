#!/usr/bin/env node

import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { spawnSync } from "node:child_process";

const verifier = new URL("./check-readme-ability-inventory.mjs", import.meta.url);
const pluginSource = readFileSync(new URL("../mcp-expose-abilities.php", import.meta.url), "utf8");
const readmeSource = readFileSync(new URL("../README.md", import.meta.url), "utf8");
const root = mkdtempSync(join(tmpdir(), "mcp-expose-inventory-"));

function verify(readme) {
  const pluginPath = join(root, "plugin.php");
  const readmePath = join(root, "README.md");
  writeFileSync(pluginPath, pluginSource);
  writeFileSync(readmePath, readme);
  return spawnSync(process.execPath, [verifier.pathname], {
    encoding: "utf8",
    env: {
      ...process.env,
      MCP_EXPOSE_INVENTORY_PLUGIN: pluginPath,
      MCP_EXPOSE_INVENTORY_README: readmePath,
    },
  });
}

try {
  assert.equal(verify(readmeSource).status, 0, "Current source and README inventory must agree.");
  assert.notEqual(
    verify(readmeSource.replace("## Core Plugin Abilities (79)", "## Core Plugin Abilities (78)")).status,
    0,
    "A stale core heading count must fail.",
  );
  assert.notEqual(
    verify(readmeSource.replace(/^\| `meta\/get-post-meta`.*\n/m, "")).status,
    0,
    "A missing registered ability must fail.",
  );
  assert.notEqual(
    verify(readmeSource.replace("**Total ecosystem: 312 abilities**", "**Total ecosystem: 311 abilities**")).status,
    0,
    "A stale ecosystem total must fail.",
  );
} finally {
  rmSync(root, { recursive: true, force: true });
}

process.stdout.write(`${JSON.stringify({ success: true, cases: 4 })}\n`);
