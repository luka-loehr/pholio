# Release notes <!-- [!code focus] -->

## 0.2.0

- Search ranks titles first <!-- [!code highlight] -->
- Code tabs keep their order
- The old `--legacy` flag <!-- [!code --] -->
- The new `--strict` flag <!-- [!code ++] -->

<!-- [!code highlight:2] -->
Install it with `pholio` and run `pholio dev`,
then open the preview.

<!-- [!code word:pholio] -->
Every `pholio` command prints `--help`; pholio builds in place.

```ts title="search.ts"
const hits = await search('invoice'); // [!code highlight]
```
