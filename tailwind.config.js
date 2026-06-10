/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './index.php',
    './login.php',
    './claimant-access.php',
    './verify-otp.php',
    './components/**/*.php',
    './Admin/**/*.php',
    './Claimant/**/*.php',
    './Finance/**/*.php',
    './Legal/**/*.php',
    './assets/js/**/*.js',
  ],
  darkMode: false,
  safelist: [
    'ui-btn',
    'ui-btn-sm',
    'ui-btn-md',
    'ui-btn-lg',
    'ui-btn-primary',
    'ui-btn-secondary',
    'ui-btn-ghost',
    'ui-input',
    'ui-select',
    'ui-checkbox',
    'ui-alert',
    'ui-alert-info',
    'ui-alert-success',
    'ui-alert-warning',
    'ui-alert-danger',
    'ui-toast',
    'ui-card',
    'ui-modal-backdrop',
    'ui-modal-panel',
    'ui-navbar',
    'ui-sidebar',
    'ui-breadcrumb',
  ],
  theme: {
    extend: {
      colors: {
        bk: {
          primary: 'rgb(var(--bk-primary-rgb) / <alpha-value>)',
          bg: 'rgb(var(--bk-bg-rgb) / <alpha-value>)',
          surface: 'rgb(var(--bk-surface-rgb) / <alpha-value>)',
          text: 'rgb(var(--bk-text-rgb) / <alpha-value>)',
          muted: 'rgb(var(--bk-muted-rgb) / <alpha-value>)',
          border: 'rgb(var(--bk-border-rgb) / <alpha-value>)',
          success: 'rgb(var(--bk-success-rgb) / <alpha-value>)',
          warning: 'rgb(var(--bk-warning-rgb) / <alpha-value>)',
          danger: 'rgb(var(--bk-danger-rgb) / <alpha-value>)',
        },
      },
      fontFamily: {
        sans: ['var(--app-font)', 'Inter', 'system-ui', 'sans-serif'],
        display: ['var(--app-display-font)', 'Inter', 'system-ui', 'sans-serif'],
      },
      borderRadius: {
        app: 'var(--radius)',
        sm: 'calc(var(--radius) - 6px)',
        md: 'calc(var(--radius) - 4px)',
        lg: 'calc(var(--radius) - 2px)',
        xl: 'calc(var(--radius) + 2px)',
        '2xl': 'calc(var(--radius) + 8px)',
      },
      boxShadow: {
        app: 'var(--shadow)',
        soft: 'var(--shadow-soft)',
        glow: 'var(--shadow-glow)',
      },
      spacing: {
        18: '4.5rem',
        22: '5.5rem',
        26: '6.5rem',
      },
      ringColor: {
        bk: 'rgb(var(--bk-ring-rgb) / 0.45)',
      },
      ringOffsetColor: {
        bk: 'rgb(var(--bk-bg-rgb) / 1)',
      },
      keyframes: {
        float: {
          '0%, 100%': { transform: 'translateY(0px)' },
          '50%': { transform: 'translateY(-8px)' },
        },
        'fade-up': {
          '0%': { opacity: '0', transform: 'translateY(14px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        pulseGlow: {
          '0%, 100%': { boxShadow: 'var(--shadow-soft)' },
          '50%': { boxShadow: 'var(--shadow-glow)' },
        },
      },
      animation: {
        float: 'float 6s ease-in-out infinite',
        'fade-up': 'fade-up 0.5s ease-out both',
        'pulse-glow': 'pulseGlow 2.8s ease-in-out infinite',
      },
      backgroundImage: {
        mesh: 'var(--mesh-gradient)',
      },
    },
  },
  plugins: [],
};
