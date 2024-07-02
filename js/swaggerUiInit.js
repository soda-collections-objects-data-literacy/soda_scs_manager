(function ($, Drupal, drupalSettings) {
    Drupal.behaviors.swaggerUI = {
      attach: function (context, settings) {
        const ui = SwaggerUIBundle({
          url: drupalSettings.swaggerSpecUrl,
          dom_id: '#swagger-ui',
          presets: [
            SwaggerUIBundle.presets.apis,
            SwaggerUIStandalonePreset
          ],
          layout: "StandaloneLayout",
        });
      }
    };
  })(jQuery, Drupal, drupalSettings);