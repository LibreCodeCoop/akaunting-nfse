// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

const project = path.resolve(import.meta.dirname, '..');
const script = path.join(project, 'tools/bin/run-feature-shard.sh');

function shard(root, index, count) {
  const output = execFileSync('bash', [script], {
    cwd: project,
    encoding: 'utf8',
    env: {
      ...process.env,
      NFSE_FEATURE_ROOT: root,
      NFSE_FEATURE_SHARD_INDEX: String(index),
      NFSE_FEATURE_SHARD_COUNT: String(count),
      NFSE_FEATURE_DRY_RUN: '1',
    },
  });
  const [summary, classes] = output.trim().split('\n');
  return { summary, classes: classes.split('|') };
}

test('Feature shard weights include observed data-provider cases, not just methods', t => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'nfse-feature-shard-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  for (const name of ['AdminRouteRegistrationTest', 'OtherTest']) {
    fs.writeFileSync(path.join(root, `${name}.php`), '<?php function testExample() {}\n');
  }
  const first = shard(root, 0, 2);
  const second = shard(root, 1, 2);
  assert.match(first.summary, /1 files, 29 estimated test cases/);
  assert.deepEqual(first.classes, ['AdminRouteRegistrationTest']);
  assert.match(second.summary, /1 files, 1 estimated test cases/);
  assert.deepEqual(second.classes, ['OtherTest']);
});

test('Feature shards cover each class once with deterministic independent groups', () => {
  const root = path.join(project, 'Tests/Feature');
  const expected = fs.readdirSync(root).filter(name => name.endsWith('Test.php'))
    .map(name => name.slice(0, -4)).sort();
  const results = Array.from({ length: 4 }, (_, index) => shard(root, index, 4));
  const selected = results.flatMap(result => result.classes);
  assert.deepEqual([...selected].sort(), expected);
  for (let index = 0; index < 4; index++) {
    assert.deepEqual(shard(root, index, 4), results[index]);
  }
});
