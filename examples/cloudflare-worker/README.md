# Cloudflare Worker for agents

Static hosts serve Pholio's `_headers` file, so the `Link` and `X-Llms-Txt` headers
reach agents without extra work. What they can't do on their own is answer a page URL
with its Markdown twin when an agent asks for `text/markdown`. `worker.js` does that in
front of Cloudflare Workers static assets, the same way the generated `.htaccess` does
on Apache.

1. Copy `worker.js` and `wrangler.jsonc` into your project, for example into `cloudflare/`.
2. In `wrangler.jsonc`, point `assets.directory` at your `output_dir` and set `name`.
3. In `worker.js`, set `BASE_PATH` to your `base_path`, and drop the agent card from the
   `Link` header if `site.url` is not set.
4. Build and deploy: `pholio build && npx wrangler deploy --config cloudflare/wrangler.jsonc`.

Check the result with `node verify/agent-score.mjs --base https://your-docs.example`
from a Pholio checkout.

On Netlify, the same logic fits into an Edge Function in front of the site. See
[Agents](../../docs/agents.md) for the rules to reproduce.
