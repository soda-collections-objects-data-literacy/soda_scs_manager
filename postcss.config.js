const isProduction = process.env.NODE_ENV === "production";

export default {
  plugins: {
    "@tailwindcss/postcss": {},
    ...(isProduction ? { cssnano: {} } : {}),
  },
  // Source maps only for build:dev / watch; the committed production file has none.
  map: isProduction ? false : { inline: false, annotation: true },
};
