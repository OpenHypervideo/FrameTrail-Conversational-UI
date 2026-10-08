/*
 * The tool-calling evaluation: how well Mistral's models do the chat panel's
 * work. Each task of shared/eval/tasks.json is sent as the first message of a
 * new conversation (client/agent/) about a fresh model store over the task
 * file's data set, through the direct adapter, and its checks are run on what
 * the store holds afterwards. The default model (ui/panel.js, DEFAULT_MODEL)
 * is chosen by this; run it again when Mistral changes its models.
 *
 *     bash scripts/build.sh
 *     node scripts/eval-models.mjs --key-file=<file> --models=ministral-14b-latest,mistral-medium-latest
 *
 * --key-file=<file> or MISTRAL_API_KEY: a Mistral API key (never printed).
 * --models=a,b       the models (default: every chat model with tools that
 *                    /v1/models lists, aliases once, that answers at all: on
 *                    the free plan some models allow no requests).
 * --task=<text>      only tasks whose name contains it.
 * --repeat=<n>       run each task n times (default 1).
 * --json=<file>      write every run's details there.
 * --verbose          print every tool call.
 *
 * Needs Node 20+, the add-on's build (build/client/) and a FrameTrail working
 * copy (FRAMETRAIL_DIR, default ../frametrail), like tests/run-js.mjs. Not
 * run in CI: it needs a key, and models answer differently from run to run.
 */

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const ROOT       = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const FRAMETRAIL = path.resolve(ROOT, process.env.FRAMETRAIL_DIR || path.join('..', 'frametrail'));
const BUNDLE     = path.join(ROOT, 'build', 'client', 'frametrail-conversational-ui.js');

const args = Object.fromEntries(process.argv.slice(2).map((arg) => {
    const m = /^--([a-z-]+)(?:=(.*))?$/.exec(arg);
    if (!m) { throw new Error('Unknown argument ' + arg); }
    return [m[1], m[2] === undefined ? true : m[2]];
}));

const key = (args['key-file'] ? fs.readFileSync(args['key-file'], 'utf8') : (process.env.MISTRAL_API_KEY || '')).trim();
if (!key) { console.error('No key: --key-file=<file> or MISTRAL_API_KEY'); process.exit(2); }
if (!fs.existsSync(BUNDLE)) { console.error('No build: run bash scripts/build.sh first'); process.exit(2); }


/* ---------------------------------------------------------------------- */
/*  The client, as the browser runs it                                    */
/* ---------------------------------------------------------------------- */

const context = {
    console: { log() {}, warn() {}, error: (...a) => console.error(...a) },
    fetch, TextDecoder, TextEncoder, AbortController, URLSearchParams,
    setTimeout, clearTimeout, setInterval, clearInterval
};
context.window = context;
vm.createContext(context);

for (const file of ['serialization/FrameTrailKeyframes.js', 'serialization/FrameTrailSerializer.js', 'schema/FrameTrailSchema.js', 'schema/FrameTrailSchemas.js']) {
    vm.runInContext(fs.readFileSync(path.join(FRAMETRAIL, 'src/_shared/frametrail-core', file), 'utf8'), context, { filename: file });
}
vm.runInContext(fs.readFileSync(BUNDLE, 'utf8'), context, { filename: 'frametrail-conversational-ui.js' });

const ns   = context.FrameTrailConversationalUI,
      ops  = ns.ops,
      json = (value) => (value === undefined ? undefined : JSON.parse(JSON.stringify(value)));

const TASKS = JSON.parse(fs.readFileSync(path.join(ROOT, 'shared', 'eval', 'tasks.json'), 'utf8')),
      DATA  = JSON.parse(fs.readFileSync(path.join(ROOT, 'shared', 'fixtures', 'data', TASKS.data + '.json'), 'utf8'));


/* ---------------------------------------------------------------------- */
/*  Checks                                                                */
/* ---------------------------------------------------------------------- */

const pattern = (text) => new RegExp(text, 'i');
const within  = (value, range) => typeof value === 'number' && value >= range[0] && value <= range[1];

function pathValue(object, dotted) {
    return dotted.split('.').reduce((value, key) => (value === null || value === undefined) ? undefined : value[key], object);
}

// The short form and the stored item together: what a check looks at.
function described(store, kind, item) {
    const body       = item.body || {},
          attributes = body['frametrail:attributes'] || {},
          span       = ops.util.timeSpan(item.target && item.target.selector && item.target.selector.value) || {},
          box        = ops.util.box(item.target && item.target.selector && item.target.selector.value),
          text       = ops.util.plainText(String(attributes.text || attributes.question || attributes.title || ''));
    return { type: body['frametrail:type'], name: body['frametrail:name'] || '', start: span.start, end: span.end, box, text, attributes };
}

function itemMatches(entry, want) {
    if (want.type && entry.type !== want.type) { return 'type ' + entry.type; }
    if (want.name && !pattern(want.name).test(entry.name)) { return 'name ' + JSON.stringify(entry.name); }
    if (want.start && !within(entry.start, want.start)) { return 'start ' + entry.start; }
    if (want.end && !within(entry.end, want.end)) { return 'end ' + entry.end; }
    if (want.text && !pattern(want.text).test(entry.text)) { return 'text ' + JSON.stringify(entry.text); }
    for (const [dotted, expected] of Object.entries(want.attributes || {})) {
        const value = pathValue(entry.attributes, dotted);
        const ok = (expected && typeof expected === 'object') ? pattern(expected.matches).test(String(value)) : String(value) === String(expected);
        if (!ok) { return dotted + ' ' + JSON.stringify(value); }
    }
    for (const [side, range] of Object.entries(want.box || {})) {
        const value = entry.box ? entry.box[{ left: 'left', top: 'top', width: 'width', height: 'height' }[side]] : undefined;
        if (!within(value, range)) { return 'box.' + side + ' ' + value; }
    }
    return null;
}

function runCheck(check, { store, before, turn, lint }) {

    if (check.noChanges) {
        return JSON.stringify(json(store.data())) === before ? null : 'changed: ' + turn.changes.map((c) => c.name).join(', ');
    }
    if (check.answer) {
        const said = turn.texts.join('\n');
        return pattern(check.answer).test(said) ? null : 'the answer lacks /' + check.answer + '/';
    }
    if (check.chapter || check.noChapter) {
        const want = check.chapter || check.noChapter,
              hits = json(store.list('chapters')).filter((c) => within(c.start, [want.from, want.to]) && (!want.title || pattern(want.title).test(c.title)));
        if (check.chapter) { return hits.length ? null : 'no chapter in [' + want.from + ', ' + want.to + ']' + (want.title ? ' titled /' + want.title + '/' : '') + '; chapters: ' + json(store.list('chapters')).map((c) => c.start + ' ' + c.title).join(' | '); }
        return hits.length ? 'a chapter in [' + want.from + ', ' + want.to + ']: ' + hits.map((c) => c.start + ' ' + c.title).join(' | ') : null;
    }
    if (check.chapters) {
        const n = store.list('chapters').length;
        return (n >= check.chapters.min && n <= check.chapters.max) ? null : n + ' chapters';
    }
    if (check.item || check.noItem) {
        const want    = check.item || check.noItem,
              entries = json(store.list(want.kind)).map((item) => described(store, want.kind, item)),
              misses  = entries.map((entry) => itemMatches(entry, want)),
              hit     = misses.some((miss) => miss === null);
        if (check.item) { return hit ? null : 'no such item; closest: ' + misses.filter(Boolean).slice(-3).join(' / '); }
        return hit ? 'the item is still there' : null;
    }
    if (check.layout) {
        const views = json(store.list('contentViews', { area: check.layout.area }));
        return views.some((view) => view.type === check.layout.includes) ? null : check.layout.area + ': ' + views.map((v) => v.type).join(', ');
    }
    if (check.lintErrors !== undefined) {
        return lint.errors === check.lintErrors ? null : lint.errors + ' lint errors: ' + lint.findings.filter((f) => f.severity === 'error').map((f) => f.message).join(' | ');
    }
    throw new Error('Unknown check ' + JSON.stringify(check));

}


/* ---------------------------------------------------------------------- */
/*  Running                                                               */
/* ---------------------------------------------------------------------- */

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function runTask(model, task) {

    const store   = ops.modelStore(json(DATA), { user: TASKS.user, duration: TASKS.duration }),
          before  = JSON.stringify(json(store.data())),
          adapter = ns.models.mistral({ key }),
          counted = { requests: 0, limited: 0 },
          post    = adapter.post;

    adapter.post = (body, signal) => { counted.requests++; return post(body, signal); };

    const talk  = ns.agent.conversation({ store, adapter, model, retry: { delays: [2, 4, 8, 16, 30], budget: 180 } }),
          tools = [], texts = {},
          t0    = Date.now();

    const turn = await talk.send(task.request, {
        text: (text, round) => { texts[round] = text; },
        tool: (entry) => { if (entry.state !== 'running') { tools.push({ name: entry.name, state: entry.state, input: entry.input, error: entry.error && entry.error.message }); } },
        wait: () => { counted.limited++; }
    });

    const seconds = (Date.now() - t0) / 1000,
          lint    = json(ns.lint.run(store)),
          result  = { texts: Object.keys(texts).sort((a, b) => a - b).map((round) => texts[round]), changes: turn.changes },
          failures = turn.state === 'done'
              ? task.checks.map((check) => runCheck(check, { store, before, turn: result, lint })).filter(Boolean)
              : ['the turn ended ' + turn.state + ': ' + (turn.error && turn.error.message)];

    return {
        model, task: task.name, passed: failures.length === 0, failures, state: turn.state, seconds,
        requests: counted.requests, rateLimited: counted.limited, invalid: tools.filter((t) => t.state === 'failed').length,
        tokens: turn.usage, tools, answer: result.texts.join('\n')
    };

}

async function answers(model) {
    try {
        await ns.models.chat({ post: ns.models.mistral({ key }).post, streaming: false }, { model, max_tokens: 3, messages: [{ role: 'user', content: 'Say OK' }] });
        return true;
    } catch (e) {
        return e.code !== 'rateLimit' && e.code !== 'model';
    }
}

let models = args.models ? String(args.models).split(',').map((m) => m.trim()).filter(Boolean) : null;

if (!models) {
    const listed = await ns.models.mistral({ key }).listModels();
    models = [];
    for (const entry of listed) {
        if (await answers(entry.id)) { models.push(entry.id); } else { console.log('skipped (no requests allowed): ' + entry.id); }
        await sleep(1200);
    }
}

const tasks  = TASKS.tasks.filter((task) => !args.task || task.name.includes(args.task)),
      repeat = Number(args.repeat || 1),
      runs   = [];

for (const model of models) {
    console.log('\n' + model);
    for (const task of tasks) {
        for (let i = 0; i < repeat; i++) {
            const run = await runTask(model, task);
            runs.push(run);
            console.log((run.passed ? '  ok   ' : '  FAIL ') + task.name + '  (' + run.seconds.toFixed(1) + ' s, ' + run.requests + ' requests, ' + run.invalid + ' invalid'
                + (run.rateLimited ? ', ' + run.rateLimited + ' rate limits' : '') + ')');
            for (const failure of run.failures) { console.log('         ' + failure.slice(0, 300)); }
            if (args.verbose) {
                for (const tool of run.tools) { console.log('         ' + tool.state + ' ' + tool.name + ' ' + JSON.stringify(tool.input).slice(0, 200) + (tool.error ? ' → ' + tool.error.slice(0, 200) : '')); }
                console.log('         » ' + run.answer.replace(/\s+/g, ' ').slice(0, 300));
            }
        }
    }
}

console.log('\nmodel                         passed   seconds/task  requests/task  invalid  tokens in/out per task');
for (const model of models) {
    const mine   = runs.filter((run) => run.model === model),
          sum    = (fn) => mine.reduce((total, run) => total + fn(run), 0),
          per    = (fn) => (sum(fn) / mine.length).toFixed(1);
    console.log(model.padEnd(30) + (sum((r) => (r.passed ? 1 : 0)) + '/' + mine.length).padEnd(9) + per((r) => r.seconds).padStart(12) + per((r) => r.requests).padStart(15)
        + String(sum((r) => r.invalid)).padStart(9) + ('  ' + Math.round(sum((r) => r.tokens.prompt_tokens) / mine.length) + ' / ' + Math.round(sum((r) => r.tokens.completion_tokens) / mine.length)));
}

if (args.json) { fs.writeFileSync(args.json, JSON.stringify(runs, null, 2)); }
