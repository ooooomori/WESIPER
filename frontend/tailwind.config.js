/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './src/**/*.{html,js,jsx,ts,tsx}',
    './src/pages/**/*.{html,js}',
    './src/pages/**/*',
    "node_modules/flowbite-react/lib/esm/**/*.js",
  ],
  theme: {
    extend: {
      animation: {
        'spin-fast': 'spin 1s ease',
      },
      colors: {
        'kbo': '#002561',
      },
    },
  },
  plugins: [
    require("flowbite/plugin"),
  ],
}
