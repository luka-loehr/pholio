// Content negotiation and discovery headers for a Pholio site on Cloudflare Workers static assets.
//
// A Pholio build writes a Markdown twin next to every page (guide/install.md next to
// guide/install/index.html). Static assets alone cannot choose between the two per request,
// so this Worker runs before them and does what the generated .htaccess does on Apache:
//
//   Accept: text/markdown, or an AI assistant's user agent   the twin, as text/markdown
//   Accept: text/plain                                        the twin, as text/plain
//   every response, 404s included                            Link, X-Llms-Txt, Vary: Accept, User-Agent
//
// Set BASE_PATH to base_path from pholio.config.php. Leave out Link entries for files the build
// does not write (the agent card needs site.url).

const BASE_PATH = '/';

// src/AgentHeaders.php, USER_AGENTS.
const AGENTS = /Claude-User|ChatGPT-User|OAI-SearchBot|PerplexityBot|Perplexity-User|Google-Agent|MistralAI-User|DuckAssistBot|cohere-ai/i;

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
  if (/text\/markdown/i.test(accept) || AGENTS.test(request.headers.get('User-Agent') ?? '')) {
    return 'text/markdown; charset=utf-8';
  }
  return /text\/plain/i.test(accept) ? 'text/plain; charset=utf-8' : null;
}

function withHeaders(response, contentType = null) {
  const out = new Response(response.body, response);
  for (const [name, value] of Object.entries(HEADERS)) out.headers.set(name, value);
  if (contentType) out.headers.set('Content-Type', contentType);
  return out;
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
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
