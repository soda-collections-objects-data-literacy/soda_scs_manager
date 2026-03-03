(function ($, Drupal, once) {
  Drupal.behaviors.activeBookPage = {
    attach: function (context, settings) {
      once('boldNavTitle', '.block-custom-book-navigation', context).forEach(function (element) {
        var currentPath = window.location.pathname.replace(/\/$/, "");
        
        var links = $(element).find('nav[role="navigation"] a, ul a');
        
        links.each(function() {
          let rawHref = $(this).attr('href');
          
          if (rawHref) {
            let linkPathString = rawHref.split('?')[0].replace(/\/$/, "");
            
            console.log(currentPath, linkPathString);

            if (linkPathString === currentPath) {
              $(this).addClass('is-active-page');
            }
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);