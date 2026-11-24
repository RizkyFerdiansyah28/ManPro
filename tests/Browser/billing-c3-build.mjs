import { lstatSync, readFileSync, readdirSync, realpathSync } from 'node:fs';
import { isAbsolute, join, relative, resolve, sep } from 'node:path';

const appCss = 'resources/css/app.css';
const invoicesCss = 'resources/css/invoices.css';
const paginationViews = 'vendor/laravel/framework/src/Illuminate/Pagination/resources/views';
const imports = [
    "@import 'tailwindcss';",
    "@import './invoices.css';",
    "@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';",
    "@source '../../storage/framework/views/*.php';",
];
const sourceDirectives = /@(?:import|source|config|plugin|reference)\b/g;

export function c3TailwindInput(css) {
    const matches = [...css.matchAll(sourceDirectives)];
    if (matches.length !== imports.length || matches.some(({ index }, i) => !css.startsWith(imports[i], index))) {
        throw new Error('billing_c3_build_css_inputs_unreviewed');
    }
    return css.replace(imports[0], "@import 'tailwindcss' source(none);")
        .replace(imports[3], "@source '../js';\n@source '../views';");
}

export function c3BuildInputs(root, pins) {
    const checkPath = path => {
        const absolute = resolve(path);
        const key = relative(root, absolute).split(sep).join('/');
        if (!key || key.startsWith('../') || isAbsolute(key)
            || !(Object.hasOwn(pins, key) || key.startsWith('node_modules/') || key.startsWith('vendor/'))) {
            throw new Error('billing_c3_build_input_unpinned');
        }
        const actual = realpathSync(absolute);
        const info = lstatSync(absolute);
        if (relative(absolute, actual) !== '' || !info.isFile() || info.isSymbolicLink() || info.nlink !== 1) {
            throw new Error('billing_c3_build_input_unverified');
        }
        return key;
    };
    const checkSourceDirectory = directory => {
        const info = lstatSync(directory);
        if (relative(directory, realpathSync(directory)) !== '' || !info.isDirectory() || info.isSymbolicLink()) {
            throw new Error('billing_c3_build_input_unverified');
        }
        for (const entry of readdirSync(directory, { withFileTypes: true })) {
            const path = join(directory, entry.name);
            if (entry.isDirectory()) checkSourceDirectory(path);
            else checkPath(path);
        }
    };
    return {
        name: 'silansia-c3-build-inputs',
        enforce: 'pre',
        buildStart() {
            for (const directory of ['resources/js', 'resources/views', paginationViews]) {
                checkSourceDirectory(join(root, directory));
            }
            // Tailwind resolves CSS imports internally, outside Vite's module load hook.
            const invoiceStyles = join(root, invoicesCss);
            checkPath(invoiceStyles);
            if ([...readFileSync(invoiceStyles, 'utf8').matchAll(sourceDirectives)].length > 0) {
                throw new Error('billing_c3_build_css_inputs_unreviewed');
            }
        },
        load(id) {
            const path = id.split('?', 1)[0];
            if (!isAbsolute(path)) return;
            const key = checkPath(path);
            if (key === appCss) return c3TailwindInput(readFileSync(path, 'utf8'));
        },
    };
}
