import { createServer as originalCreateServer } from 'vite';
import { mkdirSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { preflight, ownedPath } from './isolationPreflight.mjs';

export function createServer(config) {
    if (process.env.SILANSIA_P94E1_VERIFY !== '1') return originalCreateServer(config);
    const root = preflight();
    const cacheDir = ownedPath(root + '/node-cache/' + process.pid + '-' + randomUUID());
    mkdirSync(cacheDir, { recursive: true }); ownedPath(cacheDir);
    return originalCreateServer({ ...config, cacheDir });
}
