/**
 * Form Builder Persistence Layer
 * Handles saving and updating form fields data
 */
(function ($) {
  "use strict";

  window.JPMFormBuilderPersistence = {
    updateTimeout: null,

    /**
     * Initialize persistence handlers
     */
    init: function () {
      this.bindEvents();
    },

    /**
     * Bind persistence-related events
     */
    bindEvents: function () {
      var self = this;

      // Update form fields before form submission (publish/update)
      $("#post").on("submit", function (e) {
        // Update fields synchronously before form submits
        self.updateFormFields();

        // Ensure the hidden field is in the DOM and has data
        var $hiddenField = $("#jpm-form-fields-json");
        if ($hiddenField.length === 0) {
          console.error("Form fields JSON input not found!");
          // Try to create it if it doesn't exist
          var $formBuilder = $("#jpm-form-fields-container").closest(
            ".jpm-form-builder"
          );
          if ($formBuilder.length > 0) {
            $formBuilder.append(
              '<input type="hidden" name="jpm_form_fields_json" id="jpm-form-fields-json" value="">'
            );
            self.updateFormFields();
          }
        } else {
          var jsonValue = $hiddenField.val();
          if (!jsonValue || jsonValue === "[]" || jsonValue === "") {
            console.warn("Form fields JSON is empty! Attempting to update...");
            self.updateFormFields();
            // Double check after update
            jsonValue = $hiddenField.val();
            if (!jsonValue || jsonValue === "[]" || jsonValue === "") {
              console.error("Form fields JSON is still empty after update!");
            }
          }
        }
      });

      // Also update on publish/update button clicks (before submit) - use mousedown to catch it early
      $(document).on(
        "mousedown",
        "#publish, #save-post, #save, input[name='save']",
        function () {
          self.updateFormFields();
        }
      );

      // Also update on click as backup
      $(document).on(
        "click",
        "#publish, #save-post, #save, input[name='save']",
        function () {
          self.updateFormFields();
        }
      );

      // Update on autosave heartbeat
      if (typeof wp !== "undefined" && wp.heartbeat) {
        $(document).on("heartbeat-send", function () {
          self.updateFormFields();
        });
      }

      // Update on any field property change with a debounce
      $(document).on(
        "input change",
        ".jpm-field-label, .jpm-field-name, .jpm-field-placeholder, .jpm-field-options, .jpm-field-description, .jpm-field-required, .jpm-field-type",
        function () {
          clearTimeout(self.updateTimeout);
          self.updateTimeout = setTimeout(function () {
            self.updateFormFields();
          }, 300);
        }
      );

      // Update on any field property changes (general handler)
      $(document).on(
        "change input",
        ".jpm-field-editor input, .jpm-field-editor select, .jpm-field-editor textarea",
        function () {
          self.updateFormFields();
        }
      );
    },

    /**
     * Update form fields JSON
     */
    updateFormFields: function () {
      // Check if we're on a form builder page
      if ($("#jpm-form-fields-container").length === 0) {
        // Not on a form builder page, skip silently
        return;
      }

      var fields = [];
      $(".jpm-form-row").each(function () {
        var $row = $(this);
        $row.find(".jpm-field-editor").each(function () {
          var $field = $(this);
          var columnWidth =
            $field.find(".jpm-field-column-width").val() || "12";
          var field = {
            type: $field.find(".jpm-field-type").val() || "text",
            label: $field.find(".jpm-field-label").val() || "",
            name: $field.find(".jpm-field-name").val() || "",
            required: $field.find(".jpm-field-required").is(":checked"),
            placeholder: $field.find(".jpm-field-placeholder").val() || "",
            options: $field.find(".jpm-field-options").val() || "",
            description: $field.find(".jpm-field-description").val() || "",
            column_width: columnWidth,
          };
          fields.push(field);
        });
      });
      var jsonData = JSON.stringify(fields);
      var $hiddenField = $("#jpm-form-fields-json");
      if ($hiddenField.length > 0) {
        $hiddenField.val(jsonData);
        // Trigger multiple events to ensure form knows the value changed
        $hiddenField.trigger("change").trigger("input");
      } else {
        console.error(
          "Form fields JSON input not found! Cannot save form fields."
        );
        // Try to find it again after a short delay
        var self = this;
        setTimeout(function () {
          var $retryField = $("#jpm-form-fields-json");
          if ($retryField.length > 0) {
            $retryField.val(jsonData);
            $retryField.trigger("change").trigger("input");
          } else {
            // Last resort: try to create the field
            var $formBuilder = $("#jpm-form-fields-container").closest(
              ".jpm-form-builder"
            );
            if ($formBuilder.length > 0) {
              var $newField = $(
                '<input type="hidden" name="jpm_form_fields_json" id="jpm-form-fields-json" value="">'
              );
              $formBuilder.append($newField);
              $newField.val(jsonData);
              $newField.trigger("change").trigger("input");
            }
          }
        }, 100);
      }
    },
  };
})(jQuery);
