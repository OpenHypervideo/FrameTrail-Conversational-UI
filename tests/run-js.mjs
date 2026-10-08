/*
 * Tests of the client part. No dependencies, Node 20 or later:
 *
 *     node tests/run-js.mjs            # the files in client/ and shared/
 *     node tests/run-js.mjs --build    # the bundle bash scripts/build.sh wrote
 *
 * The client files run in a vm context, in the order of scripts/build.sh, as
 * they run in the browser: against a stand-in for FrameTrail that records what
 * the extension registers, and, for the operations, with FrameTrail's own
 * serializer, keyframe math, validator and schemas loaded first, as FrameTrail
 * loads them. Those come from a FrameTrail working copy (1.4.1 or later): the
 * environment variable FRAMETRAIL_DIR, or --frametrail=<dir>, or the folder
 * next to this repository's, ../frametrail.
 *
 * The conformance fixtures in shared/fixtures/ run against the model store;
 * shared/fixtures/README.md has the rules.
 */

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const ROOT    = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const CLIENT  = path.join(ROOT, 'client');
const SHARED  = path.join(ROOT, 'shared');
const BUNDLE  = path.join(ROOT, 'build', 'client', 'frametrail-conversational-ui.js');
const BUILT   = process.argv.includes('--build');

const NAME          = 'conversational-ui';
const LOCALES       = ['en', 'de', 'fr'];
const LABEL_PATTERN = /^ConversationalUi[A-Z][A-Za-z0-9]*$/;
const VERSION_TOKEN = '__CONVERSATIONAL_UI_VERSION__';

const FRAMETRAIL = path.resolve(ROOT, (process.argv.find((arg) => arg.startsWith('--frametrail=')) || '').slice('--frametrail='.length)
    || process.env.FRAMETRAIL_DIR || path.join('..', 'frametrail'));

// FrameTrail's pure scripts, in the order its index.html loads them.
const FRAMETRAIL_SCRIPTS = [
    'src/_shared/frametrail-core/serialization/FrameTrailKeyframes.js',
    'src/_shared/frametrail-core/serialization/FrameTrailSerializer.js',
    'src/_shared/frametrail-core/schema/FrameTrailSchema.js',
    'src/_shared/frametrail-core/schema/FrameTrailSchemas.js'
];

const readJSON = (file) => JSON.parse(fs.readFileSync(file, 'utf8'));
const clone    = (value) => JSON.parse(JSON.stringify(value));
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

// A value made in the vm context, as a value of this one (deepStrictEqual compares prototypes).
const json = (value) => (value === undefined) ? undefined : JSON.parse(JSON.stringify(value));


/* ---------------------------------------------------------------------- */
/*  The conformance fixtures this runner reads                            */
/* ---------------------------------------------------------------------- */

const FIXTURES = path.join(SHARED, 'fixtures');
const DATA     = path.join(FIXTURES, 'data');

// The folders of shared/fixtures/; anything else there is an error.
const FIXTURE_FOLDERS = ['data', 'ops', 'lint'];

function fixtureFiles(folder) {
    const dir = path.join(FIXTURES, folder);
    return fs.existsSync(dir) ? fs.readdirSync(dir).filter((name) => name.endsWith('.json')).sort() : [];
}


/* ---------------------------------------------------------------------- */
/*  Loading                                                               */
/* ---------------------------------------------------------------------- */

// A list of scripts/build.sh, its entries as written.
function buildList(name) {
    const source = fs.readFileSync(path.join(ROOT, 'scripts', 'build.sh'), 'utf8');
    const match  = source.match(new RegExp('^' + name + '=\\(\\n([\\s\\S]*?)^\\)', 'm'));
    assert.ok(match, 'scripts/build.sh has no ' + name + ' list');
    return [...match[1].matchAll(/^\s*"([^"]+)"/gm)].map((m) => m[1]);
}

const JS_FILES    = buildList('JS_FILES');
const CSS_FILES   = buildList('CSS_FILES');
const SHARED_DATA = buildList('SHARED_DATA').map((entry) => ({ property: entry.split(':')[0], file: entry.slice(entry.indexOf(':') + 1) }));

function clientFiles(extension, rel = '') {
    return fs.readdirSync(path.join(CLIENT, rel)).sort().flatMap((name) => {
        const file = rel ? rel + '/' + name : name;
        if (fs.statSync(path.join(CLIENT, file)).isDirectory()) { return clientFiles(extension, file); }
        return name.endsWith(extension) ? [file] : [];
    });
}

// The client's scripts as the build concatenates them: the shared data right after namespace.js.
function clientScripts() {

    if (BUILT) {
        return [['build/client/frametrail-conversational-ui.js', fs.readFileSync(BUNDLE, 'utf8')]];
    }

    return JS_FILES.flatMap((file) => {
        const script = [['client/' + file, fs.readFileSync(path.join(CLIENT, file), 'utf8')]];
        if (file !== 'namespace.js') { return script; }
        return script.concat(SHARED_DATA.map(({ property, file: data }) => ['shared/' + data,
            'window.FrameTrailConversationalUI.' + property + ' = ' + fs.readFileSync(path.join(SHARED, data), 'utf8') + ';']));
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

let frameTrailChecked = false;

function frameTrailScripts() {
    if (!frameTrailChecked) {
        const missing = FRAMETRAIL_SCRIPTS.filter((file) => !fs.existsSync(path.join(FRAMETRAIL, file)));
        assert.deepEqual(missing, [], 'No FrameTrail working copy (1.4.1 or later) at ' + FRAMETRAIL
            + '; set FRAMETRAIL_DIR or pass --frametrail=<dir>');
        frameTrailChecked = true;
    }
    return FRAMETRAIL_SCRIPTS.map((file) => [file, fs.readFileSync(path.join(FRAMETRAIL, file), 'utf8')]);
}

// Runs the client in a fresh context. Without the extension API, FrameTrail
// is there but too old; with frameTrail, its pure scripts run first.
function load({ extensionAPI = true, frameTrail = false } = {}) {

    const registered = {},
          warnings   = [];

    const context = {
        console:    { log() {}, error() {}, warn: (...args) => warnings.push(args.join(' ')) },
        document:   { createElement: element },
        FrameTrail: extensionAPI ? { registerExtension(name, factory) { registered[name] = factory; } } : {}
    };
    context.window = context;
    vm.createContext(context);

    const scripts = (frameTrail ? frameTrailScripts() : []).concat(clientScripts());

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

    test('embeds the shared data the client needs, as properties the namespace declares', () => {
        const namespace = fs.readFileSync(path.join(CLIENT, 'namespace.js'), 'utf8');
        assert.deepEqual(SHARED_DATA.map((entry) => entry.file).sort(), ['changeset.schema.json', 'lint.json', 'operations.json']);
        for (const { property, file } of SHARED_DATA) {
            assert.ok(fs.existsSync(path.join(SHARED, file)), 'shared/' + file);
            assert.match(namespace, new RegExp('^\\s*' + property + ':\\s*null', 'm'), 'namespace.js declares ' + property);
        }
    });

});

if (BUILT) {
    describe('the bundle', () => {
        test('carries the build\'s version', () => {
            const { context } = load();
            assert.equal(typeof context.FrameTrailConversationalUI.version, 'string');
            assert.ok(!context.FrameTrailConversationalUI.version.includes(VERSION_TOKEN.slice(0, 2)), context.FrameTrailConversationalUI.version);
        });
        test('carries the shared data as it is in shared/', () => {
            const { context } = load();
            for (const { property, file } of SHARED_DATA) {
                assert.deepStrictEqual(json(context.FrameTrailConversationalUI[property]), readJSON(path.join(SHARED, file)), file);
            }
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


/* ---------------------------------------------------------------------- */
/*  Operations: the manifest                                              */
/* ---------------------------------------------------------------------- */

// The client with FrameTrail's scripts, once for all operation tests.
let operationsEnvironment = null;

function environment() {
    if (!operationsEnvironment) {
        const { context } = load({ frameTrail: true });
        operationsEnvironment = { context, ns: context.FrameTrailConversationalUI, ops: context.FrameTrailConversationalUI.ops };
    }
    return operationsEnvironment;
}

const MANIFEST = readJSON(path.join(SHARED, 'operations.json'));

describe('shared/operations.json', () => {

    test('follows its meta-schema, which, like the changeset schema, is in FrameTrail\'s schema subset', () => {
        const { context } = environment(),
              metaSchema  = readJSON(path.join(SHARED, 'operations.schema.json')),
              validator   = context.FrameTrailSchema.create([metaSchema, ...context.FrameTrailSchemas]);
        assert.deepEqual(json(validator.validate(metaSchema.$id, MANIFEST)), []);
    });

    test('every input and output schema is in the subset and resolves (with FrameTrail\'s schemas)', () => {
        const { ops } = environment();
        for (const op of MANIFEST.operations) {
            assert.doesNotThrow(() => ops.validateInput(op.name, {}), op.name + ' input');
            assert.doesNotThrow(() => ops.validateOutput(op.name, {}), op.name + ' output');
        }
    });

    test('names each operation once, and each has an implementation', () => {
        const { ops } = environment(),
              names   = MANIFEST.operations.map((op) => op.name);
        assert.equal(new Set(names).size, names.length);
        for (const name of names) { assert.ok(ops.operation(name), name); }
    });

    test('writes work on a hypervideo; reads that need no hypervideo are instance-wide', () => {
        for (const op of MANIFEST.operations) {
            if (op.effect === 'write') { assert.equal(op.scope, 'hypervideo', op.name); }
            if (op.scope === 'instance') { assert.equal(op.effect, 'read', op.name); }
        }
    });

    test('preconditions fit the inputs they look at', () => {
        for (const op of MANIFEST.operations) {
            const properties = op.input.properties || {};
            for (const name of op.preconditions) {
                if (name === 'itemExists')       { assert.ok(properties.ref, op.name + ': itemExists needs ref'); }
                if (name === 'chapterStartFree') { assert.ok(properties.start, op.name + ': chapterStartFree needs start'); }
                if (name === 'subtitles')        { assert.ok(properties.lang, op.name + ': subtitles needs lang'); }
                if (name === 'canEditHypervideo' || name === 'canAnnotate') { assert.equal(op.effect, 'write', op.name + ': ' + name); }
            }
            if (op.effect === 'write') {
                assert.equal(op.preconditions.filter((p) => p === 'canEditHypervideo' || p === 'canAnnotate').length, 1, op.name + ': who may write');
                assert.equal(op.preconditions.includes('canAnnotate'), op.kind === 'annotations', op.name);
            }
        }
    });

    test('the manifest\'s own references stay in the manifest, FrameTrail\'s are absolute', () => {
        const refs = [];
        (function walk(node) {
            if (Array.isArray(node)) { node.forEach(walk); return; }
            if (!isObject(node)) { return; }
            if (typeof node.$ref === 'string') { refs.push(node.$ref); }
            Object.values(node).forEach(walk);
        })(MANIFEST);
        for (const ref of refs) {
            assert.ok(/^#\/\$defs\/[A-Za-z0-9_]+$/.test(ref) && MANIFEST.$defs[ref.split('/').pop()]
                || ref.startsWith('https://frametrail.org/schemas/1/'), ref);
        }
    });

});


const LINT = readJSON(path.join(SHARED, 'lint.json'));

describe('shared/lint.json', () => {

    test('names each rule once, with a severity and a description, and each has an implementation', () => {
        const { ns } = environment(),
              ids    = LINT.rules.map((rule) => rule.id);
        assert.equal(new Set(ids).size, ids.length);
        for (const rule of LINT.rules) {
            assert.match(rule.id, /^[a-z]+(-[a-z]+)*$/, rule.id);
            assert.ok(['error', 'warning'].includes(rule.severity), rule.id + ': ' + rule.severity);
            assert.ok(typeof rule.description === 'string' && rule.description !== '', rule.id);
        }
        assert.deepEqual(Object.keys(ns.lint.RULES).sort(), [...ids].sort());
    });

    test('its $defs are in FrameTrail\'s schema subset', () => {
        const { ns } = environment();
        assert.deepEqual(json(ns.lint.validateResult({ errors: 0, warnings: 0, findings: [] })), []);
        assert.notDeepEqual(json(ns.lint.validateResult({ findings: [{ rule: 'x' }] })), []);
    });

});


/* ---------------------------------------------------------------------- */
/*  Operations: the conformance fixtures                                  */
/* ---------------------------------------------------------------------- */

const fixtureData = (name) => readJSON(path.join(DATA, name + '.json'));

function pointerTokens(pointer) {
    return pointer.split('/').slice(1).map((token) => token.replace(/~1/g, '/').replace(/~0/g, '~'));
}

// Applies a JSON Patch (RFC 6902): add, remove and replace.
function applyPatch(document, patch) {

    assert.ok(Array.isArray(patch), 'after is a JSON Patch: a list of operations');

    for (const operation of patch) {

        const tokens = pointerTokens(operation.path),
              last   = tokens.pop(),
              where  = operation.op + ' ' + operation.path;
        let node = document;

        for (const token of tokens) {
            assert.ok(node !== null && typeof node === 'object' && Object.prototype.hasOwnProperty.call(node, token), 'after: no ' + where);
            node = node[token];
        }
        assert.ok(node !== null && typeof node === 'object', 'after: no ' + where);

        if (Array.isArray(node)) {
            const index = (last === '-') ? node.length : Number(last);
            assert.ok(Number.isInteger(index) && index >= 0 && index <= node.length - (operation.op === 'add' ? 0 : 1), 'after: no ' + where);
            if (operation.op === 'add')          { node.splice(index, 0, clone(operation.value)); }
            else if (operation.op === 'remove')  { node.splice(index, 1); }
            else if (operation.op === 'replace') { node[index] = clone(operation.value); }
            else { assert.fail('after: ' + operation.op + ' is not add, remove or replace'); }
        } else {
            assert.ok(operation.op === 'add' || Object.prototype.hasOwnProperty.call(node, last), 'after: no ' + where);
            if (operation.op === 'remove') { delete node[last]; }
            else if (operation.op === 'add' || operation.op === 'replace') { node[last] = clone(operation.value); }
            else { assert.fail('after: ' + operation.op + ' is not add, remove or replace'); }
        }

    }

    return document;

}

// A bundle as an undo is compared with the original: items in any order,
// properties that are null like missing ones (a merge patch cannot set null),
// an empty annotation file like none (undoing a user's first annotation
// leaves their file empty).
function undoComparable(bundle) {

    const out = clone(bundle);

    const withoutNulls = (value) => {
        if (Array.isArray(value)) { return value.map(withoutNulls); }
        if (!isObject(value)) { return value; }
        const result = {};
        for (const key of Object.keys(value)) {
            if (value[key] !== null) { result[key] = withoutNulls(value[key]); }
        }
        return result;
    };
    const byIdentity = (a, b) => (String(a['frametrail:type']) + ' ' + a.created).localeCompare(String(b['frametrail:type']) + ' ' + b.created);

    for (const hypervideo of (out.bundle === 'project') ? Object.values(out.hypervideos) : [out]) {
        const json = hypervideo.hypervideo;
        if (Array.isArray(json.contents))  { json.contents = json.contents.map(withoutNulls).sort(byIdentity); }
        if (Array.isArray(json.subtitles)) { json.subtitles.sort((a, b) => String(a.srclang).localeCompare(String(b.srclang))); }
        const files = (hypervideo.annotations || {}).files || {};
        for (const id of Object.keys(files)) {
            if (Array.isArray(files[id]) && files[id].length === 0) { delete files[id]; continue; }
            files[id] = files[id].map(withoutNulls).sort(byIdentity);
        }
    }

    return out;

}

const byPathAndMessage = (a, b) => (a.path + '\u0000' + a.message).localeCompare(b.path + '\u0000' + b.message);

/**
 * Runs one case against a fresh model store and checks it by the rules of
 * shared/fixtures/README.md. Returns the names of the operations it used.
 */
// The store options of a case: its own, else its file's.
function storeOptions(fixture, c) {
    return {
        user:         c.user || fixture.user,
        now:          (c.now !== undefined) ? c.now : fixture.now,
        duration:     (c.duration !== undefined) ? c.duration : fixture.duration,
        hypervideoId: (c.hypervideoId !== undefined) ? c.hypervideoId : fixture.hypervideoId
    };
}

function runCase(fixture, c) {

    const { ops } = environment(),
          data    = fixtureData(c.data || fixture.data),
          options = storeOptions(fixture, c),
          context = { generator: fixture.generator },
          store   = ops.modelStore(data, options);

    assert.ok((c.op ? 1 : 0) + (c.changeset ? 1 : 0) === 1, 'a case has either op or changeset');

    const op      = c.op ? ops.operation(c.op.op) : null,
          write   = c.changeset || (op && op.effect === 'write'),
          used    = c.changeset ? (c.changeset.ops || []).map((entry) => entry.op) : [c.op.op];

    let result, applied;

    try {
        if (c.changeset) {
            applied = ops.apply(store, c.changeset, context);
        } else if (write) {
            // One operation, as a changeset of its own, its errors pointing into its input.
            applied = store.transaction(c.op.op, (tx) => {
                const recorder = ops.record(tx, { generator: context.generator });
                return { results: [recorder.run(c.op.op, c.op.input)], changeset: recorder.changeset() };
            });
        } else {
            result = ops.run(store, c.op.op, c.op.input, context);
        }
    } catch (e) {
        if (!c.error) { throw e; }
        assert.equal(e.name, 'ConversationalUiOpError', 'an op error: ' + e.stack);
        assert.equal(e.code, c.error.code, e.message);
        if (c.error.errors) {
            assert.deepStrictEqual(json(e.errors).sort(byPathAndMessage), clone(c.error.errors).sort(byPathAndMessage), e.message);
        }
        assert.deepStrictEqual(json(store.data()), data, 'a refused operation changes nothing');
        return used;
    }

    assert.ok(!c.error, 'expected ' + JSON.stringify(c.error));

    if (applied) {

        const changeset = json(applied.changeset),
              results   = json(applied.results);

        assert.deepEqual(json(ops.validator().validate(environment().ns.changesetSchema.$id, changeset)), [], 'the applied changeset follows its schema');
        changeset.ops.forEach((entry, i) => {
            assert.deepEqual(json(ops.validateOutput(entry.op, results[i])), [], entry.op + ': its result follows its output schema');
        });

        if (c.result !== undefined)   { assert.deepStrictEqual(results[0], c.result); }
        if (c.results !== undefined)  { assert.deepStrictEqual(results, c.results); }
        if (c.inverse !== undefined)  { assert.deepStrictEqual(changeset.ops[0].inverse, c.inverse); }
        if (c.inverses !== undefined) { assert.deepStrictEqual(changeset.ops.map((entry) => entry.inverse), c.inverses); }
        if (c.applied !== undefined) {
            for (const key of Object.keys(c.applied)) { assert.deepStrictEqual(changeset[key], c.applied[key], 'applied ' + key); }
        }

        assert.deepStrictEqual(json(store.data()), applyPatch(clone(data), c.after || []), 'the bundle after');

        // Undo gives back what was there.
        const reference = json(ops.modelStore(data, options).data({ all: true }));
        ops.undo(store, applied.changeset);
        assert.deepStrictEqual(undoComparable(json(store.data({ all: true }))), undoComparable(reference), 'undone');

    } else {

        assert.deepEqual(json(ops.validateOutput(c.op.op, json(result))), [], 'the result follows the output schema');
        if (c.result !== undefined) { assert.deepStrictEqual(json(result), c.result); }
        assert.deepStrictEqual(json(store.data()), data, 'a read changes nothing');

    }

    return used;

}

/**
 * Runs one lint case: the case's patch applied to its data, a model store
 * made, the rules run (all, or the case's rules). Returns the rules found.
 */
function runLintCase(fixture, c) {

    const { ns } = environment(),
          data   = applyPatch(fixtureData(c.data || fixture.data), c.patch || []),
          store  = ns.ops.modelStore(data, storeOptions(fixture, c)),
          result = json(ns.lint.run(store, c.rules ? { rules: c.rules } : undefined));

    assert.deepEqual(json(ns.lint.validateResult(result)), [], 'the result follows $defs/result');
    assert.equal(result.errors, result.findings.filter((f) => f.severity === 'error').length, 'errors counts the errors');
    assert.equal(result.warnings, result.findings.filter((f) => f.severity === 'warning').length, 'warnings counts the warnings');
    assert.deepStrictEqual(result.findings, c.findings);

    return result.findings.map((finding) => finding.rule);

}

describe('shared/fixtures', () => {

    const covered = new Set(),
          found   = new Set();

    test('hold ' + FIXTURE_FOLDERS.join(', ') + ' and README.md, and nothing else', () => {
        assert.deepEqual(fs.readdirSync(FIXTURES).filter((name) => name !== 'README.md' && name !== '.DS_Store').sort(), [...FIXTURE_FOLDERS].sort());
    });

    test('every data set is a valid bundle (FrameTrail\'s schemas)', () => {
        const { context } = environment(),
              validator   = context.FrameTrailSchema.create(context.FrameTrailSchemas);
        for (const name of fs.readdirSync(DATA).filter((file) => file.endsWith('.json'))) {
            const data = readJSON(path.join(DATA, name));
            assert.deepEqual(json(validator.validate(data.bundle === 'project' ? 'project-bundle.schema.json' : 'hypervideo-bundle.schema.json', data)), [], name);
        }
    });

    for (const file of fixtureFiles('ops')) {

        const fixture = readJSON(path.join(FIXTURES, 'ops', file));

        describe('ops/' + file, () => {
            for (const c of fixture.cases) {
                test(c.name, () => {
                    for (const name of runCase(fixture, c)) { covered.add(name); }
                });
            }
        });

    }

    test('cover every operation', () => {
        assert.deepEqual(MANIFEST.operations.map((op) => op.name).filter((name) => !covered.has(name)), []);
    });

    for (const file of fixtureFiles('lint')) {

        const fixture = readJSON(path.join(FIXTURES, 'lint', file));

        describe('lint/' + file, () => {
            for (const c of fixture.cases) {
                test(c.name, () => {
                    for (const rule of runLintCase(fixture, c)) { found.add(rule); }
                });
            }
        });

    }

    test('find something for every lint rule', () => {
        assert.deepEqual(LINT.rules.map((rule) => rule.id).filter((id) => !found.has(id)), []);
    });

});


/* ---------------------------------------------------------------------- */
/*  Operations: FrameTrail's data                                         */
/* ---------------------------------------------------------------------- */

// A _data folder as the serializer's folder format reads it.
function folder(dir) {
    const files = {};
    (function walk(rel) {
        for (const name of fs.readdirSync(path.join(dir, rel)).sort()) {
            const file = rel ? rel + '/' + name : name,
                  full = path.join(dir, file);
            if (fs.statSync(full).isDirectory()) { walk(file); }
            else if (name.endsWith('.json')) { files[file] = readJSON(full); }
            else if (/\.(vtt|css)$/.test(name)) { files[file] = fs.readFileSync(full, 'utf8'); }
        }
    })('');
    return files;
}

describe('reading FrameTrail\'s fixtures', () => {

    const dataDir = path.join(FRAMETRAIL, 'tests', 'fixtures', 'data');

    for (const name of fs.existsSync(dataDir) ? fs.readdirSync(dataDir).sort() : []) {

        test(name + ': every read gives a result that follows its output schema, and lint one that follows its own', () => {

            const { context, ops } = environment(),
                  project = context.FrameTrailSerializer.readBundle(folder(path.join(dataDir, name)), 'folder', { bundle: 'project' });

            for (const id of Object.keys(project.hypervideos)) {

                const store = ops.modelStore(project, { hypervideoId: id, user: { id: '1', name: 'demo', role: 'admin' } });

                for (const op of MANIFEST.operations.filter((o) => o.effect === 'read')) {
                    let output;
                    try {
                        output = ops.run(store, op.name, op.name === 'find_in_transcript' ? { query: 'the' } : (op.name === 'get_item' ? { kind: 'chapters', ref: 0 } : {}));
                    } catch (e) {
                        assert.equal(e.name, 'ConversationalUiOpError', name + ' ' + id + ' ' + op.name + ': ' + e.stack);
                        assert.ok(e.code === 'notFound', name + ' ' + id + ' ' + op.name + ': ' + e.message);
                        continue;
                    }
                    assert.deepEqual(json(ops.validateOutput(op.name, json(output))), [], name + ' ' + id + ' ' + op.name);
                }

                assert.deepEqual(json(context.FrameTrailConversationalUI.lint.validateResult(json(context.FrameTrailConversationalUI.lint.run(store)))), [], name + ' ' + id + ' lint');

            }

        });

    }

});


/* ---------------------------------------------------------------------- */
/*  Operations: helpers and the live store                                */
/* ---------------------------------------------------------------------- */

describe('ops.util', () => {

    test('mergePatch follows RFC 7386', () => {
        const { util } = environment().ops;
        assert.deepStrictEqual(json(util.mergePatch({ a: 'b', c: { d: 'e', f: 'g' } }, { a: 'z', c: { f: null } })), { a: 'z', c: { d: 'e' } });
        assert.deepStrictEqual(json(util.mergePatch({ a: [1, 2] }, { a: [3] })), { a: [3] });
        assert.deepStrictEqual(json(util.mergePatch({ a: 1 }, { b: { c: null } })), { a: 1, b: {} });
        assert.deepStrictEqual(json(util.mergePatch([1], { a: 1 })), { a: 1 });
    });

    test('diffPatch makes the merge patch from one value to another', () => {
        const { util } = environment().ops;
        const pairs = [
            [{ a: 1, b: { c: 2, d: 3 }, e: [1] }, { a: 1, b: { c: 4 }, e: [1, 2], f: 'x' }],
            [{ a: { b: 1 } }, { a: 5 }],
            [{ a: 5 }, { a: { b: 1 } }],
            [{ a: null }, {}],
            [{}, { a: { b: { c: 1 } } }]
        ];
        for (const [from, to] of pairs) {
            assert.deepStrictEqual(json(util.mergePatch(from, util.diffPatch(from, to))), to, JSON.stringify([from, to]));
        }
        assert.equal(util.diffPatch({ a: [1, { b: 2 }] }, { a: [1, { b: 2 }] }), undefined);
        assert.deepStrictEqual(json(util.diffPatch({ a: 1 }, { a: null })), { a: null });
    });

    test('plainText decodes, strips tags and collapses white space, also for escaped HTML', () => {
        const { util } = environment().ops;
        assert.equal(util.plainText('&lt;p&gt;Fish &amp;amp; chips&lt;/p&gt;'), 'Fish & chips');
        assert.equal(util.plainText('<p>One</p>\n<p>two&nbsp;&#8211; &#x2014;</p>'), 'One two – —');
        assert.equal(util.plainText('a < b, c > d'), 'a < b, c > d');
        assert.equal(util.plainText('&unknown; &#0; &#xD800;'), '&unknown; &#0; &#xD800;');
        assert.equal(util.plainText(42), '');
    });

    test('excerpt cuts at code points', () => {
        const { util } = environment().ops;
        assert.equal(util.excerpt('abcdef', 3), 'abc…');
        assert.equal(util.excerpt('ab 😀de', 4), 'ab 😀…');
        assert.equal(util.excerpt('abc', 3), 'abc');
    });

    test('cues reads WebVTT: identifiers, hours, CRLF, BOM, notes, styles, tags and character references', () => {
        const { util } = environment().ops;
        const vtt = '﻿WEBVTT - a title\r\n\r\nSTYLE\r\n::cue { color: red }\r\n\r\nNOTE a note\r\n\r\nintro\r\n00:01.500 --> 00:04.000 align:start\r\n<v Ada>Hello <b>there</b></v> &amp; welcome\r\nsecond line\r\n\r\n01:00:00.000 --> 01:00:02,25\r\nAn hour in\r\n\r\n00:05.000 --> 00:06.000\r\n<i></i>\r\n';
        assert.deepStrictEqual(json(util.cues(vtt)), [
            { start: 1.5, end: 4, text: 'Hello there & welcome second line' },
            { start: 3600, end: 3602.25, text: 'An hour in' }
        ]);
        assert.deepStrictEqual(json(util.cues(null)), []);
    });

    test('Media Fragments: time spans, boxes, and the values written', () => {
        const { util } = environment().ops;
        assert.deepStrictEqual(json(util.timeSpan('t=12.5,20&xywh=percent:1,2,3,4')), { start: 12.5, end: 20 });
        assert.deepStrictEqual(json(util.timeSpan('t=60')), { start: 60, end: 60 });
        assert.deepStrictEqual(json(util.box('t=1,2&xywh=percent:-5,2.5,30,1e1')), { left: -5, top: 2.5, width: 30, height: 10 });
        assert.equal(util.box('t=1,2'), null);
        assert.equal(util.fragment(0.1 + 0.2, 1e21, { left: 1, top: 2, width: 3, height: 4 }), 't=0.30000000000000004,1e+21&xywh=percent:1,2,3,4');
        assert.deepStrictEqual(json(util.clipSpan([{ in: 12, out: 132, duration: 0 }])), { start: 12, duration: 120 });
        assert.deepStrictEqual(json(util.clipSpan([{ duration: 0 }])), { start: 0, duration: null });
        assert.deepStrictEqual(json(util.clipSpan([{ duration: 0 }], 600)), { start: 0, duration: 600 });
    });

});

describe('ops.liveStore', () => {

    // FrameTrail's edit API, as far as the live store uses it, recording what it is asked.
    function frameTrail() {

        const calls = [], state = { allowed: true };

        const edit = {
            getHypervideo:   () => ({ meta: { creatorId: '1', lastchanged: 5 }, clips: [] }),
            getInfo:         () => ({ id: '7', video: 'seven.mp4', start: 10, end: 60, duration: 50 }),
            getUser:         () => ({ id: '1', name: 'Ada', role: 'user', guest: false }),
            permission:      (kind) => {
                calls.push(['permission', kind]);
                return state.allowed ? { allowed: true } : { allowed: false, code: 'notAllowed', message: 'Changes can only be made in edit mode' };
            },
            listHypervideos: () => [
                { id: '7', open: true, meta: { name: 'Seven' }, clips: [{ duration: 50 }], subtitles: [] },
                { id: '8', open: false, meta: { name: 'Eight' }, clips: [{ duration: 20 }], subtitles: [{ src: 'de.vtt', srclang: 'de' }] }
            ],
            list:         (kind) => { calls.push(['list', kind]); return []; },
            get:          (kind, ref) => { calls.push(['get', kind, ref]); return null; },
            add:          (kind, data) => { calls.push(['add', kind, data]); return Object.assign({}, data); },
            update:       () => { throw new Error('not used'); },
            remove:       () => { throw new Error('not used'); },
            setLayout:    () => { throw new Error('not used'); },
            setSubtitles: () => { throw new Error('not used'); },
            transaction:  (description, fn) => {
                calls.push(['transaction', description]);
                return fn(Object.assign({}, edit, { signal: 'the signal' }));
            }
        };

        // Only edit: the live store reads no module and no state.
        const instance = {
            edit,
            getState: (name) => { throw new Error('read the state ' + name); },
            module:   (name) => { throw new Error('read the module ' + name); }
        };

        return { calls, instance, state };

    }

    test('applies a changeset in one transaction of FrameTrail\'s edit API', () => {
        const { ops } = environment(),
              { calls, instance } = frameTrail(),
              store   = ops.liveStore(instance),
              applied = ops.apply(store, { summary: 'Two chapters', ops: [
                  { op: 'add_chapter', input: { start: 0, title: 'One' } },
                  { op: 'add_chapter', input: { start: 20, title: 'Two' } }
              ] });
        assert.deepStrictEqual(json(calls.filter((entry) => ['transaction', 'permission', 'add'].includes(entry[0]))), [
            ['transaction', 'Two chapters'],
            ['permission', 'chapters'],
            ['add', 'chapters', { start: 0, title: 'One' }],
            ['permission', 'chapters'],
            ['add', 'chapters', { start: 20, title: 'Two' }]
        ]);
        const changeset = json(applied.changeset);
        assert.equal(changeset.hypervideoId, '7');
        assert.equal(changeset.createdBy, '1');
        assert.deepStrictEqual(changeset.baseVersion, { hypervideo: 5 });
        assert.deepStrictEqual(changeset.ops.map((entry) => entry.inverse), [
            { method: 'remove', args: ['chapters', 0] },
            { method: 'remove', args: ['chapters', 20] }
        ]);
    });

    test('reads what edit tells: the hypervideos, their durations, the permissions', () => {
        const { ops } = environment(),
              { instance, state } = frameTrail(),
              store = ops.liveStore(instance);
        assert.deepStrictEqual(json(ops.run(store, 'list_hypervideos', {})), { hypervideos: [
            { id: '7', name: 'Seven', duration: 50, subtitles: [], open: true },
            { id: '8', name: 'Eight', duration: 20, subtitles: ['de'], open: false }
        ] });
        state.allowed = false;
        assert.throws(() => ops.apply(store, { ops: [{ op: 'add_chapter', input: { start: 1, title: 'x' } }] }),
            (e) => e.code === 'notAllowed' && e.message.includes('edit mode'));
    });

    test('hands a transaction its stop signal', () => {
        const { ops } = environment(),
              { instance } = frameTrail(),
              store = ops.liveStore(instance);
        assert.equal(store.transaction('x', (tx, signal) => signal), 'the signal');
    });

    test('says what it needs from a FrameTrail whose edit API lacks the reads', () => {
        const { ops } = environment();
        assert.throws(() => ops.liveStore({ edit: { getHypervideo() {} } }), /getInfo\(\)/);
    });

});
