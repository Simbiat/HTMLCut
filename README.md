# HTMLCut
This is a class to cut HTML while preserving (to an extent) HTML structure. Can be used to create previews of articles, written in HTML and stored in database, for example. Will work just as well with regular text.

## Why?
This class has some benefits:
1. Preserve HTML tags, unless they are empty.
2. Preserve words.
3. Remove some orphaned punctuation signs at the end of the cut string.
4. Remove HTML tags, that you would not want in a preview (optional).
5. Limit number of paragraphs (optional).
6. Add an ellipsis if text was cut (optional).

## Details on usage
```php
Cut(\DOMNode|string $string, int $length, int $paragraphs = 0, string $ellipsis = '…', bool $stripUnwanted = true)
```
`$string` is the text you want to cut. This argument also accepts `\DOMNode` objects, but it's not intended, that you will send them to it: this is simply a requirement due to recursive nature of the function.
`$length` is the expected maximum of the resulting text. Note, that due to words preservation result **may** be a bit longer, but normally, not by much.
`$paragraphs` is the maximum number of paragraphs in the result. In this case `paragraph` means not only text with following new line (or end of string) and `<p>` tags, but also some other HTML tags, that are generally shown as if a new paragraph, like `<li>`. This is useful if you want to limit the number of `lines` shown in your previews. Generally, cutting by characters should be enough, but if the content you are sharing starts with multiple short lines (for example, a poem), this may result in a preview, that will be longer, than the rest. Setting this to a value more than `0` will help prevent that. List of tags to treat as paragraphs can be edited before calling the function by directly modifying `$paraTags` class variable.
`$ellipsis` is an optional text, that you want to display after the cut text. Normally you would want this to be a link like "Read More" or something else. Defaults to unicode vertical lower ellipsis symbol `…` (not `...`).
`$stripUnwanted` if set to `true` will remove any tags, that you may not want to be shown in preview, like images and tables. List of tags to remove can be directly modified by editing `$extraTags` class variable.
