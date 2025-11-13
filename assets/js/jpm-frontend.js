jQuery(document).ready(function ($) {
  // Stepper Form Navigation
  function initStepperForm() {
    const $form = $("#jpm-application-form");
    if ($form.length === 0) return;

    const $steps = $(".jpm-form-step");
    const $stepperNav = $(".jpm-stepper-navigation .jpm-stepper-step");
    const $prevBtn = $(".jpm-btn-prev");
    const $nextBtn = $(".jpm-btn-next");
    const $submitBtn = $(".jpm-btn-submit");

    // Step 0 is application info (always visible), form steps start at 1
    // Summary step is the last step
    let currentStep = 1;
    const totalSteps = $steps.length; // Includes summary step
    const formStepsCount = totalSteps - 1; // Exclude step 0

    // Get current step from active step (skip step 0)
    const $activeStep = $steps.filter(".active").not('[data-step="0"]');
    if ($activeStep.length > 0) {
      currentStep = parseInt($activeStep.data("step")) || 1;
    } else {
      // If no active step found, activate step 1
      currentStep = 1;
    }

    // Function to go to a specific step (step 0 is always visible)
    function goToStep(stepIndex) {
      if (stepIndex < 1 || stepIndex >= totalSteps) return;

      // Hide all form steps (but keep step 0 visible)
      $steps.not('[data-step="0"]').removeClass("active").hide();

      // Show current step
      const $currentStepEl = $steps.filter('[data-step="' + stepIndex + '"]');
      $currentStepEl.addClass("active").show();

      // Update stepper navigation (map to form steps: 0-based index for nav, 1-based for form steps)
      $stepperNav.removeClass("active completed");
      $stepperNav.each(function (index) {
        const navStepIndex = index + 1; // Nav step 0 = form step 1, etc.
        const isSummaryNav = index === $stepperNav.length - 1; // Last nav item is summary
        const navStepNum = isSummaryNav ? totalSteps - 1 : navStepIndex;

        if (navStepNum < stepIndex) {
          $(this).addClass("completed");
        } else if (navStepNum === stepIndex) {
          $(this).addClass("active");
        }
      });

      // Update buttons
      if (stepIndex === 1) {
        $prevBtn.hide();
      } else {
        $prevBtn.show();
      }

      // Check if this is the summary step (last step)
      const isSummaryStep = stepIndex === totalSteps - 1;

      if (isSummaryStep) {
        // Populate summary before showing it
        populateSummary();
        $nextBtn.hide();
        $submitBtn.show();
      } else {
        $nextBtn.show();
        $submitBtn.hide();
      }

      currentStep = stepIndex;

      // Scroll to top of form
      $("html, body").animate(
        {
          scrollTop: $form.offset().top - 100,
        },
        300
      );
    }

    // Populate summary with form values
    function populateSummary() {
      $(".jpm-summary-item").each(function () {
        const $item = $(this);
        const fieldName = $item.data("field-name");
        const $valueContainer = $item.find(".jpm-summary-value");
        const fieldId = $valueContainer.data("field-id");

        // Skip if already has content (like application number, date)
        if (
          $valueContainer.find("span").length > 0 &&
          !$valueContainer.find(".jpm-summary-placeholder").length
        ) {
          return; // Already populated
        }

        // Handle special fields
        if (
          fieldName === "application_number" ||
          fieldId === "jpm_application_number"
        ) {
          const value = $("#jpm_application_number").val();
          if (value) {
            $valueContainer.html("<span>" + value + "</span>");
          }
          return;
        }

        if (
          fieldName === "date_of_registration" ||
          fieldId === "jpm_date_of_registration"
        ) {
          const value = $("#jpm_date_of_registration").val();
          if (value) {
            $valueContainer.html("<span>" + value + "</span>");
          }
          return;
        }

        // Find the actual form field
        let $field = $("#" + fieldId);

        if ($field.length === 0) {
          // Try to find by name attribute (handle array names like jpm_fields[field_name])
          $field = $form
            .find(
              '[name="jpm_fields[' +
                fieldName +
                ']"], [name*="' +
                fieldName +
                '"]'
            )
            .first();

          // If still not found, try picture input
          if ($field.length === 0) {
            $field = $form
              .find(".jpm-picture-input, .jpm-file-input")
              .filter(function () {
                const name = $(this).attr("name") || "";
                return name.includes(fieldName);
              })
              .first();
          }
        }

        if ($field.length > 0) {
          updateSummaryValue($valueContainer, $field, fieldName);
        } else {
          $valueContainer.html(
            '<span class="jpm-summary-empty">' + "Field not found" + "</span>"
          );
        }
      });
    }

    // Update summary value based on field type
    function updateSummaryValue($container, $field, fieldName) {
      const fieldType =
        $field.attr("type") || $field.prop("tagName").toLowerCase();
      let displayValue = "";

      if (
        fieldType === "file" ||
        $field.hasClass("jpm-file-input") ||
        $field.hasClass("jpm-picture-input")
      ) {
        // Handle file uploads
        if ($field[0].files && $field[0].files.length > 0) {
          const fileNames = [];
          const filePreviews = [];

          for (let i = 0; i < $field[0].files.length; i++) {
            const file = $field[0].files[i];
            fileNames.push(file.name);

            // If it's an image, create preview
            if (file.type.startsWith("image/")) {
              const reader = new FileReader();
              reader.onload = function (e) {
                filePreviews.push(
                  '<img src="' +
                    e.target.result +
                    '" alt="' +
                    file.name +
                    '" style="max-width: 150px; max-height: 150px; border-radius: 4px; margin: 5px;">'
                );
                if (filePreviews.length === $field[0].files.length) {
                  $container.html(
                    filePreviews.join("") +
                      '<div style="margin-top: 8px; color: #666; font-size: 13px;">' +
                      fileNames.join(", ") +
                      "</div>"
                  );
                }
              };
              reader.readAsDataURL(file);
            }
          }

          // If no images, just show file names
          if (filePreviews.length === 0) {
            displayValue = fileNames.join(", ");
          } else {
            // Will be updated by FileReader callbacks
            return; // Exit early, value will be set by callback
          }
        } else {
          // Check if there's a preview in the upload slot (for pictures)
          const $container_parent = $field.closest(
            ".jpm-picture-upload-container, .jpm-file-upload-wrapper"
          );
          if ($container_parent.length > 0) {
            const $preview = $container_parent.find(
              ".jpm-upload-preview img, .jpm-file-upload-preview img"
            );
            if ($preview.length > 0) {
              displayValue = $preview.clone().wrap("<div>").parent().html();
            } else {
              displayValue =
                '<span class="jpm-summary-empty">' + "Not uploaded" + "</span>";
            }
          } else {
            displayValue =
              '<span class="jpm-summary-empty">' + "Not uploaded" + "</span>";
          }
        }
      } else if (fieldType === "checkbox") {
        // Handle checkboxes - find all checkboxes with the same name
        const checkboxName = $field.attr("name");
        if (checkboxName) {
          const checked = $form.find(
            'input[name="' + checkboxName + '"]:checked'
          );
          if (checked.length > 0) {
            const values = checked
              .map(function () {
                // Get label text if available
                const $label = $(this).closest("label");
                if ($label.length > 0) {
                  return $label.text().trim();
                }
                return $(this).val();
              })
              .get();
            displayValue = values.join(", ");
          } else {
            displayValue =
              '<span class="jpm-summary-empty">' + "Not selected" + "</span>";
          }
        } else {
          // Single checkbox
          if ($field.is(":checked")) {
            displayValue = "Yes";
          } else {
            displayValue =
              '<span class="jpm-summary-empty">' + "Not selected" + "</span>";
          }
        }
      } else if (fieldType === "radio") {
        // Handle radio buttons
        const checked = $form.find(
          'input[name="' + $field.attr("name") + '"]:checked'
        );
        if (checked.length > 0) {
          displayValue = checked.val();
        } else {
          displayValue =
            '<span class="jpm-summary-empty">' + "Not selected" + "</span>";
        }
      } else if (fieldType === "select" || $field.is("select")) {
        // Handle select dropdowns
        const selected = $field.find("option:selected");
        if (selected.length > 0 && selected.val() !== "") {
          displayValue = selected.text();
        } else {
          displayValue =
            '<span class="jpm-summary-empty">' + "Not selected" + "</span>";
        }
      } else if (fieldType === "textarea" || $field.is("textarea")) {
        // Handle textareas
        const value = $field.val().trim();
        if (value) {
          displayValue =
            value.length > 100 ? value.substring(0, 100) + "..." : value;
        } else {
          displayValue =
            '<span class="jpm-summary-empty">' + "Not filled" + "</span>";
        }
      } else {
        // Handle text inputs, email, tel, date, number, etc.
        const value = $field.val();
        if (value && value.trim() !== "") {
          displayValue = value;
        } else {
          displayValue =
            '<span class="jpm-summary-empty">' + "Not filled" + "</span>";
        }
      }

      // Update the container
      if (displayValue) {
        $container.html(displayValue);
      } else {
        $container.html(
          '<span class="jpm-summary-empty">' + "Not filled" + "</span>"
        );
      }
    }

    // Next button click
    $nextBtn.on("click", function (e) {
      e.preventDefault();

      // Validate current step before proceeding
      if (validateCurrentStep()) {
        goToStep(currentStep + 1);
      }
    });

    // Previous button click
    $prevBtn.on("click", function (e) {
      e.preventDefault();
      goToStep(currentStep - 1);
    });

    // Stepper navigation click
    $stepperNav.on("click", function () {
      const navStepIndex = parseInt($(this).data("step"));
      // Map nav step (0-based) to form step (1-based)
      // The last nav item is the summary step
      const isSummaryNav = navStepIndex === $stepperNav.length - 1;
      const stepIndex = isSummaryNav ? totalSteps - 1 : navStepIndex + 1;

      if (stepIndex !== undefined && stepIndex !== currentStep) {
        // Allow going back without validation, but validate when going forward
        if (stepIndex < currentStep) {
          goToStep(stepIndex);
        } else {
          // For summary step, validate all previous steps
          if (isSummaryNav || stepIndex === totalSteps - 1) {
            // Validate all steps before showing summary
            let allValid = true;
            for (let i = 1; i < totalSteps - 1; i++) {
              const $stepEl = $steps.filter('[data-step="' + i + '"]');
              if ($stepEl.length > 0) {
                const originalStep = currentStep;
                currentStep = i;
                if (!validateCurrentStep()) {
                  allValid = false;
                  goToStep(i); // Go to the step with errors
                  break;
                }
                currentStep = originalStep;
              }
            }
            if (allValid) {
              goToStep(totalSteps - 1); // Go to summary step
            }
          } else {
            if (validateCurrentStep()) {
              goToStep(stepIndex);
            }
          }
        }
      }
    });

    // Validate current step
    function validateCurrentStep() {
      const $currentStepEl = $steps.filter('[data-step="' + currentStep + '"]');
      const $requiredFields = $currentStepEl.find(
        "input[required], textarea[required], select[required]"
      );
      let isValid = true;

      $requiredFields.each(function () {
        const $field = $(this);
        const value = $field.val();

        if (!value || (typeof value === "string" && value.trim() === "")) {
          $field.addClass("error");
          const $errorSpan = $field
            .closest(".jpm-form-field-group")
            .find(".jpm-field-error");
          if ($errorSpan.length === 0) {
            $field
              .closest(".jpm-form-field-group")
              .append(
                '<span class="jpm-field-error">' +
                  "This field is required." +
                  "</span>"
              );
          } else {
            $errorSpan.text("This field is required.").show();
          }
          isValid = false;
        } else {
          $field.removeClass("error");
          $field
            .closest(".jpm-form-field-group")
            .find(".jpm-field-error")
            .hide();
        }
      });

      if (!isValid) {
        // Scroll to first error
        const $firstError = $currentStepEl
          .find(".jpm-field-error:visible")
          .first();
        if ($firstError.length > 0) {
          $("html, body").animate(
            {
              scrollTop: $firstError.offset().top - 100,
            },
            300
          );
        }
      }

      return isValid;
    }

    // Initialize stepper
    goToStep(currentStep);
  }

  // Initialize stepper on page load
  initStepperForm();

  // File Upload Preview Functionality
  function initFileUploads() {
    // Regular file uploads
    $(document).on(
      "change",
      ".jpm-file-input:not(.jpm-picture-upload-grid .jpm-file-input)",
      function () {
        const $input = $(this);
        const $wrapper = $input.closest(".jpm-file-upload-wrapper");
        const $label = $wrapper.find(".jpm-file-upload-label");
        const $filename = $wrapper.find(".jpm-file-upload-filename");
        const $preview = $wrapper.find(".jpm-file-upload-preview");
        const $removeBtn = $wrapper.find(".jpm-file-upload-remove");
        const file = this.files[0];

        if (file) {
          $filename.text(file.name);
          $removeBtn.show();

          // Show preview for images
          if (file.type.startsWith("image/")) {
            const reader = new FileReader();
            reader.onload = function (e) {
              $preview
                .html('<img src="' + e.target.result + '" alt="Preview">')
                .show();
            };
            reader.readAsDataURL(file);
          } else {
            $preview.hide();
          }
        }
      }
    );

    // Remove regular file upload (not picture grid)
    $(document).on(
      "click",
      ".jpm-file-upload-wrapper .jpm-file-upload-remove",
      function (e) {
        e.preventDefault();
        const $wrapper = $(this).closest(".jpm-file-upload-wrapper");
        const $input = $wrapper.find(".jpm-file-input");
        const $filename = $wrapper.find(".jpm-file-upload-filename");
        const $preview = $wrapper.find(".jpm-file-upload-preview");
        const $removeBtn = $(this);

        $input.val("");
        $filename.text("");
        $preview.hide().empty();
        $removeBtn.hide();
      }
    );

    // Single photo upload (Photo 1 only)
    $(document).on("change", ".jpm-picture-input", function () {
      const $input = $(this);
      const $container = $input.closest(".jpm-picture-upload-container");
      const $slot = $container.find(".jpm-upload-slot");
      const $preview = $slot.find(".jpm-upload-preview");
      const $removeBtn = $slot.find(".jpm-upload-remove");
      const file = this.files[0];

      if (file && file.type.startsWith("image/")) {
        const reader = new FileReader();
        reader.onload = function (e) {
          $preview.html('<img src="' + e.target.result + '" alt="Preview">');
          $slot.addClass("has-image");
          $removeBtn.show();
        };
        reader.readAsDataURL(file);
      } else {
        alert("Please select a valid image file.");
        $input.val("");
      }
    });

    // Remove photo
    $(document).on(
      "click",
      ".jpm-picture-upload-container .jpm-upload-remove",
      function (e) {
        e.preventDefault();
        const $slot = $(this).closest(".jpm-upload-slot");
        const $container = $slot.closest(".jpm-picture-upload-container");
        const $input = $container.find(".jpm-picture-input");
        const $preview = $slot.find(".jpm-upload-preview");
        const $removeBtn = $(this);

        $input.val("");
        $preview.empty();
        $slot.removeClass("has-image");
        $removeBtn.hide();
      }
    );
  }

  // Initialize file uploads
  initFileUploads();

  // Summary step - click to edit functionality (inside stepper form scope)
  function initSummaryClickToEdit() {
    const $form = $("#jpm-application-form");
    if ($form.length === 0) return;

    const $steps = $(".jpm-form-step");
    const totalSteps = $steps.length;

    $(document).on("click", ".jpm-summary-item", function () {
      const $item = $(this);
      const fieldName = $item.data("field-name");
      const fieldId = $item.find(".jpm-summary-value").data("field-id");

      // Skip navigation for read-only fields
      if (
        fieldName === "application_number" ||
        fieldName === "date_of_registration"
      ) {
        return;
      }

      // Find which step contains this field
      let targetStep = 1;
      $steps.each(function (index) {
        const $step = $(this);
        const stepNum = parseInt($step.data("step"));
        if (stepNum > 0 && stepNum < totalSteps) {
          // Check if this step contains the field
          if (
            $step.find("#" + fieldId).length > 0 ||
            $step.find('[name*="' + fieldName + '"]').length > 0
          ) {
            targetStep = stepNum;
            return false; // Break loop
          }
        }
      });

      // Navigate to the step containing this field
      if (targetStep > 0) {
        // Trigger the stepper navigation to go to that step
        const $stepperNav = $(".jpm-stepper-navigation .jpm-stepper-step");
        $stepperNav.each(function (index) {
          const navStepIndex = parseInt($(this).data("step"));
          const isSummaryNav = index === $stepperNav.length - 1;
          const navStepNum = isSummaryNav ? totalSteps - 1 : navStepIndex + 1;

          if (navStepNum === targetStep) {
            $(this).trigger("click");
            return false;
          }
        });

        // Scroll to the field after navigation
        setTimeout(function () {
          const $field = $("#" + fieldId);
          if ($field.length > 0) {
            $("html, body").animate(
              {
                scrollTop: $field.offset().top - 150,
              },
              500
            );
            $field.focus();
          }
        }, 500);
      }
    });
  }

  // Initialize summary click to edit
  initSummaryClickToEdit();

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

  // Clear field errors when user starts typing (bind once on page load)
  $(document).on(
    "input change",
    "#jpm-application-form .jpm-form-field",
    function () {
      var $field = $(this);
      $field.removeClass("error");
      $field
        .closest(".jpm-form-field-group")
        .find(".jpm-field-error")
        .hide()
        .empty();
    }
  );

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
          // Clear previous field errors
          $(".jpm-field-error").hide().empty();
          $(".jpm-form-field").removeClass("error");

          // Handle field-specific errors
          if (response.data && response.data.field_errors) {
            var fieldErrors = response.data.field_errors;
            for (var fieldName in fieldErrors) {
              var $errorSpan = $(
                '.jpm-field-error[data-field-name="' + fieldName + '"]'
              );
              if ($errorSpan.length === 0) {
                // If error span doesn't exist, find the field and add error after it
                var $field = $(
                  'input[name="jpm_fields[' +
                    fieldName +
                    '"], textarea[name="jpm_fields[' +
                    fieldName +
                    '"], select[name="jpm_fields[' +
                    fieldName +
                    '"]'
                );
                if ($field.length > 0) {
                  $field.addClass("error");
                  $field
                    .closest(".jpm-form-field-group")
                    .append(
                      '<span class="jpm-field-error" data-field-name="' +
                        fieldName +
                        '">' +
                        fieldErrors[fieldName] +
                        "</span>"
                    );
                }
              } else {
                $errorSpan.html(fieldErrors[fieldName]).show();
                $errorSpan
                  .closest(".jpm-form-field-group")
                  .find(".jpm-form-field")
                  .addClass("error");
              }
            }

            // Scroll to first error
            var $firstError = $(".jpm-field-error:visible").first();
            if ($firstError.length > 0) {
              $("html, body").animate(
                {
                  scrollTop: $firstError.offset().top - 100,
                },
                500
              );
            }
          }

          // Show toast only for general errors (not field-specific)
          if (
            response.data &&
            response.data.general_errors &&
            response.data.general_errors.length > 0
          ) {
            showToast(response.data.general_errors.join("<br>"), "error");
          } else if (
            !response.data ||
            !response.data.field_errors ||
            Object.keys(response.data.field_errors).length === 0
          ) {
            // Only show toast if there are no field errors (general error)
            showToast(
              response.data.message || "An error occurred. Please try again.",
              "error"
            );
          }

          // Update inline message
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
        var fieldErrors = null;
        var generalErrors = null;

        // Try to get error message from response
        if (xhr.responseJSON) {
          if (xhr.responseJSON.data) {
            if (xhr.responseJSON.data.message) {
              errorMessage = xhr.responseJSON.data.message;
            }
            if (xhr.responseJSON.data.field_errors) {
              fieldErrors = xhr.responseJSON.data.field_errors;
            }
            if (xhr.responseJSON.data.general_errors) {
              generalErrors = xhr.responseJSON.data.general_errors;
            }
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

        // Clear previous field errors
        $(".jpm-field-error").hide().empty();
        $(".jpm-form-field").removeClass("error");

        // Handle field-specific errors
        if (fieldErrors) {
          for (var fieldName in fieldErrors) {
            var $errorSpan = $(
              '.jpm-field-error[data-field-name="' + fieldName + '"]'
            );
            if ($errorSpan.length === 0) {
              var $field = $(
                'input[name="jpm_fields[' +
                  fieldName +
                  '"], textarea[name="jpm_fields[' +
                  fieldName +
                  '"], select[name="jpm_fields[' +
                  fieldName +
                  '"]'
              );
              if ($field.length > 0) {
                $field.addClass("error");
                $field
                  .closest(".jpm-form-field-group")
                  .append(
                    '<span class="jpm-field-error" data-field-name="' +
                      fieldName +
                      '">' +
                      fieldErrors[fieldName] +
                      "</span>"
                  );
              }
            } else {
              $errorSpan.html(fieldErrors[fieldName]).show();
              $errorSpan
                .closest(".jpm-form-field-group")
                .find(".jpm-form-field")
                .addClass("error");
            }
          }

          // Scroll to first error
          var $firstError = $(".jpm-field-error:visible").first();
          if ($firstError.length > 0) {
            $("html, body").animate(
              {
                scrollTop: $firstError.offset().top - 100,
              },
              500
            );
          }
        }

        // Show toast only for general errors (not field-specific)
        if (generalErrors && generalErrors.length > 0) {
          showToast(generalErrors.join("<br>"), "error");
        } else if (!fieldErrors || Object.keys(fieldErrors).length === 0) {
          showToast(errorMessage, "error");
        }

        // Log error for debugging
        console.error("AJAX Error:", {
          status: xhr.status,
          statusText: xhr.statusText,
          response: xhr.responseJSON || xhr.responseText,
          error: error,
        });

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

  // Latest Jobs Modal Functionality
  // Cache for job details
  const jobDetailsCache = {};

  // Open modal on Quick View button click
  $(document).on("click", ".jpm-btn-quick-view", function (e) {
    e.preventDefault();
    const jobId = $(this).data("job-id");

    if (!jobId) {
      return;
    }

    const $modal = $("#jpm-job-modal");
    const $modalBody = $modal.find(".jpm-modal-body");
    const $loading = $modalBody.find(".jpm-modal-loading");
    const $content = $modalBody.find(".jpm-modal-job-content");

    // Show modal
    $modal.addClass("active");
    $("body").css("overflow", "hidden");

    // Check if job details are cached
    if (jobDetailsCache[jobId]) {
      // Use cached data
      $loading.hide();
      $content.html(jobDetailsCache[jobId]).fadeIn();
      return;
    }

    // Show loading, hide content
    $loading.show();
    $content.hide().empty();

    // Fetch job details via AJAX
    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: {
        action: "jpm_get_job_details",
        job_id: jobId,
        nonce: jpm_ajax.nonce,
      },
      success: function (response) {
        $loading.hide();

        if (response.success && response.data.html) {
          // Cache the job details
          jobDetailsCache[jobId] = response.data.html;
          $content.html(response.data.html).fadeIn();
        } else {
          const errorHtml =
            '<p class="jpm-error">' +
            (response.data?.message || "Failed to load job details.") +
            "</p>";
          $content.html(errorHtml).fadeIn();
        }
      },
      error: function () {
        $loading.hide();
        const errorHtml =
          '<p class="jpm-error">An error occurred while loading job details. Please try again.</p>';
        $content.html(errorHtml).fadeIn();
      },
    });
  });

  // Close modal
  function closeModal() {
    const $modal = $("#jpm-job-modal");
    $modal.removeClass("active");
    $("body").css("overflow", "");
    $modal.find(".jpm-modal-job-content").empty();
  }

  // Close on close button click
  $(document).on("click", ".jpm-modal-close", closeModal);

  // Close on overlay click
  $(document).on("click", ".jpm-modal-overlay", closeModal);

  // Close on ESC key
  $(document).on("keydown", function (e) {
    if (e.key === "Escape" && $("#jpm-job-modal").hasClass("active")) {
      closeModal();
    }
  });

  // Real-time AJAX search with debounce for all_jobs shortcode
  let searchTimeout;
  const $searchInput = $("#jpm_search");
  const $filterForm = $(".jpm-filter-form");
  const $jobsGrid = $(".jpm-latest-jobs");
  const $resultsCount = $(".jpm-jobs-results-count");
  const $pagination = $(".jpm-jobs-pagination");
  const $noJobs = $(".jpm-no-jobs");

  // Debounced search function
  function performSearch(resetPage = false) {
    const searchTerm = $searchInput.val();
    const locationFilter = $("#jpm_location").val() || "";
    const companyFilter = $("#jpm_company").val() || "";
    const currentPage = resetPage ? 1 : getCurrentPage();

    // Show loading state
    $jobsGrid.html(
      '<div class="jpm-loading" style="text-align: center; padding: 40px;"><span class="spinner is-active"></span><p>Searching jobs...</p></div>'
    );

    // Perform AJAX search
    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: {
        action: "jpm_filter_jobs",
        search: searchTerm,
        location: locationFilter,
        company: companyFilter,
        per_page: 12, // Default per page
        paged: currentPage,
        nonce: jpm_ajax.nonce,
      },
      success: function (response) {
        // Hide search indicator
        $(".jpm-search-indicator").hide();

        if (response.success) {
          // Update jobs grid
          if (response.data.html) {
            $jobsGrid.html(response.data.html);
          } else {
            $jobsGrid.html(
              '<div class="jpm-no-jobs"><p>No jobs found matching your criteria.</p></div>'
            );
          }

          // Update results count
          const total = response.data.total || 0;
          const perPage = 12;
          const currentPageNum = currentPage;
          const start = (currentPageNum - 1) * perPage + 1;
          const end = Math.min(currentPageNum * perPage, total);

          if (total > 0) {
            $resultsCount.html(
              "<p>Showing " + start + "-" + end + " of " + total + " jobs</p>"
            );
          } else {
            $resultsCount.html("<p>No jobs found.</p>");
          }

          // Update pagination (if needed, you can generate pagination HTML here)
          // For now, we'll keep the existing pagination structure
          updatePagination(response.data.pages || 1, currentPageNum);

          // Update URL without reload
          updateURL(searchTerm, locationFilter, companyFilter, currentPageNum);
        } else {
          $jobsGrid.html(
            '<div class="jpm-no-jobs"><p>' +
              (response.data?.message ||
                "An error occurred. Please try again.") +
              "</p></div>"
          );
        }
      },
      error: function () {
        // Hide search indicator
        $(".jpm-search-indicator").hide();
        $jobsGrid.html(
          '<div class="jpm-no-jobs"><p>An error occurred while searching. Please try again.</p></div>'
        );
      },
    });
  }

  // Get current page from URL or default to 1
  function getCurrentPage() {
    const urlParams = new URLSearchParams(window.location.search);
    return parseInt(urlParams.get("jpm_page")) || 1;
  }

  // Update URL without reload
  function updateURL(search, location, company, page) {
    const url = new URL(window.location.href);
    url.searchParams.delete("jpm_search");
    url.searchParams.delete("jpm_location");
    url.searchParams.delete("jpm_company");
    url.searchParams.delete("jpm_page");

    if (search) {
      url.searchParams.set("jpm_search", search);
    }
    if (location) {
      url.searchParams.set("jpm_location", location);
    }
    if (company) {
      url.searchParams.set("jpm_company", company);
    }
    if (page > 1) {
      url.searchParams.set("jpm_page", page);
    }

    window.history.pushState({}, "", url);
  }

  // Update pagination HTML
  function updatePagination(totalPages, currentPage) {
    if (totalPages <= 1) {
      $pagination.html("");
      return;
    }

    let paginationHTML = '<ul class="page-numbers">';

    // Previous button
    if (currentPage > 1) {
      paginationHTML +=
        '<li><a href="?jpm_page=' +
        (currentPage - 1) +
        getFilterParams() +
        '" class="prev page-numbers">&laquo; Previous</a></li>';
    }

    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
      if (
        i === 1 ||
        i === totalPages ||
        (i >= currentPage - 1 && i <= currentPage + 1)
      ) {
        if (i === currentPage) {
          paginationHTML +=
            '<li><span class="page-numbers current">' + i + "</span></li>";
        } else {
          paginationHTML +=
            '<li><a href="?jpm_page=' +
            i +
            getFilterParams() +
            '" class="page-numbers">' +
            i +
            "</a></li>";
        }
      } else if (i === currentPage - 2 || i === currentPage + 2) {
        paginationHTML += '<li><span class="page-numbers dots">…</span></li>';
      }
    }

    // Next button
    if (currentPage < totalPages) {
      paginationHTML +=
        '<li><a href="?jpm_page=' +
        (currentPage + 1) +
        getFilterParams() +
        '" class="next page-numbers">Next &raquo;</a></li>';
    }

    paginationHTML += "</ul>";
    $pagination.html(paginationHTML);
  }

  // Get filter parameters for pagination links
  function getFilterParams() {
    const search = $searchInput.val() || "";
    const location = $("#jpm_location").val() || "";
    const company = $("#jpm_company").val() || "";
    let params = "";

    if (search) {
      params += "&jpm_search=" + encodeURIComponent(search);
    }
    if (location) {
      params += "&jpm_location=" + encodeURIComponent(location);
    }
    if (company) {
      params += "&jpm_company=" + encodeURIComponent(company);
    }

    return params;
  }

  // Real-time search with debounce (only for search input)
  if ($searchInput.length) {
    $searchInput.on("input", function () {
      clearTimeout(searchTimeout);
      const searchValue = $(this).val();
      const $indicator = $(".jpm-search-indicator");

      // Show indicator when typing
      if (searchValue.length > 0) {
        $indicator.show().text("Type to search...");
      } else {
        $indicator.hide();
      }

      // Debounce: wait 500ms after user stops typing
      searchTimeout = setTimeout(function () {
        $indicator.text("Searching...");
        performSearch(true); // Reset to page 1 when searching
      }, 500);
    });

    // Hide indicator when search is complete
    $searchInput.on("blur", function () {
      setTimeout(function () {
        $(".jpm-search-indicator").hide();
      }, 1000);
    });
  }

  // Filter button click (for location and company filters)
  if ($filterForm.length) {
    $filterForm.on("submit", function (e) {
      e.preventDefault();
      clearTimeout(searchTimeout); // Clear any pending search
      performSearch(true); // Reset to page 1 when filtering
    });
  }

  // Reset button
  $(document).on("click", ".jpm-btn-reset", function (e) {
    e.preventDefault();
    clearTimeout(searchTimeout);
    $searchInput.val("");
    $("#jpm_location").val("");
    $("#jpm_company").val("");
    window.location.href = window.location.pathname;
  });

  // Handle pagination clicks (prevent default and use AJAX)
  $(document).on("click", ".jpm-jobs-pagination .page-numbers", function (e) {
    if ($(this).hasClass("current") || $(this).hasClass("dots")) {
      e.preventDefault();
      return;
    }

    e.preventDefault();
    const href = $(this).attr("href");
    if (href) {
      const url = new URL(href, window.location.origin);
      const page = url.searchParams.get("jpm_page") || 1;
      const search = url.searchParams.get("jpm_search") || "";
      const location = url.searchParams.get("jpm_location") || "";
      const company = url.searchParams.get("jpm_company") || "";

      // Update form values
      $searchInput.val(search);
      $("#jpm_location").val(location);
      $("#jpm_company").val(company);

      // Perform search with new page
      $jobsGrid.html(
        '<div class="jpm-loading" style="text-align: center; padding: 40px;"><span class="spinner is-active"></span><p>Loading jobs...</p></div>'
      );

      $.ajax({
        url: jpm_ajax.ajax_url,
        type: "POST",
        data: {
          action: "jpm_filter_jobs",
          search: search,
          location: location,
          company: company,
          per_page: 12,
          paged: page,
          nonce: jpm_ajax.nonce,
        },
        success: function (response) {
          if (response.success) {
            if (response.data.html) {
              $jobsGrid.html(response.data.html);
            } else {
              $jobsGrid.html(
                '<div class="jpm-no-jobs"><p>No jobs found matching your criteria.</p></div>'
              );
            }

            const total = response.data.total || 0;
            const perPage = 12;
            const start = (page - 1) * perPage + 1;
            const end = Math.min(page * perPage, total);

            if (total > 0) {
              $resultsCount.html(
                "<p>Showing " + start + "-" + end + " of " + total + " jobs</p>"
              );
            } else {
              $resultsCount.html("<p>No jobs found.</p>");
            }

            updatePagination(response.data.pages || 1, parseInt(page));
            updateURL(search, location, company, parseInt(page));

            // Scroll to top of jobs grid
            $("html, body").animate(
              {
                scrollTop: $jobsGrid.offset().top - 100,
              },
              500
            );
          }
        },
        error: function () {
          $jobsGrid.html(
            '<div class="jpm-no-jobs"><p>An error occurred. Please try again.</p></div>'
          );
        },
      });
    }
  });

  // Application Tracker AJAX
  $("#jpm-tracker-form").on("submit", function (e) {
    e.preventDefault();

    const $form = $(this);
    const $errorDiv = $("#jpm-tracker-error");
    const $resultsDiv = $("#jpm-tracker-results");
    const $submitBtn = $form.find("button[type='submit']");
    const $btnText = $submitBtn.find(".jpm-btn-text");
    const applicationNumber = $("#application_number").val().trim();

    // Hide previous results and errors
    $errorDiv.hide().empty();
    $resultsDiv.hide().empty();

    // Validate input
    if (!applicationNumber) {
      $errorDiv
        .html("<p>" + "Please enter an application number." + "</p>")
        .show();
      return;
    }

    // Store original text
    const originalText = $btnText.text();

    // Show loading state
    $submitBtn.prop("disabled", true);
    $btnText.text("Tracking...");

    // Make AJAX request
    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: {
        action: "jpm_track_application",
        application_number: applicationNumber,
        nonce: jpm_ajax.nonce,
      },
      success: function (response) {
        $submitBtn.prop("disabled", false);
        $btnText.text(originalText);

        if (response.success && response.data.html) {
          $resultsDiv.html(response.data.html).fadeIn();
          // Scroll to results
          $("html, body").animate(
            {
              scrollTop: $resultsDiv.offset().top - 100,
            },
            500
          );
        } else {
          const errorMsg =
            response.data?.message || "Failed to load application details.";
          $errorDiv.html("<p>" + errorMsg + "</p>").show();
        }
      },
      error: function () {
        $submitBtn.prop("disabled", false);
        $btnText.text(originalText);
        $errorDiv
          .html(
            "<p>An error occurred while loading application details. Please try again.</p>"
          )
          .show();
      },
    });
  });
});
