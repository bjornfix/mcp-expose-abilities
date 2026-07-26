#!/usr/bin/env node

import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const pluginPath = process.env.MCP_EXPOSE_INVENTORY_PLUGIN || new URL("../mcp-expose-abilities.php", import.meta.url);
const readmePath = process.env.MCP_EXPOSE_INVENTORY_README || new URL("../README.md", import.meta.url);
const plugin = readFileSync(pluginPath, "utf8");
const readme = readFileSync(readmePath, "utf8");

const registered = [...plugin.matchAll(/wp_register_ability\(\s*['"]([^'"]+)['"]/g)].map((match) => match[1]);
assert.equal(new Set(registered).size, registered.length, "Registered ability names must be unique.");

const coreStart = readme.indexOf("## Core Plugin Abilities");
const coreEnd = readme.indexOf("## Add-on Plugin Abilities");
assert.ok(coreStart >= 0 && coreEnd > coreStart, "README core ability inventory section is missing.");
const coreSection = readme.slice(coreStart, coreEnd);
const documented = [...coreSection.matchAll(/^\| `([^`]+)` \|/gm)].map((match) => match[1]);

assert.deepEqual(
  [...documented].sort(),
  [...registered].sort(),
  "README core ability names must exactly match registered abilities.",
);
assert.equal(new Set(documented).size, documented.length, "README ability names must be unique.");

const declaredCoreCount = Number(coreSection.match(/^## Core Plugin Abilities \((\d+)\)$/m)?.[1]);
assert.equal(declaredCoreCount, registered.length, "README core ability heading count is stale.");

const categoryMatches = [...coreSection.matchAll(/^### .+ \((\d+)\)$/gm)];
for (const [index, category] of categoryMatches.entries()) {
	const declared = Number(category[1]);
	const bodyStart = category.index + category[0].length;
	const bodyEnd = categoryMatches[index + 1]?.index ?? coreSection.length;
	const actual = [...coreSection.slice(bodyStart, bodyEnd).matchAll(/^\| `[^`]+` \|/gm)].length;
	assert.equal(actual, declared, `README category count is stale: ${category[0]}`);
}
assert.equal(
  categoryMatches.reduce((sum, category) => sum + Number(category[1]), 0),
  registered.length,
  "README category counts do not cover every registered ability.",
);

const architectureStart = readme.indexOf("## Modular Architecture");
const requirementsStart = readme.indexOf("## Requirements", architectureStart);
assert.ok(architectureStart >= 0 && requirementsStart > architectureStart, "README architecture inventory is missing.");
const architecture = readme.slice(architectureStart, requirementsStart);
const declaredArchitectureCore = Number(architecture.match(/^\| \*\*MCP Expose Abilities\*\* \(core\) \| (\d+) \|/m)?.[1]);
assert.equal(declaredArchitectureCore, registered.length, "README architecture core count is stale.");
const ecosystemCount = [...architecture.matchAll(/^\| (?![-|])[^\n]+ \| (\d+) \|[^\n]+$/gm)]
  .reduce((sum, match) => sum + Number(match[1]), 0);
const declaredEcosystemCount = Number(architecture.match(/^\*\*Total ecosystem: (\d+) abilities\*\*$/m)?.[1]);
assert.equal(declaredEcosystemCount, ecosystemCount, "README ecosystem total is stale.");

process.stdout.write(`${JSON.stringify({ success: true, coreAbilities: registered.length, ecosystemAbilities: ecosystemCount })}\n`);
