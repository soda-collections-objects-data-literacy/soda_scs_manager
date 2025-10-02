export default {
  plugins: {
    "@tailwindcss/postcss": {},
    ...(process.env.NODE_ENV === 'production' ? { cssnano: {} } : {})
  },
  map: {
    inline: false, // Generates a separate .map file.
    annotation: true, // Adds annotation comment to the CSS.
  }
};
