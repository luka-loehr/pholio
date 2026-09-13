// Content negotiation and discovery headers for a Pholio site on Cloudflare Workers static assets.
//
// A Pholio build writes a Markdown twin next to every page (guide/install.md next to
// guide/install/index.html). Static assets alone cannot choose between the two per request,
// so this Worker runs before them and does what the generated .htaccess does on Apache:
//
//   Accept: text/markdown, or an AI assistant's user agent   the twin, as text/markdown
//   Accept: text/plain                                        the twin, as text/plain
//   OpenAI's agents                                           every twin and .md file as text/plain
//   a .md file the build did not write                        404
//   every response, 404s included                            Link, X-Llms-Txt, Vary: Accept, User-Agent
//
// Set BASE_PATH to base_path from pholio.config.php. Leave out Link entries for files the build
// does not write (the agent card needs site.url).

const BASE_PATH = '/';

// src/AgentHeaders.php, USER_AGENTS.
const AGENTS = /Claude-User|ChatGPT-User|OAI-SearchBot|PerplexityBot|Perplexity-User|Google-Agent|MistralAI-User|DuckAssistBot|cohere-ai/i;
// src/AgentHeaders.php, PLAIN_USER_AGENTS: they reject text/markdown.
const PLAIN_AGENTS = /ChatGPT-User|OAI-SearchBot|GPTBot/i;

const MARKDOWN = 'text/markdown; charset=utf-8';
const PLAIN = 'text/plain; charset=utf-8';

const home = BASE_PATH.replace(/\/+$/, '');
const HEADERS = {
  Link: [
    `<${home}/llms.txt>; rel="llms-txt"`,
    `<${home}/llms-full.txt>; rel="llms-full-txt"`,
    `<${home}/.well-known/agent-card.json>; rel="agent-card"`,
    `<${home}/.well-known/agent-skills/index.json>; rel="agent-skills"`,
  ].join(', '),
  'X-Llms-Txt': `${home}/llms.txt`,
  Vary: 'Accept, User-Agent',
};

/** The content type of the twin the request asks for, or null for the HTML page. */
function twinType(request) {
  const accept = request.headers.get('Accept') ?? '';
  const agent = request.headers.get('User-Agent') ?? '';
  if (/text\/markdown/i.test(accept) || AGENTS.test(agent)) {
    return PLAIN_AGENTS.test(agent) ? PLAIN : MARKDOWN;
  }
  return /text\/plain/i.test(accept) ? PLAIN : null;
}

function withHeaders(response, contentType = null) {
  const out = new Response(response.body, response);
  for (const [name, value] of Object.entries(HEADERS)) out.headers.set(name, value);
  if (contentType) out.headers.set('Content-Type', contentType);
  return out;
}

/** Whether a .md path is one the build writes: a page twin, index.md, skill.md, an _llms/ index or .well-known/. */
async function servesMarkdown(url, request, env) {
  if (!url.pathname.startsWith(`${home}/`)) return false;
  const relative = url.pathname.slice(home.length + 1);
  if (['index.md', 'skill.md'].includes(relative) || relative.startsWith('_llms/') || relative.startsWith('.well-known/')) return true;
  const sibling = new URL(`${url.pathname.slice(0, -3)}/index.html`, url);
  const response = await env.ASSETS.fetch(new Request(sibling, { method: 'HEAD', headers: request.headers }));
  return response.status !== 404;
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (/\.md$/i.test(url.pathname)) {
      if (!(await servesMarkdown(url, request, env))) {
        return withHeaders(new Response('404 Not Found\n', { status: 404, headers: { 'Content-Type': PLAIN } }));
      }
      const response = await env.ASSETS.fetch(request);
      const agent = request.headers.get('User-Agent') ?? '';
      return withHeaders(response, response.ok ? (PLAIN_AGENTS.test(agent) ? PLAIN : MARKDOWN) : null);
    }

    const type = twinType(request);
    // Page URLs have no file extension. x and x/ have the twin x.md, the start page index.md.
    if (type && (request.method === 'GET' || request.method === 'HEAD') && !/\.[a-z0-9]+$/i.test(url.pathname)) {
      const path = url.pathname.replace(/\/+$/, '');
      const twin = new URL(path === home ? `${home}/index.md` : `${path}.md`, url);
      const response = await env.ASSETS.fetch(new Request(twin, request));
      if (response.ok) return withHeaders(response, type);
    }

    return withHeaders(await env.ASSETS.fetch(request));
  },
};
