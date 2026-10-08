## Types of content

The body of an overlay or an annotation says what it shows:

    { "frametrail:type": "text", "frametrail:name": "Welcome", "frametrail:attributes": { "text": "&lt;p&gt;Hello&lt;/p&gt;" } }

Types that show a file or a page take its file name or URL in `source` or in `value`; `describe_type` says which, and lists every attribute of a type with its values and defaults. The types used most:

- **text** (overlays, annotations): rich text. `text` is escaped HTML, `title` an optional plain-text title above it, `box` an optional card: `{ "background": "#ffffffe6", "padding": 12, "radius": 8, "shadow": true }`. Without a card the text lies directly on the video, so give it one where the video is busy.
- **html** (overlays, annotations): custom HTML in `text`, escaped like the text type's.
- **quiz** (overlays, annotations): `questionType` (`multipleChoice`, `multiSelect`, `freeText`, `rating`), `question` (plain text), `answers` (`[{ "text": "…", "correct": true }]`), `onCorrectAnswer` and `onWrongAnswer` (`{ "showText": "…", "resumePlayback": true }`). A quiz overlay should pause the video when it appears: events `{ "onStart": "FrameTrail.module('HypervideoController').pause();" }`.
- **hotspot** (overlays only): a clickable shape or a drawn mark. `shape` (`circle`, `rectangle`, `rounded`, `arrow`, `underline`, `freeform`), `color`, `text` (a label), `action` (`openUrl`, `jumpToTime`, `jumpToHypervideo`) with `actionTarget` (the URL, the seconds, or the hypervideo's id). An arrow has `direction` and `curve`.
- **image**, **video**, **audio**, **pdf**: a file name or URL in `source`. Video and audio overlays play along while shown with `autoPlay: true`.
- **youtube**, **vimeo** and other embeds: the video's URL in `source`.
- **webpage**: the page's URL in `value`; **wikipedia**: the article's URL in `value`.
- **location**: a map, `lat`, `lon` and `zoom`.
- **cursor** (overlays only): a mouse pointer that moves with the overlay's keyframes; `clicks` are click times in seconds from the overlay's start.
- **counter** (overlays only): a number that counts from `from` to `to`, with `prefix`, `suffix`, `decimals`, `duration` (milliseconds).
- **chart** (overlays only): `chartType` (`bars`, `line`, `donut`, `ring`) and `data`, one `Label: value` per line.

Every overlay also takes `opacity`, `zIndex` and `animation` in its attributes: `{ "in": { "preset": "fadeIn", "duration": 400 }, "emphasis": { "preset": "pulse" }, "out": { "preset": "fadeOut" } }`. The entrance plays just before `start` and the exit just after `end`, so the overlay is fully there from start to end. Entrances: fadeIn, slideFromLeft (Right, Top, Bottom), zoomIn, popIn, blurIn, wipeFromLeft, irisIn, dropIn, rotateIn, draw (hotspots). Exits mirror them: fadeOut, slideToLeft, zoomOut, popOut, … Emphasis loops: pulse, breathe, wobble, shake, bounce, float, heartbeat, flash, glow.

Boxes, for orientation: a title across the top `{ "left": 5, "top": 5, "width": 90, "height": 15 }`, a lower third `{ "left": 5, "top": 72, "width": 60, "height": 20 }`, a hotspot on something `{ "left": 40, "top": 30, "width": 12, "height": 18 }`. Overlays should not cover each other unless that is meant.
