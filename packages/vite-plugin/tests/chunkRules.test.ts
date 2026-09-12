import { describe, expect, it } from 'vitest';

import { makeChunkPredicate } from '../src/chunkRules';

describe('chunk selection', () => {
    it('skips vendor chunks under the default application rule', () => {
        const pred = makeChunkPredicate({ mode: 'application' });

        expect(
            pred({
                fileName: 'assets/vendor.js',
                isEntry: false,
                moduleIds: ['/project/node_modules/lodash/index.js'],
            }),
        ).toBe(false);

        expect(
            pred({
                fileName: 'assets/app.js',
                isEntry: true,
                moduleIds: ['/project/resources/js/app.js', '/project/node_modules/axios/index.js'],
            }),
        ).toBe(false);

        expect(
            pred({
                fileName: 'assets/app.js',
                isEntry: true,
                moduleIds: ['/project/resources/js/app.js'],
            }),
        ).toBe(true);
    });

    it('honours include and exclude globs, with exclude winning', () => {
        const pred = makeChunkPredicate({
            mode: 'all',
            include: ['dashboard/**'],
            exclude: ['**/*.min.js'],
        });

        expect(
            pred({
                fileName: 'dashboard/panel.js',
                isEntry: false,
                moduleIds: [],
            }),
        ).toBe(true);

        expect(
            pred({
                fileName: 'assets/app.js',
                isEntry: true,
                moduleIds: [],
            }),
        ).toBe(false);

        expect(
            pred({
                fileName: 'dashboard/vendor.min.js',
                isEntry: false,
                moduleIds: ['/project/node_modules/x/index.js'],
            }),
        ).toBe(false);
    });

    it('never selects css chunks', () => {
        const pred = makeChunkPredicate({ mode: 'all' });

        expect(
            pred({
                fileName: 'assets/app.css',
                isEntry: true,
                moduleIds: [],
                isCss: true,
            }),
        ).toBe(false);

        expect(
            pred({
                fileName: 'assets/app.css',
                isEntry: true,
                moduleIds: [],
            }),
        ).toBe(false);
    });

    it('only selects entry chunks in entries mode', () => {
        const pred = makeChunkPredicate({ mode: 'entries' });

        expect(
            pred({
                fileName: 'assets/app.js',
                isEntry: true,
                moduleIds: ['/project/node_modules/x/index.js'],
            }),
        ).toBe(true);

        expect(
            pred({
                fileName: 'assets/dashboard.js',
                isEntry: false,
                moduleIds: ['/project/resources/js/dashboard.js'],
            }),
        ).toBe(false);
    });
});