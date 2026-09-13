---
title: Themes
description: The eleven built-in color presets in light and dark, and how to put your own colors on top.
icon: palette
---

Every Pholio site uses the same layout and components. What changes between
sites is the palette: a light and a dark set of color tokens that every
component reads. Pick a preset with one line in `pholio.config.php`:

```php title="pholio.config.php"
'theme' => [
    'preset' => 'catppuccin',
],
```

The default is `neutral`. Every preset ships in the stylesheet and is scoped to
`<html data-preset="name">`, so `theme.preset` only sets the preset a page starts
with, and a script can switch presets live:

```js
document.documentElement.dataset.preset = 'ocean';
```

Adjust single tokens with `theme.light` and `theme.dark`, or add a whole
stylesheet with `theme.palette_css`; both apply on top of whichever preset is
active. [Configuration](/docs/configuration#theme) lists the keys.

Each preset below is the demo site's callouts and cards page, first in the
light scheme, then in the dark one.

## neutral

![The neutral preset in the light scheme](./assets/themes/neutral-light.webp)

![The neutral preset in the dark scheme](./assets/themes/neutral-dark.webp)

## black

![The black preset in the light scheme](./assets/themes/black-light.webp)

![The black preset in the dark scheme](./assets/themes/black-dark.webp)

## vitepress

![The vitepress preset in the light scheme](./assets/themes/vitepress-light.webp)

![The vitepress preset in the dark scheme](./assets/themes/vitepress-dark.webp)

## dusk

![The dusk preset in the light scheme](./assets/themes/dusk-light.webp)

![The dusk preset in the dark scheme](./assets/themes/dusk-dark.webp)

## catppuccin

![The catppuccin preset in the light scheme](./assets/themes/catppuccin-light.webp)

![The catppuccin preset in the dark scheme](./assets/themes/catppuccin-dark.webp)

## ocean

![The ocean preset in the light scheme](./assets/themes/ocean-light.webp)

![The ocean preset in the dark scheme](./assets/themes/ocean-dark.webp)

## purple

![The purple preset in the light scheme](./assets/themes/purple-light.webp)

![The purple preset in the dark scheme](./assets/themes/purple-dark.webp)

## solar

From 768 pixels wide, solar also sets the article on a raised card.

![The solar preset in the light scheme](./assets/themes/solar-light.webp)

![The solar preset in the dark scheme](./assets/themes/solar-dark.webp)

## emerald

![The emerald preset in the light scheme](./assets/themes/emerald-light.webp)

![The emerald preset in the dark scheme](./assets/themes/emerald-dark.webp)

## ruby

![The ruby preset in the light scheme](./assets/themes/ruby-light.webp)

![The ruby preset in the dark scheme](./assets/themes/ruby-dark.webp)

## aspen

![The aspen preset in the light scheme](./assets/themes/aspen-light.webp)

![The aspen preset in the dark scheme](./assets/themes/aspen-dark.webp)

## Your own palette

A token map is enough for a brand color:

```php title="pholio.config.php"
'theme' => [
    'preset' => 'neutral',
    'light' => ['primary' => 'hsl(220 85% 45%)'],
    'dark' => ['primary' => 'hsl(220 90% 70%)'],
],
```

For more, `theme.palette_css` names a stylesheet that is inserted after the
preset and the token maps. The tokens are `--color-fd-background`,
`foreground`, `muted`, `muted-foreground`, `popover`, `popover-foreground`,
`card`, `card-foreground`, `border`, `primary`, `primary-foreground`,
`secondary`, `secondary-foreground`, `accent`, `accent-foreground` and `ring`,
set on `:root` for light and on `.dark` for dark.
