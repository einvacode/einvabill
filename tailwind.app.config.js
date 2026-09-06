// Admin shell build. Preflight is off so public/style.css keeps styling the
// pages that have not been rebuilt yet.
const tokens = require('./tailwind.tokens.js');

module.exports = {
  content: ['./views/**/*.php', './index.php', './app/**/*.php'],
  corePlugins: { preflight: false },
  theme: { extend: tokens },
  safelist: [
    // Classes injected by JavaScript in views/layout.php
    'ui-badge', 'ui-badge-signal', 'ui-badge-danger', 'inline-block', 'h-1.5', 'w-1.5', 'rounded-full', 'bg-signal', 'bg-danger',
    'hidden', 'flex', '-translate-x-full',
  ],
};
