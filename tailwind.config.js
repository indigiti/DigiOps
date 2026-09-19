/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts}'],
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
      boxShadow: {
        panel: '0 18px 50px rgba(15, 23, 42, .08)',
        soft: '0 8px 24px rgba(15, 23, 42, .06)'
      }
    }
  },
  plugins: []
}
