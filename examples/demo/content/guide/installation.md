---
title: Installation
description: Install Lanternfly with your package manager of choice and check that it runs.
icon: download
updated: 2026-09-13
---

<InlineTOC />

## Requirements

Lanternfly runs on macOS, Linux and Windows. It needs about 20 MB of disk space
and nothing else.

## Install

The code tabs below share a group, so choosing a package manager here also
selects it on every other page that uses the same group.

```bash tab="Homebrew"
brew install lanternfly
```

```bash tab="apt"
sudo apt install lanternfly
```

```sh tab="winget"
winget install Lanternfly.Lanternfly
```

## Verify

<Steps>
<Step>

### Open a terminal

Any shell works. On Windows, PowerShell is recommended.

</Step>
<Step>

### Ask for the version

```bash
lanternfly --version
```

</Step>
<Step>

### Read the output

You should see a single line such as `lanternfly 2.4.0`. Anything else means the
binary is not on your `PATH`.

</Step>
</Steps>

## Upgrading

<Callout type="warning" title="Archives from 1.x">
Version 2 reads 1.x archives but writes a new format. Keep a copy of old
archives until every machine that opens them has been upgraded.
</Callout>
