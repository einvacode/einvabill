// Shared design tokens (shadcn/ui shape) for the admin shell and the landing page.
module.exports = {
  fontFamily: { sans: ['"Plus Jakarta Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
  colors: {
    background: '#F5F7F6',
    foreground: '#172026',
    card: '#FFFFFF',
    border: '#D9E0E2',
    input: '#D9E0E2',
    ring: '#0F3A47',
    primary: { DEFAULT: '#0F3A47', foreground: '#FFFFFF', deep: '#0A2A34', soft: '#E4EDF0' },
    muted: { DEFAULT: '#E9EEEC', foreground: '#5B6B72' },
    accent: { DEFAULT: '#E39B0A', foreground: '#1D1300', soft: '#FFF3D6', ink: '#7A5000' },
    signal: { DEFAULT: '#1F8A5B', soft: '#E3F3EA' },
    danger: { DEFAULT: '#B42318', soft: '#FBE9E7' },
    wa: { DEFAULT: '#1DA851', hover: '#178A43' },
  },
  borderRadius: { lg: '0.75rem', md: '0.5rem', sm: '0.375rem' },
  boxShadow: {
    card: '0 1px 2px rgba(23,32,38,.05), 0 1px 0 rgba(23,32,38,.02)',
    lift: '0 12px 32px -12px rgba(15,58,71,.25)',
  },
};
