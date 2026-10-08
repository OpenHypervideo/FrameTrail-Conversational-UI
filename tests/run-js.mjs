/*
 * Tests of the client part. No dependencies, Node 20 or later:
 *
 *     node tests/run-js.mjs            # the files in client/
 *     node tests/run-js.mjs --build    # the bundle bash scripts/build.sh wrote
 *
 * The client files run in a vm context, in the order of scripts/build.sh, as
 * they run in the browser: against a stand-in for FrameTrail that records what
 * the extension registers.
 */

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const ROOT    = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const CLIENT  = path.join(ROOT, 'client');
const BUNDLE  = path.join(ROOT, 'build', 'client', 'frametrail-conversational-ui.js');
const BUILT   = process.argv.includes('--build');

const NAME          = 'conversational-ui';
const LOCALES       = ['en', 'de', 'fr'];
const LABEL_PATTERN = /^ConversationalUi[A-Z][A-Za-z0-9]*$/;
const VERSION_TOKEN = '__CONVERSATIONAL_UI_VERSION__';


/* ---------------------------------------------------------------------- */
/*  Loading                                                               */
/* ---------------------------------------------------------------------- */

// A file list of scripts/build.sh, relative to client/.
function buildList(name) {
    const source = fs.readFileSync(path.join(ROOT, 'scripts', 'build.sh'), 'utf8');
    const match  = source.match(new RegExp('^' + name + '=\\(\\n([\\s\\S]*?)^\\)', 'm'));
    assert.ok(match, 'scripts/build.sh has no ' + name + ' list');
    return [...match[1].matchAll(/^\s*"([^"]+)"/gm)].map((m) => m[1]);
}

const JS_FILES  = buildList('JS_FILES');
const CSS_FILES = buildList('CSS_FILES');

function clientFiles(extension, rel = '') {
    return fs.readdirSync(path.join(CLIENT, rel)).sort().flatMap((name) => {
        const file = rel ? rel + '/' + name : name;
        if (fs.statSync(path.join(CLIENT, file)).isDirectory()) { return clientFiles(extension, file); }
        return name.endsWith(extension) ? [file] : [];
    });
}

// A DOM element, as far as the client uses one.
function element(tagName) {
    return {
        tagName:  tagName.toUpperCase(),
        className: '',
        children: [],
        append(...nodes) { this.children.push(...nodes); }
    };
}

// Runs the client in a fresh context. Without the extension API, FrameTrail
// is there but too old.
function load({ extensionAPI = true } = {}) {

    const registered = {},
          warnings   = [];

    const context = {
        console:    { log() {}, error() {}, warn: (...args) => warnings.push(args.join(' ')) },
        document:   { createElement: element },
        FrameTrail: extensionAPI ? { registerExtension(name, factory) { registered[name] = factory; } } : {}
    };
    context.window = context;
    vm.createContext(context);

    const scripts = BUILT
        ? [['build/client/frametrail-conversational-ui.js', fs.readFileSync(BUNDLE, 'utf8')]]
        : JS_FILES.map((file) => ['client/' + file, fs.readFileSync(path.join(CLIENT, file), 'utf8')]);

    for (const [filename, source] of scripts) {
        vm.runInContext(source, context, { filename });
    }

    return { context, registered, warnings };

}

// The internal instance an extension's factory gets, as far as the client
// uses it: Localization with addLabels() and labels in one language, falling
// back to English per key like FrameTrail's.
function instance(language = 'en') {

    const tables = {};

    const Localization = {
        added: [],
        addLabels(more) {
            this.added.push(more);
            for (const lang in more) { tables[lang] = Object.assign(tables[lang] || {}, more[lang]); }
        },
        labels: new Proxy({}, {
            get: (target, key) => ((tables[language] || {})[key] !== undefined ? tables[language][key] : (tables.en || {})[key])
        })
    };

    return { module: (name) => (name === 'Localization' ? Localization : undefined), Localization };

}


/* ---------------------------------------------------------------------- */
/*  Build lists                                                           */
/* ---------------------------------------------------------------------- */

describe('scripts/build.sh', () => {

    test('lists every client script once, namespace.js first and module.js last', () => {
        assert.deepEqual([...JS_FILES].sort(), clientFiles('.js'));
        assert.equal(new Set(JS_FILES).size, JS_FILES.length);
        assert.equal(JS_FILES[0], 'namespace.js');
        assert.equal(JS_FILES[JS_FILES.length - 1], 'module.js');
    });

    test('lists every client stylesheet once', () => {
        assert.deepEqual([...CSS_FILES].sort(), clientFiles('.css'));
        assert.equal(new Set(CSS_FILES).size, CSS_FILES.length);
    });

    test('loads every locale', () => {
        for (const lang of LOCALES) { assert.ok(JS_FILES.includes('locale/' + lang + '.js'), 'locale/' + lang + '.js'); }
    });

});

if (BUILT) {
    describe('the bundle', () => {
        test('carries the build\'s version', () => {
            const { context } = load();
            assert.equal(typeof context.FrameTrailConversationalUI.version, 'string');
            assert.ok(!context.FrameTrailConversationalUI.version.includes(VERSION_TOKEN.slice(0, 2)), context.FrameTrailConversationalUI.version);
        });
    });
}


/* ---------------------------------------------------------------------- */
/*  Labels                                                                */
/* ---------------------------------------------------------------------- */

describe('labels', () => {

    const { context } = load(),
          tables      = context.FrameTrailConversationalUI.labels,
          english     = Object.keys(tables.en || {});

    test('exist for every locale, and only for those', () => {
        assert.deepEqual(Object.keys(tables).sort(), [...LOCALES].sort());
    });

    for (const lang of LOCALES) {

        test(lang + ': keys are prefixed and in alphabetical order', () => {
            const keys = Object.keys(tables[lang]);
            for (const key of keys) { assert.match(key, LABEL_PATTERN); }
            assert.deepEqual(keys, [...keys].sort());
        });

        test(lang + ': same keys as English, every value a text', () => {
            assert.deepEqual(Object.keys(tables[lang]).sort(), [...english].sort());
            for (const key in tables[lang]) {
                assert.equal(typeof tables[lang][key], 'string', key);
                assert.ok(tables[lang][key].trim() !== '', key + ' is empty');
            }
        });

    }

    test('every label the client uses exists', () => {
        for (const file of clientFiles('.js')) {
            const source = fs.readFileSync(path.join(CLIENT, file), 'utf8');
            for (const match of source.matchAll(/labels\[\s*['"]([A-Za-z0-9]+)['"]\s*\]|labels\.([A-Za-z][A-Za-z0-9]*)/g)) {
                const key = match[1] || match[2];
                if (key === 'en' || key === 'de' || key === 'fr') { continue; }
                assert.ok(english.includes(key), file + ' uses the unknown label ' + key);
            }
        }
    });

});


/* ---------------------------------------------------------------------- */
/*  Registration                                                          */
/* ---------------------------------------------------------------------- */

describe('the extension', () => {

    test('registers as ' + NAME, () => {
        const { registered, warnings } = load();
        assert.deepEqual(Object.keys(registered), [NAME]);
        assert.equal(typeof registered[NAME], 'function');
        assert.deepEqual(warnings, []);
    });

    test('adds its labels to FrameTrail\'s', () => {
        const { context, registered } = load(),
              ft = instance();
        registered[NAME](ft);
        assert.equal(ft.Localization.added.length, 1);
        assert.deepEqual(Object.keys(ft.Localization.added[0]).sort(), [...LOCALES].sort());
        assert.equal(ft.Localization.added[0], context.FrameTrailConversationalUI.labels);
    });

    test('takes a side panel while editing, and nothing else', () => {
        const { context, registered } = load(),
              extension = registered[NAME](instance());
        assert.deepEqual(Object.keys(extension.slots), ['sidePanel']);
        const panel = extension.slots.sidePanel;
        assert.equal(panel.when, 'edit');
        assert.equal(panel.icon, 'icon-chat');
        assert.equal(panel.label, context.FrameTrailConversationalUI.labels.en.ConversationalUiTitle);
        assert.equal(typeof panel.create, 'function');
    });

    test('labels the panel in the interface language', () => {
        const { context, registered } = load(),
              extension = registered[NAME](instance('de'));
        assert.equal(extension.slots.sidePanel.label, context.FrameTrailConversationalUI.labels.de.ConversationalUiTitle);
    });

    test('builds an empty panel', () => {
        const { registered } = load(),
              extension = registered[NAME](instance()),
              container = element('div');
        extension.slots.sidePanel.create(container, { open() {}, close() {}, toggle() {}, isOpen: false });
        assert.equal(container.children.length, 1);
        assert.equal(container.children[0].tagName, 'DIV');
        assert.equal(container.children[0].className, 'conversationalUi');
        assert.equal(container.children[0].children.length, 0);
    });

    test('says so and registers nothing with a FrameTrail before extensions', () => {
        const { context, registered, warnings } = load({ extensionAPI: false });
        assert.deepEqual(Object.keys(registered), []);
        assert.equal(warnings.length, 1);
        assert.ok(warnings[0].includes(context.FrameTrailConversationalUI.version), warnings[0]);
    });

});
