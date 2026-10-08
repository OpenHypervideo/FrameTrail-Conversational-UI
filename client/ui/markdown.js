/*
 * FrameTrail-Conversational-UI — the model's text as DOM
 * (ConversationalUI.ui.markdown): the little Markdown the system prompt asks
 * for — paragraphs, line breaks, lists, code blocks, **bold**, *italic*,
 * `code` and links — built with createElement and text nodes, so nothing the
 * model writes is ever parsed as HTML.
 *
 *     element.append(ui.markdown('**Done.** Added:\n- a chapter at 0:30'));
 */

(function(ConversationalUI) {

    var ui = ConversationalUI.ui;

    var INLINE = /(`[^`\n]+`)|(\*\*[^*\n]+\*\*|__[^_\n]+__)|(\*[^*\s][^*\n]*\*|_[^_\s][^_\n]*_)|(\[[^\]\n]+\]\((https?:\/\/[^)\s]+)\))/;

    function element(tag, className) {
        var el = document.createElement(tag);
        if (className) { el.className = className; }
        return el;
    }

    // Inline markup into a parent: code, bold, italic, links; the rest as text.
    function inline(parent, text) {

        var rest = text, match;

        while (rest !== '' && (match = INLINE.exec(rest))) {

            if (match.index > 0) { parent.append(document.createTextNode(rest.slice(0, match.index))); }

            var token = match[0], el;

            if (match[1]) {
                el = element('code');
                el.textContent = token.slice(1, -1);
            } else if (match[2]) {
                el = element('strong');
                inline(el, token.slice(2, -2));
            } else if (match[3]) {
                el = element('em');
                inline(el, token.slice(1, -1));
            } else {
                el = element('a');
                el.href = match[5];
                el.target = '_blank';
                el.rel = 'noopener noreferrer';
                el.textContent = token.slice(1, token.indexOf(']('));
            }

            parent.append(el);
            rest = rest.slice(match.index + token.length);

        }

        if (rest !== '') { parent.append(document.createTextNode(rest)); }

    }

    // Lines of a paragraph, joined by line breaks.
    function lines(parent, list) {
        list.forEach(function(line, i) {
            if (i > 0) { parent.append(element('br')); }
            inline(parent, line);
        });
    }

    /**
     * I return a document fragment for a Markdown text.
     *
     * @param {String} text
     * @return {DocumentFragment}
     */
    function markdown(text) {

        var fragment = document.createDocumentFragment(),
            source   = String(text || '').replace(/\r\n?/g, '\n').split('\n'),
            i        = 0;

        while (i < source.length) {

            var line = source[i];

            if (/^\s*$/.test(line)) { i++; continue; }

            // A code block, fenced with ```.
            if (/^\s*```/.test(line)) {
                var code = [];
                i++;
                while (i < source.length && !/^\s*```/.test(source[i])) { code.push(source[i]); i++; }
                i++;
                var pre = element('pre'), codeElement = element('code');
                codeElement.textContent = code.join('\n');
                pre.append(codeElement);
                fragment.append(pre);
                continue;
            }

            // A list: "- ", "* " or "1. " at the start of each item; other lines continue the item.
            var bullet = /^\s*([-*•]|\d+[.)])\s+(.*)$/.exec(line);
            if (bullet) {
                var ordered = /\d/.test(bullet[1]),
                    list    = element(ordered ? 'ol' : 'ul'),
                    item    = null,
                    content = null;
                while (i < source.length && !/^\s*$/.test(source[i]) && !/^\s*```/.test(source[i])) {
                    var next = /^\s*([-*•]|\d+[.)])\s+(.*)$/.exec(source[i]);
                    if (next) {
                        if (item) { lines(item, content); }
                        item = element('li');
                        content = [next[2]];
                        list.append(item);
                    } else {
                        content.push(source[i].trim());
                    }
                    i++;
                }
                if (item) { lines(item, content); }
                fragment.append(list);
                continue;
            }

            // A paragraph: up to an empty line, a list or a code block. Headings are read as bold paragraphs.
            var paragraph = [];
            while (i < source.length && !/^\s*$/.test(source[i]) && !/^\s*```/.test(source[i])
                   && !(paragraph.length && /^\s*([-*•]|\d+[.)])\s+/.test(source[i]))) {
                paragraph.push(source[i].replace(/^#{1,6}\s+(.*)$/, '**$1**'));
                i++;
            }
            var p = element('p');
            lines(p, paragraph);
            fragment.append(p);

        }

        return fragment;

    }


    ui.markdown = markdown;

})(window.FrameTrailConversationalUI);
