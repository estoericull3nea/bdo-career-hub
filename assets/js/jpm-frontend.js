jQuery(document).ready(function ($) {
  // Toast notification function
  function showToast(message, type) {
    type = type || "success"; // success, error, warning, info
    var toast = $(
      '<div class="jpm-toast jpm-toast-' +
        type +
        '">' +
        '<div class="jpm-toast-content">' +
        '<span class="jpm-toast-icon"></span>' +
        '<span class="jpm-toast-message">' +
        message +
        "</span>" +
        "</div>" +
        '<button class="jpm-toast-close">&times;</button>' +
        "</div>"
    );

    // Add to container or body
    var container = $("#jpm-toast-container");
    if (container.length === 0) {
      $("body").append('<div id="jpm-toast-container"></div>');
      container = $("#jpm-toast-container");
    }

    container.append(toast);

    // Trigger animation
    setTimeout(function () {
      toast.addClass("show");
    }, 10);

    // Auto remove after 5 seconds
    var autoRemove = setTimeout(function () {
      hideToast(toast);
    }, 5000);

    // Close button
    toast.find(".jpm-toast-close").on("click", function () {
      clearTimeout(autoRemove);
      hideToast(toast);
    });
  }

  function hideToast(toast) {
    toast.removeClass("show");
    setTimeout(function () {
      toast.remove();
    }, 300);
  }

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
        showToast("Application submitted successfully!", "success");
      },
      error: function () {
        showToast("An error occurred. Please try again.", "error");
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
    var $submitBtn = $form.find('button[type="submit"]');
    var originalText = $submitBtn.text();
    $submitBtn
      .prop("disabled", true)
      .html(originalText + ' <span class="jpm-loading-spinner"></span>');
    $message.removeClass("success error").html("");

    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      timeout: 60000, // 60 seconds timeout for file uploads
      success: function (response) {
        // Handle both JSON and string responses
        if (typeof response === "string") {
          try {
            response = JSON.parse(response);
          } catch (e) {
            console.error("Failed to parse response:", response);
            showToast(
              "Invalid response from server. Please try again.",
              "error"
            );
            return;
          }
        }

        if (response.success) {
          // Show success toast
          showToast(
            response.data.message || "Application submitted successfully!",
            "success"
          );

          // Also update inline message
          $message
            .addClass("success")
            .html(
              "<p>" +
                (response.data.message ||
                  "Application submitted successfully!") +
                "</p>"
            );

          // Reset form
          $form[0].reset();

          // Scroll to message
          $("html, body").animate(
            {
              scrollTop: $message.offset().top - 100,
            },
            500
          );
        } else {
          // Show error toast
          showToast(
            response.data.message || "An error occurred. Please try again.",
            "error"
          );

          // Also update inline message
          $message
            .addClass("error")
            .html(
              "<p>" +
                (response.data.message ||
                  "An error occurred. Please try again.") +
                "</p>"
            );
        }
      },
      error: function (xhr, status, error) {
        var errorMessage = "An error occurred. Please try again.";

        // Try to get error message from response
        if (xhr.responseJSON) {
          if (xhr.responseJSON.data && xhr.responseJSON.data.message) {
            errorMessage = xhr.responseJSON.data.message;
          } else if (xhr.responseJSON.message) {
            errorMessage = xhr.responseJSON.message;
          }
        } else if (xhr.responseText) {
          // Try to parse HTML error response
          try {
            var parser = new DOMParser();
            var doc = parser.parseFromString(xhr.responseText, "text/html");
            var errorDiv = doc.querySelector(".error, .notice-error");
            if (errorDiv) {
              errorMessage = errorDiv.textContent.trim();
            }
          } catch (e) {
            // Ignore parsing errors
          }
        }

        // Log error for debugging
        console.error("AJAX Error:", {
          status: xhr.status,
          statusText: xhr.statusText,
          response: xhr.responseJSON || xhr.responseText,
          error: error,
        });

        // Show error toast
        showToast(errorMessage, "error");

        // Also update inline message
        $message.addClass("error").html("<p>" + errorMessage + "</p>");
      },
      complete: function () {
        var $submitBtn = $form.find('button[type="submit"]');
        $submitBtn.prop("disabled", false).html("Submit Application");
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
