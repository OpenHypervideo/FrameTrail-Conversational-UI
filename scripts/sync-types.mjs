/*
 * Writes shared/prompts/types.md, the system prompt's part about the types of
 * content: the header below (the body, describe_type, what every overlay
 * takes), then the table of FrameTrail's docs/TYPES.md "At a Glance", which
 * FrameTrail generates from its schemas. Run it after moving to a newer
 * FrameTrail:
 *
 *     node scripts/sync-types.mjs                    # FRAMETRAIL_DIR, or ../frametrail
 *     node scripts/sync-types.mjs --frametrail=<dir>
 *
 * tests/run-js.mjs fails while the file differs from what this writes. What a
 * type is for belongs in FrameTrail's schema descriptions (the first sentence
 * of an attribute schema's description is the type's line in the table),
 * never in the generated file; the header is the add-on's own text.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export const OUTPUT = path.join(ROOT, 'shared', 'prompts', 'types.md');

const HEADER = `## Types of content

The body of an overlay or an annotation says what it shows: its \`frametrail:type\` is the type, its \`frametrail:attributes\` hold the type's settings.

    { "frametrail:type": "text", "frametrail:name": "Welcome", "frametrail:attributes": { "text": "&lt;p&gt;Hello&lt;/p&gt;" } }

Types that show a file or a page take its file name or URL in \`source\` or in \`value\`. \`describe_type\` says which, and lists every attribute of a type with its values and defaults: call it before you add an item of a type, or change attributes, you do not know for sure. Every overlay also takes \`opacity\`, \`zIndex\` and \`animation\` in its attributes, e.g. \`{ "in": { "preset": "fadeIn" }, "emphasis": { "preset": "pulse" }, "out": { "preset": "fadeOut" } }\`; the entrance plays just before \`start\` and the exit just after \`end\`, so the overlay is fully there from start to end.

The types, and whether overlays, annotations and the resource library can use them:
`;

/**
 * The contents of shared/prompts/types.md for the FrameTrail working copy in dir.
 * @param {String} dir
 * @return {String}
 */
export function typesPrompt(dir) {

    const file  = path.join(dir, 'docs', 'TYPES.md'),
          text  = fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : null,
          start = text ? text.indexOf('\n## At a Glance\n') : -1,
          end   = (start >= 0) ? text.indexOf('\n## ', start + 1) : -1;

    if (start < 0 || end < 0) {
        throw new Error('No "At a Glance" section in ' + file + ' (FrameTrail generates docs/TYPES.md since the release after 1.4.1)');
    }

    const table = text.slice(start, end).split('\n').filter((line) => line.startsWith('|'));

    return HEADER + '\n' + table.join('\n') + '\n';

}


if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const option     = process.argv.find((arg) => arg.startsWith('--frametrail=')),
          frametrail = path.resolve(ROOT, option ? option.slice('--frametrail='.length) : (process.env.FRAMETRAIL_DIR || path.join('..', 'frametrail')));
    fs.writeFileSync(OUTPUT, typesPrompt(frametrail));
    console.log('Wrote ' + path.relative(ROOT, OUTPUT) + ' from ' + path.join(frametrail, 'docs', 'TYPES.md'));
}
