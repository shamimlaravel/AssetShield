import { build } from 'vite';
import { assetShieldVite, namelessName } from '../dist/index.js';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, existsSync, rmSync, readdirSync } from 'node:fs';
import { join, normalize } from 'node:path';
import { tmpdir } from 'node:os';

const seed = 'e2e-seed';
const roots = [];

function check(ok, label) {
    console.log((ok ? 'ok  ' : 'FAIL ') + label);
    if (!ok) throw new Error(label);
}

function listFiles(dir) {
    const out = [];

    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const file = join(dir, entry.name);

        if (entry.isDirectory()) {
            out.push(...listFiles(file));
        } else {
            out.push(file);
        }
    }

    return out;
}

async function runBuild({ outDirName, obfuscation }) {
    const root = mkdtempSync(join(tmpdir(), 'assetshield-e2e-'));
    roots.push(root);

    mkdirSync(join(root, 'src'), { recursive: true });
    writeFileSync(join(root, 'src', 'main.js'), '// asset-shield e2e\nconst message = "asset-shield e2e";\nconsole.log(message);\n');

    const outDir = join(root, outDirName);

    await build({
        root,
        logLevel: 'silent',
        plugins: [
            assetShieldVite({
                mask: { enabled: true, strategy: 'nameless', seed },
                obfuscation,
            }),
        ],
        build: {
            outDir,
            emptyOutDir: true,
            sourcemap: false,
            manifest: true,
            rollupOptions: {
                input: { main: join(root, 'src', 'main.js') },
            },
        },
    });

    return {
        root,
        outDir,
        registryFile: join(root, normalize('storage/app/assetshield/registry.json')),
        legendFile: join(root, normalize('storage/app/assetshield/legend.json')),
    };
}

try {
    const buildA = await runBuild({ outDirName: 'build-a', obfuscation: { enabled: false } });

    check(existsSync(buildA.registryFile), 'mask: registry.json written');
    check(existsSync(buildA.legendFile), 'mask: legend.json written');

    const registryA = JSON.parse(readFileSync(buildA.registryFile, 'utf8'));
    const legendA = JSON.parse(readFileSync(buildA.legendFile, 'utf8'));

    const entryKeyA = Object.keys(registryA.assets).find((key) => key.endsWith('main.js'));
    const entryA = entryKeyA === undefined ? undefined : registryA.assets[entryKeyA];
    const maskFile = entryA?.file?.replace(/^build-a\//, '');
    check(Boolean(entryA), 'mask: registry has src/main.js');
    check(new RegExp('^assets/[a-f0-9]{8}.js$').test(maskFile ?? ''), `mask: nameless shape (${maskFile})`);

    const original = Object.keys(legendA.entries).find((key) => legendA.entries[key].masked === maskFile);
    check(Boolean(original), `mask: legend back-maps (${original})`);

    const expected = 'assets/' + namelessName(seed, original ?? '');
    check(Boolean(original) && expected === maskFile, `mask: namelessName parity (${expected})`);

    const buildAFile = join(buildA.outDir, maskFile ?? 'missing.js');
    const bytesA = readFileSync(buildAFile, 'utf8');
    check(bytesA.includes('asset-shield e2e'), 'mask: built bytes exist on disk');
    check(listFiles(buildA.outDir).filter((file) => file.endsWith('.map')).length === 0, 'mask: no source maps');

    const buildB = await runBuild({ outDirName: 'build-b', obfuscation: { enabled: true, preset: 'balanced' } });

    check(existsSync(buildB.registryFile), 'obfuscation: registry.json written');
    check(existsSync(buildB.legendFile), 'obfuscation: legend.json written');

    const filesB = listFiles(buildB.outDir).filter((file) => file.endsWith('.js') && !file.includes('legend.json') && !file.includes('registry.json'));
    const obfJs = filesB.map((file) => file.replace(/\\/g, '/')).find((file) => /\/assets\/[a-f0-9]{8}\.js$/.test(file));
    check(Boolean(obfJs), 'obfuscation: obfuscated assets/*.js on disk');

    const bytesB = readFileSync(obfJs ?? 'missing.js', 'utf8');
    check(
        bytesB.length > 0 && bytesB !== bytesA,
        `obfuscation: bytes differ from mask-only (${bytesA.length}B -> ${bytesB.length}B)`,
    );
    check(listFiles(buildB.outDir).filter((file) => file.endsWith('.map')).length === 0, 'obfuscation: no source maps');

    console.log('\nE2E PASSED');
} catch (error) {
    console.error(`\nE2E FAILED: ${error instanceof Error ? error.message : String(error)}`);
    process.exitCode = 1;
} finally {
    for (const root of roots) {
        rmSync(root, { recursive: true, force: true });
    }
}