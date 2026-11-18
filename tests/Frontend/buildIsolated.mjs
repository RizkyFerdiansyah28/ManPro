import { build, loadConfigFromFile } from 'vite';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { preflight, ownedPath } from './isolationPreflight.mjs';

const root = preflight();
const loaded = await loadConfigFromFile({ command: 'build', mode: 'production' }, resolve('vite.config.js'), process.cwd(), undefined, undefined, 'runner');
if (!loaded) throw new Error('runtime_build_config_missing');
async function flatten(list) { const result=[];for(const value of list){const plugin=await value;if(Array.isArray(plugin))result.push(...await flatten(plugin));else if(plugin)result.push(plugin);}return result; }
const plugins=await flatten(loaded.config.plugins ?? []);
const fontProject=ownedPath(root + '/font-project');mkdirSync(fontProject,{recursive:true});ownedPath(fontProject);
let redirected=0;
for(const plugin of plugins) if(plugin.name==='laravel:fonts') {
    if(typeof plugin.configResolved!=='function')throw new Error('runtime_font_hook_unsupported');
    const original=plugin.configResolved;
    // Installed plugin hardcodes its cache beneath config.root; redirect this hook's
    // private config only. Keep the application's Vite/Tailwind root and inputs intact.
    plugin.configResolved=function(config){return original.call(this,{...config,root:fontProject});};redirected++;
}
if(redirected!==1)throw new Error('runtime_font_plugin_missing');
await build({ ...loaded.config, plugins, configFile: false, envDir: false,
    cacheDir: ownedPath(root + '/node-cache/build'), build: { ...loaded.config.build, outDir: ownedPath(root + '/vite-build'), emptyOutDir: false } });
ownedPath(fontProject+'/node_modules/.cache/laravel-vite-plugin/fonts');
writeFileSync(ownedPath(root+'/build-isolation.json'),JSON.stringify({config:'vite.config.js',application_root:process.cwd(),font_cache_root:fontProject,output:root+'/vite-build',source_config_modified:false}));
