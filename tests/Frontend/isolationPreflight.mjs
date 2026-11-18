import { readFileSync, realpathSync, lstatSync, existsSync, accessSync, constants } from 'node:fs';
import { dirname, basename } from 'node:path';
import { tmpdir } from 'node:os';
import { timingSafeEqual } from 'node:crypto';

export function ownedPath(path) {
    const root = process.env.SILANSIA_RUNTIME_ROOT;
    const normalize = v => v.replaceAll('\\', '/').toLowerCase();
    if (!path || !root || !normalize(path).startsWith(normalize(root) + '/')) throw new Error('runtime_node_path_escape');
    let cursor = path;
    while (true) {
        if (existsSync(cursor)) {
            if (lstatSync(cursor).isSymbolicLink()) throw new Error('runtime_node_path_link');
            if (normalize(realpathSync(cursor)) !== normalize(cursor)) throw new Error('runtime_node_path_alias');
        }
        const parent = dirname(cursor); if (cursor === parent) break; cursor = parent;
    }
    return path;
}
export function preflight() {
    const root = process.env.SILANSIA_RUNTIME_ROOT;
    if (process.env.SILANSIA_P94E1_VERIFY !== '1' || !root || !/^silansia-p94e1-[a-f0-9]{16}$/.test(basename(root))) throw new Error('runtime_node_context_missing');
    const normalize = v => v.replaceAll('\\','/').toLowerCase();
    if (lstatSync(root).isSymbolicLink() || normalize(realpathSync(root)) !== normalize(root)) throw new Error('runtime_node_root_alias');
    const marker = JSON.parse(readFileSync(ownedPath(root + '/owner.json'),'utf8'));
    if (marker.root !== root || !/^[a-f0-9]{64}$/.test(process.env.SILANSIA_RUNTIME_OWNER ?? '')
        || !/^[a-f0-9]{64}$/.test(marker.token ?? '') || !timingSafeEqual(Buffer.from(marker.token),Buffer.from(process.env.SILANSIA_RUNTIME_OWNER))) throw new Error('runtime_node_owner_invalid');
    for (const path of [tmpdir(), process.env.TMP, process.env.TEMP, process.env.TMPDIR, process.env.npm_config_cache]) accessSync(ownedPath(path),constants.W_OK);
    return root;
}
// This file is also the mandatory Node --import bootstrap in the isolated launcher.
if (process.env.SILANSIA_P94E1_VERIFY !== undefined || process.execArgv.some(v => v.includes('isolationPreflight.mjs'))) preflight();
