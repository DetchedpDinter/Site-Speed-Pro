jQuery(function ($) {
  $("#sitespeedpro-db-clean").on("click", function () {
    const $btn = $(this),
      $results = $("#sitespeedpro-db-clean-results");
    $btn.prop("disabled", true).text("Cleaning..."),
      $.post(SiteSpeedProDB.ajax_url, {
        action: "sitespeedpro_db_clean",
        nonce: SiteSpeedProDB.nonce,
      })
        .done(function (response) {
          if (response.success) {
            let res = response.data,
              html = "<ul>";
            for (const [key, val] of Object.entries(res))
              html += `<li><strong>${key}</strong>: ${val}</li>`;
            (html += "</ul>"), $results.html(html);
          } else $results.html(`<p style="color:red;">Error: ${response.data.message}</p>`);
        })
        .fail(function () {
          $results.html('<p style="color:red;">AJAX request failed.</p>');
        })
        .always(function () {
          $btn.prop("disabled", false).text("Run DB Cleanup");
        });
  });
});
