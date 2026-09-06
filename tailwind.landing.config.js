// Landing page build: full preflight, centered container.
const tokens = require('./tailwind.tokens.js');

module.exports = {
  content: ['./views/landing.php'],
  theme: {
    container: { center: true, padding: '1.25rem', screens: { '2xl': '1120px' } },
    extend: tokens,
  },
};
