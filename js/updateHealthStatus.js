(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.updateHealthStatus = {
    attach: function (context, settings) {
      once('updateHealthStatus', 'html', context).forEach(function () {
        const healthUrl = drupalSettings.componentInfo.healthUrl;
        const dotSpan = $("<span class='dot'>.</span>");
        setInterval(function () {
          if ($("div.field--name-health div.field__item .dot").length === 3) {
            $("div.field--name-health div.field__item .dot").remove();
          }
          $("div.field--name-health div.field__item").append(dotSpan.clone());
        }, 1000);
        setInterval(function () {
          $.ajax({
            url: healthUrl,
            method: "GET",
          }).then(function (data, textStatus, jqXHR) {
            let $data = $(data);
            if ($data[0]['status']['success'] === true) {
              $("div.field--name-health div.field__item .dot").remove();
              $("div.field--name-health div.field__item").text('Running')
            } else {
              $("div.field--name-health div.field__item").text($data[0]['status']['message'])
              $("div.field--name-health div.field__label").addClass('soda-scs-manager--component-status--api-error').attr('title', $data[0]['status']['error']);
            }


          }).fail(function (jqXHR, textStatus, errorThrown) {
            $("div.field--name-health div.field__item").text($data[0]['status']['message']);
            $("div.field--name-health div.field__label").addClass('soda-scs-manager--component-status--api-error').attr('title', 'Health controller has internal error or is not reachable');
          });
        }, 3000)
      });
    }
  };
})(jQuery, Drupal, drupalSettings);
