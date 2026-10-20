
# Writing pages

Pages are *Markdown* with a few **component tags**. Mix ***both***, ~~strike~~
and `inline code`, or escape them: \*not emphasis\*, \_no underscore\_, \`no code\`.
Entities stay as written: &amp; &copy; &#169; &#xA9; and a bare & ampersand.
Strikethrough: ~~gone~~, ~~~three~~~, a_~~mid~~_b, ~~end_~~word, ~~a ~ b~~, \~~escaped~~ and ~~open.

Setext heading
==============

Second level
------------

## Lists

- First item
* Second item, another marker
+ Third item
	- Nested with a tab
	- [ ] An open task
	- [x] A finished task

1. One
2. Two
10) Ten, with a parenthesis

## Quotes and rules

> A quote with **strong** text
> - and a list
>
> > nested twice

---
***
_ _ _

## Links and images

An [inline link](https://example.com "Title"), a [relative one](../guide/setup.md#install),
a [reference link][ref], a collapsed [ref][] and an autolink <https://example.com/docs>.
Mail <mailto:docs@example.com> and a bare URL https://example.com/plain.

![Pipeline](/assets/images/pipeline.svg "The build pipeline")
[![Badge](https://img.shields.io/badge/a-b-blue.svg)](https://example.com)

[ref]: https://example.com/reference "Reference title"

<!-- A comment that spans
     two lines -->

## HTML and tables

Press <kbd>Ctrl</kbd> + <kbd>K</kbd> to search.

<details>
<summary>More</summary>

Hidden **Markdown**.

</details>

| Name | Default | Notes |
| :--- | :-----: | ----: |
| `port` | 8080 | `--port` |
| width | 1.5 | \| escaped pipe |

    an indented code block
    with two lines

Footnote reference[^1] and a hard line break  
on the next line.

[^1]: The footnote text.
