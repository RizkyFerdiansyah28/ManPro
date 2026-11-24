import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, rmdirSync, symlinkSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { assertDependencyTree, treeDigest } from '../Browser/billing-c3-bootstrap.mjs';

test('Node rejects changed package bytes with the same version, and missing files', () => {
    const root = mkdtempSync(join(tmpdir(), 'silansia-h03-node-'));
    const packageFile = join(root, 'package.json');
    const code = join(root, 'index.mjs');
    try {
        writeFileSync(packageFile, '{"version":"1.0.0"}');
        writeFileSync(code, 'export const fixture = 1;');
        const expected = treeDigest(root);
        assert.doesNotThrow(() => assertDependencyTree(root, expected));
        assert.throws(() => assertDependencyTree(root, expected, -1), { message: 'billing_c3_dependency_deadline_exceeded' });
        writeFileSync(code, 'export const fixture = 2;');
        assert.throws(() => assertDependencyTree(root, expected), { message: 'billing_c3_dependency_tree_mismatch' });
        unlinkSync(code);
        assert.throws(() => assertDependencyTree(root, expected), { message: 'billing_c3_dependency_tree_mismatch' });
    } finally {
        unlinkSync(packageFile);
        rmdirSync(root);
    }
});

test('Node rejects junctions before walking into the linked target', () => {
    const root = mkdtempSync(join(tmpdir(), 'silansia-h03-link-'));
    const target = join(root, 'target');
    const link = join(root, 'package-link');
    mkdirSync(target);
    try {
        symlinkSync(target, link, process.platform === 'win32' ? 'junction' : 'dir');
        try {
            assert.throws(() => treeDigest(root), /billing_c3_dependency_path_(alias|reparse)/);
        } finally {
            unlinkSync(link);
        }
    } finally {
        rmdirSync(target);
        rmdirSync(root);
    }
});
