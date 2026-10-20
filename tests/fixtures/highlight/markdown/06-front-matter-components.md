---
title: Components
description: Every component tag, each followed by its source.
icon: blocks
keywords: [callout, cards, tabs]
---

<InlineTOC label="On this page" />

<Callout type="warning" title="Before you start">
The body is **Markdown**: lists, `code` and [links](/docs/content-format) work.
</Callout>

<Cards>
  <Card title="Getting started" href="/docs/getting-started" icon="rocket">
    Install Pholio and build the first site.
  </Card>
</Cards>

<Tabs items={["npm", "pnpm"]}>
<Tab value="npm">

```bash
npm install
```

</Tab>
</Tabs>

<Steps>
<Step>

### Write

Put pages into `content/`.

</Step>
</Steps>

````markdown
```php title="Tree.php" lineNumbers
<?php
echo 'nested';
```
````

```yaml
site:
  name: Docs # a comment
```

```lua
print("an unknown language stays plain")
```
