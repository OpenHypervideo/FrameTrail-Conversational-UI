## Types of content

The body of an overlay or an annotation says what it shows: its `frametrail:type` is the type, its `frametrail:attributes` hold the type's settings.

    { "frametrail:type": "text", "frametrail:name": "Welcome", "frametrail:attributes": { "text": "&lt;p&gt;Hello&lt;/p&gt;" } }

Types that show a file or a page take its file name or URL in `source` or in `value`. `describe_type` says which, and lists every attribute of a type with its values and defaults: call it before you add an item of a type, or change attributes, you do not know for sure. Every overlay also takes `opacity`, `zIndex` and `animation` in its attributes, e.g. `{ "in": { "preset": "fadeIn" }, "emphasis": { "preset": "pulse" }, "out": { "preset": "fadeOut" } }`; the entrance plays just before `start` and the exit just after `end`, so the overlay is fully there from start to end.

The types, and whether overlays, annotations and the resource library can use them:

| Type | Overlay | Annotation | Library | What it is |
|------|:-------:|:----------:|:-------:|------------|
| `text` | ✓ | ✓ |  | Text overlay or annotation: rich text with an optional title and card style, for titles, captions, notes and explanations. |
| `html` | ✓ | ✓ |  | Custom HTML overlay or annotation, for what the other types cannot show. |
| `quiz` | ✓ | ✓ |  | Quiz overlay or annotation: a question with answers to choose (scored), or asking for a free text or a rating, to check understanding at a point in the video. |
| `hotspot` | ✓ |  |  | Hotspot overlay: a clickable shape (circle, rectangle, rounded rectangle) that marks something in the picture and can open a URL or jump to a time or another hypervideo, or a drawn mark (arrow, underline, freeform outline) that points something out. |
| `cursor` | ✓ |  |  | Cursor overlay: a mouse pointer with click ripples, for walking through a screen recording. |
| `counter` | ✓ |  |  | Counter overlay: a number that counts up or rolls like an odometer, for a figure or statistic the video mentions. |
| `chart` | ✓ |  |  | Chart overlay: an animated bar, line or donut chart, or a progress ring, for numbers the video talks about. |
| `image` | ✓ | ✓ | ✓ | Image: a picture, diagram or photo. |
| `video` | ✓ | ✓ | ✓ | Video file (MP4, WebM or HLS) shown over or beside the hypervideo's own video. |
| `audio` | ✓ | ✓ | ✓ | Audio file with player controls: narration, music, a sound clip. |
| `pdf` | ✓ | ✓ | ✓ | PDF document in a viewer: a handout, a paper, slides. |
| `youtube` | ✓ | ✓ | ✓ | YouTube video. |
| `vimeo` | ✓ | ✓ | ✓ | Vimeo video. |
| `wistia` | ✓ | ✓ | ✓ | Wistia video. |
| `loom` | ✓ | ✓ | ✓ | Loom video. |
| `twitch` | ✓ | ✓ | ✓ | Twitch video or live channel. |
| `soundcloud` | ✓ | ✓ | ✓ | SoundCloud track or playlist. |
| `spotify` | ✓ | ✓ | ✓ | Spotify track, album, playlist or podcast. |
| `webpage` | ✓ | ✓ | ✓ | Web page shown in an iframe, or as a card when the site forbids embedding. |
| `wikipedia` | ✓ | ✓ | ✓ | Wikipedia article summary, for background on a term, a person or an event. |
| `entity` | ✓ | ✓ | ✓ | Linked data entity, such as a Wikidata item: a person, place or concept with its description. |
| `location` | ✓ | ✓ | ✓ | Map (OpenStreetMap via Leaflet) centred on a coordinate, for a place the video shows or mentions. |
| `mastodon` | ✓ | ✓ | ✓ | Mastodon post. |
| `codepen` | ✓ | ✓ | ✓ | CodePen embed: a live HTML, CSS and JavaScript demo. |
| `figma` | ✓ | ✓ | ✓ | Figma embed: a design file or prototype. |
| `urlpreview` | ✓ | ✓ | ✓ | Link preview card: title, description and image of a page, or the platform's own embed for posts from Bluesky, X, TikTok, SlideShare, Reddit and Flickr. |
