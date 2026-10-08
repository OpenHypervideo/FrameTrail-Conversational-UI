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
const SHARED_TEXT = buildList('SHARED_TEXT').map((entry) => ({ name: entry.split(':')[0], file: entry.slice(entry.indexOf(':') + 1) }));

// A prompt file as the build writes it into the bundle: the last line break dropped.
const promptText = (file) => fs.readFileSync(path.join(SHARED, file), 'utf8').replace(/\r/g, '').replace(/\n$/, '');

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
        const prompts = {};
        for (const { name, file: text } of SHARED_TEXT) { prompts[name] = promptText(text); }
        return script.concat(SHARED_DATA.map(({ property, file: data }) => ['shared/' + data,
            'window.FrameTrailConversationalUI.' + property + ' = ' + fs.readFileSync(path.join(SHARED, data), 'utf8') + ';']),
            [['shared/prompts', 'window.FrameTrailConversationalUI.prompts = ' + JSON.stringify(prompts) + ';']]);
    });

}


/* ---------------------------------------------------------------------- */
/*  A DOM, as far as the panel uses one                                   */
/* ---------------------------------------------------------------------- */

class FakeNode {

    constructor(tagName, document) {
        this.tagName = tagName ? tagName.toUpperCase() : undefined;
        this.nodeType = tagName ? 1 : 11;
        this.ownerDocument = document;
        this.childNodes = [];
        this.parentNode = null;
        this.attributes = {};
        this.listeners = {};
        this.style = {};
        this.hidden = false;
        this.disabled = false;
        this.value = '';
        this._className = '';
    }

    get children() { return this.childNodes.filter((node) => node.nodeType === 1); }
    get firstChild() { return this.childNodes[0] || null; }
    get lastChild() { return this.childNodes[this.childNodes.length - 1] || null; }

    get className() { return this._className; }
    set className(value) { this._className = String(value); }

    get classList() {
        const node = this, list = () => node._className.split(/\s+/).filter(Boolean);
        return {
            contains: (name) => list().includes(name),
            add: (...names) => { node._className = [...new Set(list().concat(names))].join(' '); },
            remove: (...names) => { node._className = list().filter((n) => !names.includes(n)).join(' '); },
            toggle(name, force) {
                const on = (force === undefined) ? !this.contains(name) : !!force;
                if (on) { this.add(name); } else { this.remove(name); }
                return on;
            }
        };
    }

    get textContent() { return this.childNodes.map((node) => node.textContent).join(''); }
    set textContent(value) {
        this.childNodes.forEach((node) => { node.parentNode = null; });
        this.childNodes = [];
        if (value !== '' && value !== null && value !== undefined) { this.append(this.ownerDocument.createTextNode(String(value))); }
    }

    set innerHTML(value) {
        assert.equal(value, '', 'the client only empties elements through innerHTML');
        this.textContent = '';
    }

    setAttribute(name, value) { this.attributes[name] = String(value); if (name === 'id') { this.id = String(value); } }
    getAttribute(name) { return (name in this.attributes) ? this.attributes[name] : null; }
    removeAttribute(name) { delete this.attributes[name]; }

    append(...nodes) { for (const node of nodes) { this.insertBefore(node, null); } }

    insertBefore(node, before) {
        if (typeof node === 'string') { node = this.ownerDocument.createTextNode(node); }
        const moving = (node.nodeType === 11) ? [...node.childNodes] : [node];
        if (node.nodeType === 11) { node.childNodes = []; }
        for (const child of moving) {
            if (child.parentNode) { child.remove(); }
            const at = before ? this.childNodes.indexOf(before) : -1;
            if (at < 0) { this.childNodes.push(child); } else { this.childNodes.splice(at, 0, child); }
            child.parentNode = this;
        }
        return node;
    }

    remove() {
        if (!this.parentNode) { return; }
        const siblings = this.parentNode.childNodes;
        siblings.splice(siblings.indexOf(this), 1);
        this.parentNode = null;
    }

    addEventListener(type, handler) { (this.listeners[type] = this.listeners[type] || []).push(handler); }
    removeEventListener(type, handler) { this.listeners[type] = (this.listeners[type] || []).filter((h) => h !== handler); }

    dispatch(type, init = {}) {
        const event = Object.assign({ type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } }, init);
        for (const handler of [...(this.listeners[type] || [])]) { handler.call(this, event); }
        return event;
    }

    click() { if (!this.disabled) { this.dispatch('click'); } }
    focus() { this.ownerDocument.activeElement = this; }

    // Every element below, in document order.
    descendants() { return this.children.flatMap((child) => [child, ...child.descendants()]); }

    // Selectors as the client and the tests use them: tag names and .classes, one of each at most.
    matches(selector) {
        const m = /^([a-z]*)((?:\.[A-Za-z0-9_-]+)*)$/.exec(selector);
        assert.ok(m, 'an unsupported selector: ' + selector);
        return (!m[1] || this.tagName === m[1].toUpperCase())
            && m[2].split('.').filter(Boolean).every((name) => this.classList.contains(name));
    }

    querySelector(selector) { return this.descendants().find((node) => node.matches(selector)) || null; }
    querySelectorAll(selector) { return this.descendants().filter((node) => node.matches(selector)); }

    // Layout is not computed: nothing is scrolled.
    get scrollHeight() { return 0; }
    get clientHeight() { return 0; }

}

class FakeText {
    constructor(text) { this.nodeType = 3; this.data = text; this.parentNode = null; }
    get textContent() { return this.data; }
    set textContent(value) { this.data = String(value); }
    remove() { FakeNode.prototype.remove.call(this); }
}

function fakeDocument() {
    const document = {
        activeElement: null,
        createElement: (tagName) => new FakeNode(tagName, document),
        createTextNode: (text) => new FakeText(String(text)),
        createDocumentFragment: () => new FakeNode(null, document)
    };
    return document;
}

// A DOM element, as far as the client uses one.
function element(tagName) {
    return fakeDocument().createElement(tagName);
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
function load({ extensionAPI = true, frameTrail = false, fetch } = {}) {

    const registered = {},
          warnings   = [],
          errors     = [],
          storage    = {};

    const context = {
        console:    { log() {}, error: (...args) => errors.push(args.join(' ')), warn: (...args) => warnings.push(args.join(' ')) },
        document:   fakeDocument(),
        FrameTrail: extensionAPI ? { registerExtension(name, factory) { registered[name] = factory; } } : {},
        localStorage: {
            getItem: (key) => (key in storage ? storage[key] : null),
            setItem: (key, value) => { storage[key] = String(value); },
            removeItem: (key) => { delete storage[key]; }
        },
        fetch: fetch || (() => Promise.reject(new Error('no network in the tests'))),
        TextDecoder, TextEncoder, AbortController, URLSearchParams,
        setTimeout, clearTimeout, setInterval, clearInterval
    };
    context.window = context;
    vm.createContext(context);

    const scripts = (frameTrail ? frameTrailScripts() : []).concat(clientScripts());

    for (const [filename, source] of scripts) {
        vm.runInContext(source, context, { filename });
    }

    return { context, registered, warnings, errors, storage };

}

// The internal instance an extension's factory gets, as far as the client
// uses it: Localization with addLabels() and labels in one language, falling
// back to English per key like FrameTrail's; for the panel also the state
// (storageMode, viewMode, editMode, editBusy), events, StorageManager's
// serverPost() (answering the status action with options.status) and
// extensionURL(), UndoManager (a stack of descriptions that edit's
// transactions push onto), and edit (options.edit, e.g. a model store).
function instance(language = 'en', options = {}) {

    const tables = {}, listeners = {}, posted = [];

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

    const state = Object.assign({ storageMode: 'download', viewMode: 'video', editMode: 'overlays', editBusy: false }, options.state);

    const trigger = (type, detail) => { for (const handler of listeners[type] || []) { handler({ type, detail }); } };

    const UndoManager = {
        undoStack: [],
        redoStack: [],
        getUndoDescription() { return this.undoStack[this.undoStack.length - 1] || null; },
        getRedoDescription() { return this.redoStack[this.redoStack.length - 1] || null; },
        undo() { if (!this.undoStack.length) { return false; } this.redoStack.push(this.undoStack.pop()); trigger('undoStateChanged'); return true; },
        redo() { if (!this.redoStack.length) { return false; } this.undoStack.push(this.redoStack.pop()); trigger('undoStateChanged'); return true; },
        register(description) { this.undoStack.push(description); this.redoStack = []; trigger('undoStateChanged'); }
    };

    const StorageManager = {
        posted,
        serverPost(body) {
            posted.push(Object.fromEntries(body.entries()));
            return (options.status === undefined) ? Promise.reject(new Error('no server')) : Promise.resolve(options.status);
        },
        extensionURL: (name, route) => '_server/extension.php?e=' + name + '&r=' + route
    };

    // edit over a store: a transaction that changed something is a step on the undo stack.
    const edit = options.edit ? Object.assign({}, options.edit, {
        transaction(description, fn) {
            const before = JSON.stringify(options.edit.data()),
                  result = options.edit.transaction(description, fn),
                  done   = () => { if (JSON.stringify(options.edit.data()) !== before) { UndoManager.register(description); } };
            return (result && typeof result.then === 'function') ? result.then((value) => { done(); return value; }) : (done(), result);
        }
    }) : undefined;

    const modules = { Localization, StorageManager, UndoManager };

    return {
        module: (name) => modules[name],
        getState: (name) => state[name],
        changeState: (name, value) => { state[name] = value; },
        addEventListener: (type, handler) => { (listeners[type] = listeners[type] || []).push(handler); },
        removeEventListener: (type, handler) => { listeners[type] = (listeners[type] || []).filter((h) => h !== handler); },
        edit,
        Localization, StorageManager, UndoManager, state, trigger
    };

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

    test('embeds every prompt in shared/prompts/ as text, which holds no tabs or other control characters', () => {
        const namespace = fs.readFileSync(path.join(CLIENT, 'namespace.js'), 'utf8');
        assert.match(namespace, /^\s*prompts:\s*null/m, 'namespace.js declares prompts');
        assert.deepEqual(SHARED_TEXT.map((entry) => entry.file).sort(),
            fs.readdirSync(path.join(SHARED, 'prompts')).filter((name) => name.endsWith('.md')).map((name) => 'prompts/' + name).sort());
        assert.deepEqual(SHARED_TEXT.map((entry) => entry.name), ['system', 'conversation', 'types']);
        for (const { file } of SHARED_TEXT) {
            const text = fs.readFileSync(path.join(SHARED, file), 'utf8');
            assert.doesNotMatch(text, /[\u0000-\u0009\u000b-\u001f\u007f]/, file + ' holds a control character');
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
        test('carries the prompts as they are in shared/prompts/', () => {
            const { context } = load();
            assert.deepEqual(Object.keys(context.FrameTrailConversationalUI.prompts), SHARED_TEXT.map((entry) => entry.name));
            for (const { name, file } of SHARED_TEXT) {
                assert.equal(context.FrameTrailConversationalUI.prompts[name], promptText(file), file);
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
        assert.equal(panel.icon, 'icon-ai');
        assert.equal(panel.label, context.FrameTrailConversationalUI.labels.en.ConversationalUiTitle);
        assert.equal(typeof panel.create, 'function');
    });

    test('labels the panel in the interface language', () => {
        const { context, registered } = load(),
              extension = registered[NAME](instance('de'));
        assert.equal(extension.slots.sidePanel.label, context.FrameTrailConversationalUI.labels.de.ConversationalUiTitle);
    });

    test('builds the chat panel: a bar, settings (closed), the conversation, a notice and the composer', () => {
        const { context, registered } = load(),
              extension = registered[NAME](instance()),
              container = context.document.createElement('div');
        extension.slots.sidePanel.create(container, { open() {}, close() {}, toggle() {}, isOpen: false });
        assert.equal(container.children.length, 1);
        const root = container.children[0];
        assert.equal(root.className, 'conversationalUi');
        assert.deepEqual(root.children.map((child) => child.className.split(' ').find((name) => name.startsWith('conversationalUi'))),
            ['conversationalUiBar', 'conversationalUiSettingsBox', 'conversationalUiLogs', 'conversationalUiNotice', 'conversationalUiComposer']);
        assert.equal(root.querySelector('.conversationalUiSettingsBox').hidden, true);
        assert.ok(root.querySelector('textarea'));
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
                        output = ops.run(store, op.name, op.name === 'find_in_transcript' ? { query: 'the' }
                            : op.name === 'get_item' ? { kind: 'chapters', ref: 0 }
                            : op.name === 'describe_type' ? { type: 'text' } : {});
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
                  { op: 'add_chapter', input: { start: 15, title: 'One' } },
                  { op: 'add_chapter', input: { start: 20, title: 'Two' } }
              ] });
        assert.deepStrictEqual(json(calls.filter((entry) => ['transaction', 'permission', 'add'].includes(entry[0]))), [
            ['transaction', 'Two chapters'],
            ['permission', 'chapters'],
            ['add', 'chapters', { start: 15, title: 'One' }],
            ['permission', 'chapters'],
            ['add', 'chapters', { start: 20, title: 'Two' }]
        ]);
        const changeset = json(applied.changeset);
        assert.equal(changeset.hypervideoId, '7');
        assert.equal(changeset.createdBy, '1');
        assert.deepStrictEqual(changeset.baseVersion, { hypervideo: 5 });
        assert.deepStrictEqual(changeset.ops.map((entry) => entry.inverse), [
            { method: 'remove', args: ['chapters', 15] },
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


/* ---------------------------------------------------------------------- */
/*  describe_type                                                         */
/* ---------------------------------------------------------------------- */

describe('describe_type', () => {

    const BASE = 'https://frametrail.org/schemas/1/';

    function schemaDocuments(context) {
        const documents = {};
        for (const schema of context.FrameTrailSchemas) { documents[schema.$id] = schema; }
        return documents;
    }

    const typesOf = (body) => body.oneOf.map((alternative) => alternative.properties['frametrail:type'].const).filter((type) => type !== 'button');

    test('every type of FrameTrail\'s body schemas: as its output schema says, its attributes FrameTrail\'s, standing alone, without legacy keys', () => {
        const { context, ops } = environment(),
              documents   = schemaDocuments(context),
              overlays    = typesOf(documents[BASE + 'content-item.schema.json'].$defs.overlay.properties.body),
              annotations = typesOf(documents[BASE + 'annotation-file.schema.json'].$defs.annotation.properties.body),
              store       = ops.modelStore(readJSON(path.join(DATA, 'lecture.json')), { user: { id: '1', name: 'Ada', role: 'user' } });
        assert.ok(overlays.length > 20, 'overlay types found');
        for (const type of new Set(overlays.concat(annotations))) {
            const result = json(ops.run(store, 'describe_type', { type }));
            assert.deepEqual(json(ops.validateOutput('describe_type', result)), [], type);
            assert.equal(result.overlay, overlays.includes(type), type + ': overlay');
            assert.equal(result.annotation, annotations.includes(type), type + ': annotation');
            const text = JSON.stringify(result.attributes);
            assert.ok(!text.includes('$ref'), type + ': no references left');
            for (const legacy of ['animationIn', 'animationOut', 'animationDuration']) {
                assert.ok(!(legacy in (result.attributes.properties || {})), type + ': no ' + legacy);
            }
            const own = Object.keys(documents[BASE + 'attributes/' + type + '.schema.json'].properties || {});
            assert.deepEqual(Object.keys(result.attributes.properties || {}).filter((key) => !own.includes(key)), [], type + ': only its own attributes');
        }
    });

    test('says where the src goes, as FrameTrail\'s serializer writes it (docs/DATA-MODEL.md, Resource types)', () => {
        const { ops } = environment(),
              store = ops.modelStore(readJSON(path.join(DATA, 'lecture.json')), { user: { id: '1', name: 'Ada', role: 'user' } }),
              place = (type) => json(ops.run(store, 'describe_type', { type })).src;
        for (const type of ['text', 'quiz', 'webpage', 'wikipedia', 'entity']) { assert.equal(place(type), 'value', type); }
        for (const type of ['html', 'image', 'video', 'audio', 'pdf', 'youtube', 'vimeo', 'soundcloud', 'mastodon', 'urlpreview', 'figma']) { assert.equal(place(type), 'source', type); }
        for (const type of ['hotspot', 'cursor', 'counter', 'chart']) { assert.equal(place(type), null, type); }
    });

});


/* ---------------------------------------------------------------------- */
/*  The model's tools                                                     */
/* ---------------------------------------------------------------------- */

describe('agent.tools', () => {

    const KEYWORDS = ['type', 'description', 'properties', 'required', 'items', 'enum', 'minimum', 'maximum', 'minItems', 'maxItems', 'anyOf', 'default'];

    // Every keyword of a tool's parameters, wherever it sits.
    function keywords(schema, found = new Set()) {
        if (!isObject(schema)) { return found; }
        for (const key of Object.keys(schema)) {
            found.add(key);
            if (key === 'properties') { for (const name in schema.properties) { keywords(schema.properties[name], found); } }
            if (key === 'items') { keywords(schema.items, found); }
            if (key === 'anyOf') { schema.anyOf.forEach((alternative) => keywords(alternative, found)); }
        }
        return found;
    }

    function lecture(ops, user) {
        return ops.modelStore(readJSON(path.join(DATA, 'lecture.json')), { user });
    }

    test('one tool per operation, its parameters standing alone, in the keywords tools keep, all together under 20 KB', () => {
        const { ns, ops } = environment(),
              tools = json(ns.agent.tools(lecture(ops, { id: '1', name: 'Ada', role: 'user' })));
        assert.deepEqual(tools.map((tool) => tool.function.name), MANIFEST.operations.map((op) => op.name));
        for (const tool of tools) {
            assert.equal(tool.type, 'function');
            assert.equal(tool.function.parameters.type, 'object', tool.function.name);
            assert.ok(!JSON.stringify(tool).includes('$ref'), tool.function.name + ': no references left');
            assert.deepEqual([...keywords(tool.function.parameters)].filter((key) => !KEYWORDS.includes(key)), [], tool.function.name);
        }
        assert.ok(JSON.stringify(tools).length < 20000, JSON.stringify(tools).length + ' bytes');
    });

    test('a body is an object that points to describe_type, a keyframe is inlined', () => {
        const { ns, ops } = environment(),
              tools = json(ns.agent.tools(lecture(ops, { id: '1', name: 'Ada', role: 'user' }))),
              add   = tools.find((tool) => tool.function.name === 'add_overlay').function.parameters;
        assert.equal(add.properties.body.type, 'object');
        assert.match(add.properties.body.description, /describe_type/);
        assert.deepEqual(add.properties.keyframes.items.required, ['t', 'xywh']);
        assert.deepEqual(add.required, ['start', 'end', 'body']);
    });

    test('offers only the changes the user may make', () => {
        const { ns, ops } = environment(),
              names = json(ns.agent.tools(lecture(ops, { id: '2', name: 'Bob', role: 'user' }))).map((tool) => tool.function.name);
        for (const op of MANIFEST.operations) {
            const offered = op.effect === 'read' || !op.preconditions.includes('canEditHypervideo');
            assert.equal(names.includes(op.name), offered, op.name);
        }
    });

});


/* ---------------------------------------------------------------------- */
/*  The Chat Completions client                                           */
/* ---------------------------------------------------------------------- */

// An answer as the parts of a fetch Response chat() reads.
function answer(status, body, headers = {}) {
    const text = (typeof body === 'string') ? body : JSON.stringify(body);
    return { ok: status >= 200 && status < 300, status, headers: { get: (name) => headers[name.toLowerCase()] ?? null }, body: null, text: () => Promise.resolve(text) };
}

// A completion of one message.
function completion(message, usage = { prompt_tokens: 10, completion_tokens: 5 }) {
    return answer(200, { model: 'test-model', choices: [{ index: 0, message, finish_reason: message.tool_calls ? 'tool_calls' : 'stop' }], usage });
}

// A stream of server-sent events, cut into pieces of a given size, aborted with the signal.
function stream(events, size, signal) {
    const bytes = new TextEncoder().encode(events.map((event) => 'data: ' + (typeof event === 'string' ? event : JSON.stringify(event)) + '\n\n').join(''));
    let at = 0;
    return {
        ok: true, status: 200, headers: { get: () => null }, text: () => Promise.resolve(''),
        body: {
            getReader: () => ({
                read: () => {
                    if (signal && signal.aborted) { return Promise.reject(new Error('aborted')); }
                    if (at >= bytes.length) { return Promise.resolve({ done: true }); }
                    const value = bytes.slice(at, at + size);
                    at += size;
                    return Promise.resolve({ done: false, value });
                },
                cancel: () => Promise.resolve()
            })
        }
    };
}

describe('models.chat', () => {

    const chunk = (delta, extra = {}) => Object.assign({ choices: [{ index: 0, delta }] }, extra);

    const EVENTS = [
        chunk({ role: 'assistant', content: '' }),
        chunk({ content: 'Hel' }),
        chunk({ content: 'lo ✓' }),
        chunk({ tool_calls: [{ index: 0, id: 'abcdefghi', type: 'function', function: { name: 'add_chapter', arguments: '{"start":' } }] }),
        chunk({ tool_calls: [{ index: 0, function: { arguments: '12,"title":"Twelve"}' } }] }),
        chunk({ tool_calls: [{ index: 1, id: 'bcdefghij', function: { name: 'list_items', arguments: '{}' } }] }),
        { choices: [{ index: 0, delta: {}, finish_reason: 'tool_calls' }], usage: { prompt_tokens: 100, completion_tokens: 20 } },
        '[DONE]'
    ];

    test('reads a stream cut anywhere: the text, and tool calls that come in pieces or whole', async () => {
        const { ns } = environment();
        for (const size of [1, 2, 3, 7, 64, 100000]) {
            const pieces = [],
                  result = json(await ns.models.chat({ post: (body, signal) => Promise.resolve(stream(EVENTS, size, signal)) }, { model: 'm' }, { onText: (piece) => pieces.push(piece) }));
            assert.deepStrictEqual(result.message, {
                role: 'assistant',
                content: 'Hello ✓',
                tool_calls: [
                    { id: 'abcdefghi', type: 'function', function: { name: 'add_chapter', arguments: '{"start":12,"title":"Twelve"}' } },
                    { id: 'bcdefghij', type: 'function', function: { name: 'list_items', arguments: '{}' } }
                ]
            }, 'pieces of ' + size);
            assert.equal(result.finishReason, 'tool_calls');
            assert.deepStrictEqual(result.usage, { prompt_tokens: 100, completion_tokens: 20 });
            assert.equal(pieces.join(''), 'Hello ✓');
        }
    });

    test('asks for a stream, and keeps the text of content lists, not their thinking', async () => {
        const { ns } = environment(),
              bodies = [],
              result = await ns.models.chat({ post: (body) => { bodies.push(body); return Promise.resolve(stream([
                  chunk({ content: [{ type: 'thinking', thinking: [{ type: 'text', text: 'hmm' }] }] }),
                  chunk({ content: [{ type: 'text', text: 'Yes.' }] }),
                  '[DONE]'
              ], 5)); } }, { model: 'm' });
        assert.equal(bodies[0].stream, true);
        assert.equal(result.message.content, 'Yes.');
        assert.equal(result.message.tool_calls, undefined);
    });

    test('a call with another id under an index already taken is a new call', async () => {
        const { ns } = environment(),
              result = json(await ns.models.chat({ post: () => Promise.resolve(stream([
                  chunk({ tool_calls: [{ index: 0, id: 'aaaaaaaaa', function: { name: 'get_item', arguments: '{"kind":"chapters","ref":0}' } }] }),
                  chunk({ tool_calls: [{ index: 0, id: 'bbbbbbbbb', function: { name: 'get_item', arguments: '{"kind":"chapters","ref":120}' } }] }),
                  '[DONE]'
              ], 1000)) }, { model: 'm' }));
        assert.deepEqual(result.message.tool_calls.map((call) => call.id), ['aaaaaaaaa', 'bbbbbbbbb']);
    });

    test('puts failures into codes: key, rate limit (with Retry-After), model, request, service, network, the relay\'s own', async () => {
        const { ns } = environment();
        const failure = async (response) => {
            try {
                await ns.models.chat({ streaming: false, post: () => (response instanceof Error ? Promise.reject(response) : Promise.resolve(response)) }, { model: 'm' });
            } catch (e) {
                return { code: e.code, message: e.message, retryAfter: e.retryAfter, status: e.status };
            }
            assert.fail('no error');
        };
        assert.equal((await failure(answer(401, { message: 'Unauthorized' }))).code, 'key');
        assert.deepEqual(await failure(answer(429, { message: 'Requests rate limit exceeded' }, { 'retry-after': '7' })),
            { code: 'rateLimit', message: 'Requests rate limit exceeded', retryAfter: 7, status: 429 });
        assert.equal((await failure(answer(400, { object: 'error', message: 'Invalid model: mistral-huge', type: 'invalid_model' }))).code, 'model');
        const request = await failure(answer(422, { detail: [{ loc: ['body', 'tools', 0], msg: 'Field required' }] }));
        assert.deepEqual([request.code, request.message], ['request', 'Field required (body.tools.0)']);
        assert.equal((await failure(answer(503, 'Service unavailable'))).code, 'service');
        assert.equal((await failure(new TypeError('Failed to fetch'))).code, 'network');
        assert.equal((await failure(answer(429, { error: { code: 'quota', message: 'Daily limit reached' } }))).code, 'quota');
        assert.equal((await failure(answer(401, { error: { code: 'login', message: 'Sign in' } }))).code, 'login');
    });

    test('through the relay\'s action: Mistral\'s errors read as in direct mode, the relay\'s refusals by their code', async () => {
        const { ns } = environment();
        const viaAction = async (reply) => {
            const posted  = [],
                  adapter = ns.models.relay({ url: 'relay', post: (body) => { posted.push(Object.fromEntries(body.entries())); return Promise.resolve(reply); } });
            adapter.streaming = false;
            try {
                const result = await ns.models.chat(adapter, { model: 'm', messages: [] });
                return { result, posted };
            } catch (e) {
                return { code: e.code, message: e.message, retryAfter: e.retryAfter, status: e.status, posted };
            }
        };
        const ok = await viaAction({ status: 'success', code: 0, response: { choices: [{ index: 0, message: { role: 'assistant', content: 'Hi' }, finish_reason: 'stop' }] } });
        assert.equal(ok.result.message.content, 'Hi');
        assert.deepEqual(ok.posted, [{ a: 'conversationalUiChat', request: JSON.stringify({ model: 'm', messages: [], stream: false }) }]);
        const model = await viaAction({ status: 'fail', code: 400, string: 'The model service answered with status 400.', upstream: { object: 'error', message: 'Invalid model: m', type: 'invalid_model' } });
        assert.deepEqual([model.code, model.message, model.status], ['model', 'Invalid model: m', 400]);
        const rate = await viaAction({ status: 'fail', code: 429, string: '…', upstream: { message: 'Requests rate limit exceeded' }, retryAfter: 7 });
        assert.deepEqual([rate.code, rate.retryAfter], ['rateLimit', 7]);
        const quota = await viaAction({ status: 'fail', code: 429, string: '…', error: { code: 'quota', message: 'You have used today\'s 40 requests.' }, retryAfter: 3600 });
        assert.deepEqual([quota.code, quota.message], ['quota', 'You have used today\'s 40 requests.']);
        assert.equal((await viaAction({ status: 'fail', code: 502, string: '…', error: { code: 'notConfigured', message: 'The model service refused the server\'s key.' } })).code, 'notConfigured');
        assert.equal((await viaAction({ status: 'fail', code: 401, string: '…', error: { code: 'login', message: 'Sign in to use the assistant.' } })).code, 'login');
        assert.equal((await viaAction({ status: 'fail', code: 400, string: '…', error: { message: 'The model mistral-large-latest is not available on this server.' } })).code, 'model');
        // FrameTrail's own failures: an extension that failed, or that lacks a PHP extension.
        assert.equal((await viaAction({ status: 'fail', code: 503, string: 'The extension needs curl' })).code, 'service');
    });

    test('asks again without streaming when a stream sends nothing in time, and the adapter remembers', async () => {
        const { ns } = environment(),
              bodies  = [],
              adapter = {
                  post: (body, signal) => {
                      bodies.push(body);
                      if (!body.stream) { return Promise.resolve(completion({ role: 'assistant', content: 'Late but here.' })); }
                      return Promise.resolve({ ok: true, status: 200, headers: { get: () => null }, text: () => Promise.resolve(''), body: { getReader: () => ({
                          read: () => new Promise((resolve, reject) => { signal.addEventListener('abort', () => reject(new Error('aborted'))); }),
                          cancel: () => Promise.resolve()
                      }) } });
                  }
              };
        const result = await ns.models.chat(adapter, { model: 'm' }, { firstChunkTimeout: 20 });
        assert.equal(result.message.content, 'Late but here.');
        assert.deepEqual(bodies.map((body) => body.stream), [true, false]);
        assert.equal(adapter.streaming, false);
        await ns.models.chat(adapter, { model: 'm' });
        assert.deepEqual(bodies.map((body) => body.stream), [true, false, false]);
    });

    test('Stop ends a request with code stopped', async () => {
        const { ns } = environment(),
              controller = new AbortController(),
              pending = ns.models.chat({ post: (body, signal) => new Promise((resolve, reject) => { signal.addEventListener('abort', () => reject(new Error('aborted'))); }) },
                  { model: 'm' }, { signal: controller.signal });
        controller.abort();
        await assert.rejects(pending, (e) => e.code === 'stopped');
    });

    test('models.chatModels lists the chat models with tools, aliases folded in', () => {
        const { ns } = environment();
        assert.deepStrictEqual(json(ns.models.chatModels({ data: [
            { id: 'mistral-medium-2508', name: 'mistral-medium-2508', aliases: ['mistral-medium-latest'], capabilities: { completion_chat: true, function_calling: true }, max_context_length: 131072 },
            { id: 'mistral-medium-latest', aliases: ['mistral-medium-2508'], capabilities: { completion_chat: true, function_calling: true } },
            { id: 'mistral-embed', capabilities: { completion_chat: false, function_calling: false } },
            { id: 'old-model', archived: true, capabilities: { completion_chat: true, function_calling: true } },
            { id: 'codestral-2508', capabilities: { completion_chat: true, function_calling: false } }
        ] })), [{ id: 'mistral-medium-2508', name: 'mistral-medium-2508', aliases: ['mistral-medium-latest'], maxContext: 131072 }]);
    });

});


/* ---------------------------------------------------------------------- */
/*  The conversation                                                      */
/* ---------------------------------------------------------------------- */

// A model's messages: tool calls, or words.
const call  = (id, name, input) => ({ id, type: 'function', function: { name, arguments: JSON.stringify(input) } });
const calls = (...list) => ({ role: 'assistant', content: '', tool_calls: list });
const says  = (text) => ({ role: 'assistant', content: text });

describe('agent.conversation', () => {

    // A model that answers from a script: messages, answers, or functions of the request.
    function scripted(script) {
        const requests = [];
        return {
            requests,
            streaming: false,
            post(body, signal) {
                requests.push(JSON.parse(JSON.stringify(body)));
                const next = script.shift();
                assert.ok(next !== undefined, 'the script has an answer for request ' + requests.length);
                if (typeof next === 'function') { return Promise.resolve(next(body, signal)); }
                return Promise.resolve(next.status ? next : completion(next));
            }
        };
    }

    // A model store over the lecture, counting its transactions; a signal to stop them as the editor's Stop does.
    function lectureStore(ops) {
        const store = ops.modelStore(readJSON(path.join(DATA, 'lecture.json')), { user: { id: '1', name: 'Ada', role: 'user' } }),
              stops = new AbortController(),
              transactions = [];
        return Object.assign({}, store, {
            transactions, stops,
            transaction(description, fn) {
                transactions.push(description);
                return store.transaction(description, (tx) => fn(tx, stops.signal));
            }
        });
    }

    function talk(ns, store, adapter, extra = {}) {
        return ns.agent.conversation(Object.assign({ store, adapter, model: 'test-model', retry: { delays: [0.01], budget: 0.05 } }, extra));
    }

    test('a question: tools that read, an answer, no transaction', async () => {
        const { ns, ops } = environment(),
              store   = lectureStore(ops),
              adapter = scripted([calls(call('aaaaaaaa1', 'list_items', { kind: 'chapters' })), says('There are three chapters.')]),
              chat    = talk(ns, store, adapter),
              tools   = [],
              turn    = await chat.send('How many chapters are there?', { tool: (entry) => tools.push([entry.name, entry.state]) });
        assert.equal(turn.state, 'done');
        assert.equal(turn.changed, false);
        assert.deepEqual(json(turn.changes), []);
        assert.deepEqual(store.transactions, []);
        assert.deepEqual(tools, [['list_items', 'running'], ['list_items', 'done']]);
        const first = adapter.requests[0];
        assert.equal(first.model, 'test-model');
        assert.equal(first.tool_choice, 'auto');
        assert.equal(first.parallel_tool_calls, true);
        assert.equal(first.prompt_cache_key, chat.id);
        assert.equal(first.tools.length, MANIFEST.operations.length);
        assert.equal(first.messages[0].role, 'system');
        assert.equal(first.messages[0].content, ns.agent.systemPrompt(ns.prompts));
        assert.match(first.messages[1].content, /^\[Hypervideo: \{"id":"1","name":"Cell Biology, Lecture 3"[^\n]*\]\n\nHow many chapters are there\?$/);
        const second = adapter.requests[1];
        assert.deepEqual(second.messages.slice(2).map((message) => message.role), ['assistant', 'tool']);
        assert.equal(second.messages[3].tool_call_id, 'aaaaaaaa1');
        assert.equal(JSON.parse(second.messages[3].content).total, 3);
    });

    test('a change: one transaction named after the request, the generator on what it wrote', async () => {
        const { ns, ops } = environment(),
              store   = lectureStore(ops),
              adapter = scripted([
                  calls(call('aaaaaaaa1', 'add_chapter', { start: 450, title: 'Summary' }),
                        call('aaaaaaaa2', 'add_overlay', { start: 20, end: 30, box: { left: 5, top: 75, width: 90, height: 15 },
                            body: { 'frametrail:type': 'text', 'frametrail:name': 'Title', 'frametrail:attributes': { text: '&lt;p&gt;Cell Biology&lt;/p&gt;' } } })),
                  says('Added a chapter at 7:30 and a title.')
              ]),
              turn = await talk(ns, store, adapter).send('Add a summary chapter at 7:30 and a title');
        assert.equal(turn.state, 'done', turn.error && turn.error.stack);
        assert.equal(turn.changed, true);
        assert.deepEqual(store.transactions, ['Conversational UI: Add a summary chapter at 7:30 and a title']);
        assert.deepEqual(json(turn.changes.map((change) => change.name)), ['add_chapter', 'add_overlay']);
        const overlay = json(store.list('overlays')).find((item) => item.body['frametrail:name'] === 'Title');
        assert.deepStrictEqual(overlay.generator, { type: 'Software', name: 'FrameTrail-Conversational-UI', model: 'test-model', provider: 'mistral' });
        assert.ok(json(store.list('chapters')).some((chapter) => chapter.start === 450 && chapter.title === 'Summary'));
        assert.deepEqual(json(turn.lint), []);
    });

    test('the summary of the hypervideo goes along only when it changed', async () => {
        const { ns, ops } = environment(),
              store   = lectureStore(ops),
              adapter = scripted([says('One.'), says('Two.'), calls(call('aaaaaaaa1', 'add_chapter', { start: 500, title: 'End' })), says('Done.'), says('Four.')]),
              chat    = talk(ns, store, adapter);
        await chat.send('one');
        await chat.send('two');
        await chat.send('three');
        await chat.send('four');
        const users = chat.messages.filter((message) => message.role === 'user').map((message) => message.content);
        assert.match(users[0], /^\[Hypervideo: /);
        assert.equal(users[1], 'two');
        assert.equal(users[2], 'three');
        assert.match(users[3], /^\[Hypervideo: .*"chapters":4/);
    });

    test('invalid input goes back to the model, which may try twice more, then has to answer in words', async () => {
        const { ns, ops } = environment(),
              store   = lectureStore(ops),
              bad     = (id) => calls(call(id, 'add_chapter', { start: -1, title: 'Nope' })),
              adapter = scripted([bad('aaaaaaaa1'), bad('aaaaaaaa2'), bad('aaaaaaaa3'), says('I could not add it: the start must not be negative.')]),
              turn    = await talk(ns, store, adapter).send('Add a chapter before the start');
        assert.equal(turn.state, 'done');
        assert.deepEqual(adapter.requests.map((request) => request.tool_choice), ['auto', 'auto', 'auto', 'none']);
        const results = adapter.requests[3].messages.filter((message) => message.role === 'tool').map((message) => JSON.parse(message.content));
        assert.equal(results.length, 3);
        for (const result of results) {
            assert.equal(result.error.code, 'invalid');
            assert.deepEqual(result.error.errors, [{ path: '/start', message: 'must be >= 0' }]);
        }
        assert.equal(turn.changed, false);
    });

    test('arguments that are not JSON and unknown tools are reported to the model', async () => {
        const { ns, ops } = environment(),
              store   = lectureStore(ops),
              adapter = scripted([
                  calls({ id: 'aaaaaaaa1', type: 'function', function: { name: 'add_chapter', arguments: '{"start": 3,' } }, call('aaaaaaaa2', 'delete_everything', {})),
                  says('Sorry.')
              ]),
              turn = await talk(ns, store, adapter).send('x');
        const results = adapter.requests[1].messages.filter((message) => message.role === 'tool').map((message) => JSON.parse(message.content).error);
        assert.match(results[0].message, /not a JSON object/);
        assert.match(results[1].message, /no tool "delete_everything"/);
        assert.equal(turn.state, 'done');
    });

    test('a rate limit is waited out, and the turn goes on', async () => {
        const { ns, ops } = environment(),
              store   = lectureStore(ops),
              waits   = [],
              adapter = scripted([answer(429, { message: 'Requests rate limit exceeded' }, { 'retry-after': '0' }), says('Here.')]),
              turn    = await talk(ns, store, adapter).send('x', { wait: (seconds, error) => waits.push([seconds, error.code]) });
        assert.equal(turn.state, 'done');
        assert.deepEqual(waits, [[0, 'rateLimit']]);
        assert.equal(adapter.requests.length, 2);
    });

    test('a rate limit that outlasts the waiting ends the turn as limited, keeping its changes; resume goes on', async () => {
        const { ns, ops } = environment(),
              store    = lectureStore(ops),
              limited  = () => answer(429, { message: 'Requests rate limit exceeded' }),
              adapter  = scripted([calls(call('aaaaaaaa1', 'add_chapter', { start: 450, title: 'Summary' })), limited(), limited(), limited(), limited(), limited(), limited(), limited(), says('Done.')]),
              chat     = talk(ns, store, adapter),
              turn     = await chat.send('Add a summary chapter');
        assert.equal(turn.state, 'limited');
        assert.equal(turn.error.code, 'rateLimit');
        assert.equal(turn.changed, true);
        assert.ok(json(store.list('chapters')).some((chapter) => chapter.start === 450));
        assert.equal(chat.canResume(), true);
        adapter.requests.length = 0;
        adapter.streaming = false;
        while (adapter.requests.length < 0) { /* nothing */ }
        const script = [says('Done.')];
        adapter.post = (body) => { adapter.requests.push(JSON.parse(JSON.stringify(body))); return Promise.resolve(completion(script.shift())); };
        const resumed = await chat.resume();
        assert.equal(resumed.state, 'done');
        assert.equal(resumed.resumed, true);
        assert.equal(adapter.requests[0].messages[adapter.requests[0].messages.length - 1].role, 'tool');
        assert.equal(chat.canResume(), false);
    });

    test('Stop takes the turn\'s changes back, answers its open calls, and tells the model next time', async () => {
        const { ns, ops } = environment(),
              store   = lectureStore(ops),
              before  = JSON.stringify(store.data()),
              adapter = scripted([
                  calls(call('aaaaaaaa1', 'add_chapter', { start: 450, title: 'Summary' })),
                  (body, signal) => new Promise((resolve, reject) => { signal.addEventListener('abort', () => reject(new Error('aborted'))); chat.stop(); }),
                  says('Fine.')
              ]),
              chat    = talk(ns, store, adapter),
              turn    = await chat.send('Add a summary chapter');
        assert.equal(turn.state, 'stopped');
        assert.equal(turn.changed, false);
        assert.equal(JSON.stringify(store.data()), before);
        await chat.send('And now?');
        const users = adapter.requests[2].messages.filter((message) => message.role === 'user');
        assert.match(users[1].content, /^\[The user stopped your previous turn; its changes were taken back\.\]\n/);
    });

    test('the editor\'s own Stop ends the turn the same way', async () => {
        const { ns, ops } = environment(),
              store   = lectureStore(ops),
              before  = JSON.stringify(store.data()),
              adapter = scripted([
                  calls(call('aaaaaaaa1', 'add_chapter', { start: 450, title: 'Summary' })),
                  (body, signal) => new Promise((resolve, reject) => { signal.addEventListener('abort', () => reject(new Error('aborted'))); store.stops.abort(); })
              ]),
              turn = await talk(ns, store, adapter).send('Add a summary chapter');
        assert.equal(turn.state, 'stopped');
        assert.equal(JSON.stringify(store.data()), before);
    });

    test('lint findings about the turn\'s changes go back to the model once, in the same transaction', async () => {
        const { ns, ops } = environment(),
              store    = lectureStore(ops),
              checks   = [],
              refOf    = (body) => JSON.parse(body.messages.filter((message) => message.role === 'tool').pop().content).ref,
              adapter  = scripted([
                  calls(call('aaaaaaaa1', 'add_overlay', { start: 20, end: 30, box: { left: 60, top: 60, width: 30, height: 20 },
                      body: { 'frametrail:type': 'text', 'frametrail:name': 'Empty', 'frametrail:attributes': { text: '' } } })),
                  says('Added it.'),
                  (body) => {
                      const last = body.messages[body.messages.length - 1];
                      assert.equal(last.role, 'user');
                      assert.match(last.content, /^\[Automatic check of your changes\]\n- error \(empty-required, overlays /);
                      const ref = JSON.parse(body.messages.find((message) => message.role === 'tool').content).ref;
                      return completion(calls(call('aaaaaaaa2', 'update_overlay', { ref, body: { 'frametrail:attributes': { text: '&lt;p&gt;Now with text&lt;/p&gt;' } } })));
                  },
                  says('Fixed: the overlay has its text.')
              ]),
              turn = await talk(ns, store, adapter).send('Add an overlay', { check: (findings) => checks.push(findings.map((finding) => finding.rule)) });
        assert.equal(refOf.length, 1);
        assert.deepEqual(json(checks), [['empty-required']]);
        assert.equal(turn.state, 'done');
        assert.deepEqual(json(turn.lint), []);
        assert.deepEqual(store.transactions.length, 1);
        assert.equal(adapter.requests.length, 4);
    });

});


/* ---------------------------------------------------------------------- */
/*  The panel                                                             */
/* ---------------------------------------------------------------------- */

describe('ui.markdown', () => {

    // The DOM as markup, to compare.
    function markup(node) {
        if (node.nodeType === 3) { return node.data; }
        const inner = node.childNodes.map(markup).join('');
        if (node.nodeType === 11) { return inner; }
        const tag = node.tagName.toLowerCase(), href = node.href ? ' href="' + node.href + '"' : '';
        return (tag === 'br') ? '<br>' : '<' + tag + href + '>' + inner + '</' + tag + '>';
    }

    test('paragraphs, lists, emphasis, code and links, and nothing parsed as HTML', () => {
        const { context } = load(),
              out = (text) => markup(context.FrameTrailConversationalUI.ui.markdown(text));
        assert.equal(out('One\nline two\n\nNext **bold** and *it* and `x<y>`'), '<p>One<br>line two</p><p>Next <strong>bold</strong> and <em>it</em> and <code>x&lt;y&gt;</code></p>'.replace(/&lt;/g, '<').replace(/&gt;/g, '>'));
        assert.equal(out('Done:\n- a chapter at 0:30\n- a **title**\n1. first'), '<p>Done:</p><ul><li>a chapter at 0:30</li><li>a <strong>title</strong></li><li>first</li></ul>');
        assert.equal(out('1. one\n2. two'), '<ol><li>one</li><li>two</li></ol>');
        assert.equal(out('```\n<b>code</b>\n```'), '<pre><code><b>code</b></code></pre>');
        assert.equal(out('[the docs](https://frametrail.org/) and [bad](javascript:alert(1))'), '<p><a href="https://frametrail.org/">the docs</a> and [bad](javascript:alert(1))</p>');
        assert.equal(out('<img src=x onerror=alert(1)>'), '<p><img src=x onerror=alert(1)></p>');
        assert.equal(out('## Heading'), '<p><strong>Heading</strong></p>');
    });

});

describe('the panel', () => {

    const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

    // The extension in a stand-in FrameTrail with the lecture open, its panel built.
    async function opened(options = {}) {
        const fetches = [],
              loaded  = load({ frameTrail: true, fetch: (url, init) => { fetches.push({ url, body: init && init.body ? JSON.parse(init.body) : null }); return options.fetch(url, init); } }),
              { context, registered } = loaded,
              ns    = context.FrameTrailConversationalUI,
              store = ns.ops.modelStore(readJSON(path.join(DATA, 'lecture.json')), { user: { id: '1', name: 'Ada', role: 'user' } });
        if ('user' in options) { store.getUser = () => options.user; }
        const ft    = instance('en', { state: { storageMode: options.storageMode || 'download' }, status: options.status, edit: store }),
              ext   = registered[NAME](ft),
              container = context.document.createElement('div');
        if (options.key) { loaded.storage['frametrail-conversational-ui-key'] = options.key; }
        Object.assign(loaded.storage, options.stored || {});
        ext.init({});
        ext.slots.sidePanel.create(container, {});
        ext.onHypervideoChange('1');
        await tick();
        const root = container.children[0];
        return {
            context, ns, ft, ext, store, root, fetches, storage: loaded.storage,
            notice:   () => (root.querySelector('.conversationalUiNotice').classList.contains('active') ? root.querySelector('.conversationalUiNotice').textContent : ''),
            settings: () => root.querySelector('.conversationalUiConnection').textContent,
            labels:   ns.labels.en
        };
    }

    test('without a server it talks to Mistral directly, and first asks for a key', async () => {
        const panel = await opened({ storageMode: 'download' });
        assert.match(panel.notice(), new RegExp('^' + panel.labels.ConversationalUiNeedsKey.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
        assert.equal(panel.settings(), panel.labels.ConversationalUiSettingsDirect);
        assert.equal(panel.root.querySelector('.conversationalUiSend').disabled, true);
        assert.equal(panel.root.querySelector('textarea').disabled, false);
    });

    test('on a server: through the relay when it is there, directly when own keys are allowed, otherwise not at all', async () => {
        const relay = await opened({ storageMode: 'server', status: { status: 'success', code: 0, response: { version: 'dev', capabilities: { relay: true, ownKey: false, models: ['mistral-small-latest'], defaultModel: 'mistral-small-latest' } } } });
        assert.equal(relay.notice(), '');
        assert.equal(relay.settings(), relay.labels.ConversationalUiSettingsRelay);
        assert.equal(relay.root.querySelector('.conversationalUiStatus').textContent, 'mistral-small-latest · ' + relay.labels.ConversationalUiViaServer);
        assert.deepEqual(relay.ft.StorageManager.posted, [{ a: 'conversationalUiStatus' }]);
        const own = await opened({ storageMode: 'server', status: { status: 'success', code: 0, response: { version: 'dev', capabilities: { relay: false, ownKey: true } } } });
        assert.equal(own.settings(), own.labels.ConversationalUiSettingsDirect);
        const none = await opened({ storageMode: 'server', status: { status: 'success', code: 0, response: { version: 'dev', capabilities: { relay: false, ownKey: false } } } });
        assert.equal(none.notice(), none.labels.ConversationalUiNotEnabled);
        assert.equal(none.root.querySelector('textarea').disabled, true);
        const old = await opened({ storageMode: 'server', status: { status: 'success', code: 0, response: { version: 'dev' } } });
        assert.equal(old.notice(), old.labels.ConversationalUiNotEnabled);
    });

    const capabilities = (more) => ({ status: 'success', code: 0, response: { version: 'dev', capabilities: Object.assign({ relay: true, ownKey: false, models: ['ministral-14b-latest', 'ministral-8b-latest'], defaultModel: 'ministral-14b-latest', requestsPerDay: 40 }, more) } });
    const select = (panel, suffix) => panel.root.querySelectorAll('select').find((el) => el.id.endsWith(suffix));
    const shown  = (el) => el.style.display !== 'none';

    test('relay and own keys on one server: the user chooses, the relay first, and the choice is remembered', async () => {
        const panel  = await opened({ storageMode: 'server', status: capabilities({ ownKey: true }) }),
              choice = select(panel, 'Connection');
        assert.ok(shown(choice.parentNode.parentNode), 'the choice is shown');
        assert.equal(choice.value, 'relay');
        assert.equal(panel.settings(), panel.labels.ConversationalUiSettingsRelay);
        assert.equal(panel.root.querySelector('.conversationalUiLimit').textContent, panel.labels.ConversationalUiSettingsRelayLimit.replace('{count}', '40'));
        assert.equal(panel.notice(), '');
        choice.value = 'direct';
        choice.dispatch('change');
        assert.equal(panel.settings(), panel.labels.ConversationalUiSettingsDirect);
        assert.ok(!shown(panel.root.querySelector('.conversationalUiLimit')), 'no limit with one\'s own key');
        assert.match(panel.notice(), new RegExp('^' + panel.labels.ConversationalUiNeedsKey.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
        assert.equal(panel.storage['frametrail-conversational-ui-connection'], 'direct');
        assert.equal(panel.root.querySelector('.conversationalUiStatus').textContent, panel.ns.ui.DEFAULT_MODEL);
        choice.value = 'relay';
        choice.dispatch('change');
        assert.equal(panel.notice(), '');
        assert.equal(panel.storage['frametrail-conversational-ui-connection'], undefined);
        const again = await opened({ storageMode: 'server', status: capabilities({ ownKey: true }), stored: { 'frametrail-conversational-ui-connection': 'direct' } });
        assert.equal(again.settings(), again.labels.ConversationalUiSettingsDirect);
        const relayOnly = await opened({ storageMode: 'server', status: capabilities({}), stored: { 'frametrail-conversational-ui-connection': 'direct' } });
        assert.equal(relayOnly.settings(), relayOnly.labels.ConversationalUiSettingsRelay, 'no choice without own keys');
        assert.ok(!shown(select(relayOnly, 'Connection').parentNode.parentNode));
    });

    test('the relay needs someone signed in: a guest is told so, and offered their own key where the server allows it', async () => {
        const guest = await opened({ storageMode: 'server', status: capabilities({}), user: { id: 'guest_ada-1', name: 'Ada', role: 'admin', guest: true } });
        assert.equal(guest.notice(), guest.labels.ConversationalUiErrorLogin);
        assert.equal(guest.root.querySelector('textarea').disabled, true);
        const nobody = await opened({ storageMode: 'server', status: capabilities({ ownKey: true }), user: null });
        assert.equal(nobody.notice(), nobody.labels.ConversationalUiErrorLogin + ' ' + nobody.labels.ConversationalUiSettings);
        nobody.root.querySelector('.conversationalUiOpenSettings').click();
        const choice = select(nobody, 'Connection');
        choice.value = 'direct';
        choice.dispatch('change');
        assert.match(nobody.notice(), /^To start, enter your Mistral API key/);
    });

    test('administrators see what the server has set up; others do not', async () => {
        const admin = { id: '1', name: 'Ada', role: 'admin', guest: false };
        const info  = (panel) => { const el = panel.root.querySelector('.conversationalUiServerInfo'); return shown(el) ? el.children.map((p) => p.textContent) : null; };
        const on = await opened({ storageMode: 'server', status: capabilities({}), user: admin });
        assert.deepEqual(info(on), [
            on.labels.ConversationalUiServerTitle,
            'The relay is on. Models: ministral-14b-latest, ministral-8b-latest; default: ministral-14b-latest; 40 requests a day per person.',
            on.labels.ConversationalUiServerOwnKeyOff
        ]);
        const unlimited = await opened({ storageMode: 'server', status: capabilities({ requestsPerDay: null, ownKey: true }), user: admin });
        assert.match(info(unlimited)[1], /; no daily limit\.$/);
        assert.equal(info(unlimited)[2], unlimited.labels.ConversationalUiServerOwnKeyOn);
        const off = await opened({ storageMode: 'server', status: capabilities({ relay: false, models: undefined, defaultModel: undefined, requestsPerDay: undefined }), user: admin });
        assert.equal(info(off)[1], off.labels.ConversationalUiServerRelayOff);
        const curl = await opened({ storageMode: 'server', status: capabilities({ relay: false, ownKey: true, problem: 'curl' }), user: admin });
        assert.equal(info(curl)[1], curl.labels.ConversationalUiServerCurl);
        const baseUrl = await opened({ storageMode: 'server', status: capabilities({ relay: false, problem: 'baseUrl' }), user: admin });
        assert.equal(info(baseUrl)[1], baseUrl.labels.ConversationalUiServerBaseUrl);
        assert.equal(info(await opened({ storageMode: 'server', status: capabilities({}) })), null, 'not for a user');
        assert.equal(info(await opened({ storageMode: 'download', user: admin })), null, 'not without a server');
    });

    test('a turn: the request, what the tools did, the answer, and Undo this turn while it is the latest step', async () => {
        const script = [
            calls(call('aaaaaaaa1', 'add_chapter', { start: 450, title: 'Summary' })),
            says('Added **Summary** at 7:30.')
        ];
        const panel = await opened({ key: 'test-key', fetch: (url, init) => {
            if (/\/models$/.test(url)) { return Promise.resolve(answer(200, { data: [] })); }
            return Promise.resolve(completion(script.shift()));
        } });
        await tick();
        assert.equal(panel.notice(), '');
        const input = panel.root.querySelector('textarea');
        input.value = 'Add a summary chapter at 7:30';
        input.dispatch('keydown', { key: 'Enter', shiftKey: false, isComposing: false });
        assert.ok(panel.root.classList.contains('busy'), 'busy while the turn runs');
        while (panel.root.classList.contains('busy')) { await tick(); }
        const chats = panel.fetches.filter((entry) => /chat\/completions$/.test(entry.url));
        assert.equal(chats.length, 2);
        assert.equal(chats[0].body.model, panel.ns.ui.DEFAULT_MODEL);
        const turn = panel.root.querySelector('.conversationalUiTurn');
        assert.equal(turn.querySelector('.conversationalUiUser').textContent, 'Add a summary chapter at 7:30');
        assert.equal(turn.querySelector('summary').textContent, 'Added a chapter: Summary, 7:30');
        assert.equal(turn.querySelector('.conversationalUiAssistant').textContent, 'Added Summary at 7:30.');
        assert.equal(input.value, '');
        const undo = turn.querySelector('.conversationalUiUndo');
        assert.ok(undo, 'Undo this turn');
        assert.equal(panel.ft.UndoManager.getUndoDescription(), 'Assistant: Add a summary chapter at 7:30');
        undo.click();
        assert.deepEqual(panel.ft.UndoManager.redoStack, ['Assistant: Add a summary chapter at 7:30']);
        assert.equal(undo.textContent, panel.labels.ConversationalUiRedoTurn);
        panel.ft.UndoManager.register('Move overlay');
        undo.click();
        assert.equal(turn.querySelector('.conversationalUiFooter').querySelector('p.conversationalUiState').textContent, panel.labels.ConversationalUiUndoNotOnTop);
        assert.equal(panel.storage['frametrail-conversational-ui-key'], 'test-key');
    });

    test('names every tool and every error it can meet', () => {
        const ns = load().context.FrameTrailConversationalUI;
        assert.deepEqual(Object.keys(ns.ui.TOOL_LABELS).sort(), MANIFEST.operations.map((op) => op.name).sort());
        for (const key of [...Object.values(ns.ui.TOOL_LABELS), ...Object.values(ns.ui.ERROR_LABELS), ...Object.values(ns.ui.UNAVAILABLE_LABELS)]) {
            assert.ok(ns.labels.en[key], key);
        }
    });

});
