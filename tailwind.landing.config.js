// Landing page build: full preflight, centered container. Light-only by design —
// the theme switch in the staff app does not reach this page.
const tokens = require('./tailwind.tokens.js');

module.exports = {
  content: ['./views/landing.php'],
  theme: {
    container: { center: true, padding: '1.25rem', screens: { '2xl': '1120px' } },
    extend: {
      ...tokens,
      fontFamily: {
        ...tokens.fontFamily,
        // Archivo carries the headline: a wider, more engineered grotesque than the
        // body face, so the two are told apart at a glance.
        display: ['Archivo', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      colors: {
        ...tokens.colors,
        // Hero band only. Deep petrol ground, amber for the light inside the fibre.
        hero: {
          DEFAULT: '#071319',
          ink: '#E8EFF1',
          mute: '#8CA3AC',
          line: '#16323B',
          fiber: '#E9A93C',
          hot: '#FFE7BE',
        },
      },
    },
  },
};
