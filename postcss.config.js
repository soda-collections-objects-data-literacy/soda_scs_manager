export default {
  plugins: {
    "@tailwindcss/postcss": {}
  },
  map: {
    inline: false, // Generates a separate .map file
    annotation: true, // Adds annotation comment to the CSS
  }
};
