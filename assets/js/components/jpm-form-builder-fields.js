/**
 * Form Builder Fields Management
 * Handles adding, removing, toggling, and updating fields
 */
(function ($) {
  "use strict";

  window.JPMFormBuilderFields = {
    fieldIndex: 0,

    /**
     * Initialize field management
     */
    init: function () {
      this.fieldIndex = $(".jpm-field-editor").length;
      this.bindEvents();
    },

    /**
     * Bind field-related events
     */
    bindEvents: function () {
      var self = this;

      // Show field type selection
      $("#jpm-add-field-btn").on("click", function () {
        $("#jpm-field-types").slideToggle();
      });

      // Add field when type is selected
      $(".jpm-field-type-btn").on("click", function () {
        var fieldType = $(this).data("type");
        self.addField(fieldType);
        $("#jpm-field-types").slideUp();
      });

      // Toggle field editor
      $(document).on("click", ".jpm-field-toggle", function () {
        var content = $(this)
          .closest(".jpm-field-editor")
          .find(".jpm-field-content");
        content.slideToggle();
        $(this)
          .find(".dashicons")
          .toggleClass("dashicons-arrow-down dashicons-arrow-up");
      });

      // Remove field
      $(document).on("click", ".jpm-field-remove", function () {
        if (confirm("Are you sure you want to remove this field?")) {
          self.removeField($(this).closest(".jpm-field-editor"));
        }
      });

      // Update field title when label changes
      $(document).on("input", ".jpm-field-label", function () {
        var label = $(this).val() || "Untitled Field";
        $(this)
          .closest(".jpm-field-editor")
          .find(".jpm-field-title")
          .text(label);
        if (window.JPMFormBuilderPersistence) {
          window.JPMFormBuilderPersistence.updateFormFields();
        }
      });

      // Auto-generate field name from label
      $(document).on("input", ".jpm-field-label", function () {
        var label = $(this).val();
        var nameField = $(this)
          .closest(".jpm-field-editor")
          .find(".jpm-field-name");
        if (!nameField.val() || nameField.data("auto-generated")) {
          var name = window.JPMFormBuilderUtils
            ? window.JPMFormBuilderUtils.sanitizeFieldName(label)
            : label
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, "_")
                .replace(/^_+|_+$/g, "");
          nameField.val(name).data("auto-generated", true);
        }
        if (window.JPMFormBuilderPersistence) {
          window.JPMFormBuilderPersistence.updateFormFields();
        }
      });

      // Show/hide options field based on field type
      $(document).on("change", ".jpm-field-type", function () {
        var fieldType = $(this).val();
        var optionsRow = $(this)
          .closest(".jpm-field-editor")
          .find(".jpm-field-options-row");
        if (["select", "radio", "checkbox"].includes(fieldType)) {
          optionsRow.slideDown();
        } else {
          optionsRow.slideUp();
        }
        if (window.JPMFormBuilderPersistence) {
          window.JPMFormBuilderPersistence.updateFormFields();
        }
      });
    },

    /**
     * Add new field via AJAX
     * @param {string} fieldType - Type of field to add
     */
    addField: function (fieldType) {
      var self = this;
      $.ajax({
        url: jpm_ajax.ajax_url,
        type: "POST",
        data: {
          action: "jpm_add_field",
          field_type: fieldType,
          index: this.fieldIndex,
          nonce: jpm_ajax.nonce,
        },
        success: function (response) {
          if (response.success) {
            // Create new row for each field
            var rowIndex = $(".jpm-form-row").length;
            var $newRow = $(
              "<div class='jpm-form-row' data-row-index='" +
                rowIndex +
                "'></div>"
            );
            $newRow.html(response.data.html);
            $(".jpm-form-rows").append($newRow);

            self.fieldIndex++;
            if (window.JPMFormBuilderLayout) {
              window.JPMFormBuilderLayout.reorganizeRows();
            }
            if (window.JPMFormBuilderPersistence) {
              window.JPMFormBuilderPersistence.updateFormFields();
            }
            if (window.JPMFormBuilderDragDrop) {
              window.JPMFormBuilderDragDrop.initializeSortable();
            }
          }
        },
      });
    },

    /**
     * Remove field and clean up layout
     * @param {jQuery} $fieldEditor - Field editor element to remove
     */
    removeField: function ($fieldEditor) {
      var self = this;
      var $row = $fieldEditor.closest(".jpm-form-row");
      var $column = $fieldEditor.closest(".jpm-form-column");

      $fieldEditor.fadeOut(300, function () {
        $fieldEditor.remove();

        // Remove column wrapper if it exists
        if ($column.length > 0) {
          $column.remove();
        }

        // Check remaining fields in row
        var $remainingFields = $row.find(".jpm-field-editor");
        var $remainingColumns = $row.find(".jpm-form-column");

        if ($remainingFields.length === 0) {
          // No fields left, remove row
          $row.remove();
        } else if ($remainingFields.length === 1) {
          // Only one field left - remove columns and make it full width
          var $singleField = $remainingFields.first();
          var $singleColumn = $singleField.parent();

          if ($singleColumn.hasClass("jpm-form-column")) {
            $singleField.unwrap();
            $row.removeClass("jpm-row-has-columns");
          }

          // Set to full width
          $singleField.find(".jpm-field-column-width").val(12);
          $singleField.find(".jpm-field-column-badge").text("Full");
        } else if ($remainingColumns.length > 0) {
          // Multiple fields remain - recalculate column widths
          // This will be handled by reorganizeRows()
        } else {
          // Fields exist but no columns - make them full width
          $remainingFields.each(function () {
            var $field = $(this);
            $field.find(".jpm-field-column-width").val(12);
            $field.find(".jpm-field-column-badge").text("Full");
          });
        }

        if (window.JPMFormBuilderLayout) {
          window.JPMFormBuilderLayout.reorganizeRows();
        }
        if (window.JPMFormBuilderPersistence) {
          window.JPMFormBuilderPersistence.updateFormFields();
        }
        if (window.JPMFormBuilderDragDrop) {
          window.JPMFormBuilderDragDrop.initializeSortable();
        }
      });
    },
  };
})(jQuery);
