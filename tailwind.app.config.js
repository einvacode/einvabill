// Admin shell build. Preflight is off so public/style.css keeps styling the
// pages that have not been rebuilt yet.
//
// Colours resolve through CSS variables (declared in src/css/app.css) so the
// whole shell flips with data-theme on <html>. The channels are raw RGB triplets
// and every colour keeps `<alpha-value>`, which is what lets utilities like
// border-danger/40 still work. The landing page keeps the literal hex values
// from tailwind.tokens.js: it is light-only.
const tokens = require('./tailwind.tokens.js');

const c = (name) => `rgb(var(--c-${name}) / <alpha-value>)`;

module.exports = {
  content: ['./views/**/*.php', './index.php', './app/**/*.php'],
  corePlugins: { preflight: false },
  darkMode: ['class', '[data-theme="dark"]'],
  theme: {
    extend: {
      fontFamily: tokens.fontFamily,
      borderRadius: tokens.borderRadius,
      colors: {
        background: c('background'),
        foreground: c('foreground'),
        card: c('card'),
        border: c('border'),
        sidebar: c('sidebar'),
        input: c('input'),
        ring: c('ring'),
        primary: { DEFAULT: c('primary'), foreground: c('primary-foreground'), deep: c('primary-deep'), soft: c('primary-soft') },
        muted: { DEFAULT: c('muted'), foreground: c('muted-foreground') },
        accent: { DEFAULT: c('accent'), foreground: c('accent-foreground'), soft: c('accent-soft'), ink: c('accent-ink') },
        signal: { DEFAULT: c('signal'), soft: c('signal-soft') },
        danger: { DEFAULT: c('danger'), soft: c('danger-soft') },
        wa: { DEFAULT: c('wa'), hover: c('wa-hover') },
      },
      boxShadow: {
        card: 'var(--shadow-card)',
        lift: 'var(--shadow-lift)',
      },
    },
  },
  safelist: [
    // Classes injected by JavaScript in views/layout.php
    'ui-badge', 'ui-badge-signal', 'ui-badge-danger', 'inline-block', 'h-1.5', 'w-1.5', 'rounded-full', 'bg-signal', 'bg-danger',
    'hidden', 'flex', '-translate-x-full',
  ],
};
