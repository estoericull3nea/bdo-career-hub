jQuery(document).ready(function ($) {
  // AJAX for application submission (legacy form)
  $("#jpm-apply-form").on("submit", function (e) {
    e.preventDefault();
    var formData = new FormData(this);
    formData.append("action", "jpm_apply");
    formData.append("nonce", jpm_ajax.nonce);
    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      success: function (response) {
        alert(response);
      },
    });
  });

  // AJAX for new application form submission
  $("#jpm-application-form").on("submit", function (e) {
    e.preventDefault();
    var $form = $(this);
    var $message = $("#jpm-form-message");
    var formData = new FormData(this);

    // Add action and nonce
    formData.append("action", "jpm_submit_application_form");
    formData.append(
      "jpm_application_nonce",
      $("#jpm-application-form input[name='jpm_application_nonce']").val()
    );

    // Show loading state
    $form
      .find('button[type="submit"]')
      .prop("disabled", true)
      .text("Submitting...");
    $message.removeClass("success error").html("");

    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      success: function (response) {
        if (response.success) {
          $message
            .addClass("success")
            .html("<p>" + response.data.message + "</p>");
          $form[0].reset();
          // Scroll to message
          $("html, body").animate(
            {
              scrollTop: $message.offset().top - 100,
            },
            500
          );
        } else {
          $message
            .addClass("error")
            .html("<p>" + response.data.message + "</p>");
        }
      },
      error: function (xhr, status, error) {
        $message
          .addClass("error")
          .html("<p>An error occurred. Please try again.</p>");
      },
      complete: function () {
        $form
          .find('button[type="submit"]')
          .prop("disabled", false)
          .text("Submit Application");
      },
    });
  });

  // Polling for status
  setInterval(function () {
    $.post(
      jpm_ajax.ajax_url,
      { action: "jpm_get_status", nonce: jpm_ajax.nonce },
      function (data) {
        $("#jpm-status-updates").html(data); // Update DOM
      }
    );
  }, 30000);
});
