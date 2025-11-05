// Handle bulk actions
jQuery("#jpm-bulk-form").on("submit", function (e) {
  e.preventDefault();
  // AJAX call similar to above
});

// Form Builder JavaScript
jQuery(document).ready(function ($) {
  var fieldIndex = $(".jpm-field-editor").length;

  // Show field type selection
  $("#jpm-add-field-btn").on("click", function () {
    $("#jpm-field-types").slideToggle();
  });

  // Add field when type is selected
  $(".jpm-field-type-btn").on("click", function () {
    var fieldType = $(this).data("type");
    addField(fieldType);
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
      $(this)
        .closest(".jpm-field-editor")
        .fadeOut(300, function () {
          $(this).remove();
          updateFormFields();
        });
    }
  });

  // Update field title when label changes
  $(document).on("input", ".jpm-field-label", function () {
    var label = $(this).val() || "Untitled Field";
    $(this).closest(".jpm-field-editor").find(".jpm-field-title").text(label);
    updateFormFields();
  });

  // Update column badge when column width changes
  $(document).on("change", ".jpm-field-column-width", function () {
    var colWidth = $(this).val();
    var colText = colWidth == "12" ? "Full" : colWidth + " cols";
    $(this)
      .closest(".jpm-field-editor")
      .find(".jpm-field-column-badge")
      .text(colText);
    updateFormFields();
  });

  // Update field name when label changes (auto-generate)
  $(document).on("input", ".jpm-field-label", function () {
    var label = $(this).val();
    var nameField = $(this)
      .closest(".jpm-field-editor")
      .find(".jpm-field-name");
    if (!nameField.val() || nameField.data("auto-generated")) {
      var name = label
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "_")
        .replace(/^_+|_+$/g, "");
      nameField.val(name).data("auto-generated", true);
    }
    updateFormFields();
  });

  // Update form fields when any field property changes
  $(document).on(
    "change input",
    ".jpm-field-editor input, .jpm-field-editor select, .jpm-field-editor textarea",
    function () {
      updateFormFields();
    }
  );

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
    updateFormFields();
  });

  // Add new field
  function addField(fieldType) {
    $.ajax({
      url: jpm_ajax.ajax_url,
      type: "POST",
      data: {
        action: "jpm_add_field",
        field_type: fieldType,
        index: fieldIndex,
        nonce: jpm_ajax.nonce,
      },
      success: function (response) {
        if (response.success) {
          $("#jpm-form-fields-container").append(response.data.html);
          fieldIndex++;
          updateFormFields();
        }
      },
    });
  }

  // Update form fields JSON
  function updateFormFields() {
    var fields = [];
    $(".jpm-field-editor").each(function () {
      var $field = $(this);
      var field = {
        type: $field.find(".jpm-field-type").val() || "text",
        label: $field.find(".jpm-field-label").val() || "",
        name: $field.find(".jpm-field-name").val() || "",
        required: $field.find(".jpm-field-required").is(":checked"),
        placeholder: $field.find(".jpm-field-placeholder").val() || "",
        options: $field.find(".jpm-field-options").val() || "",
        description: $field.find(".jpm-field-description").val() || "",
        column_width: $field.find(".jpm-field-column-width").val() || "12",
      };
      fields.push(field);
    });
    $("#jpm-form-fields-json").val(JSON.stringify(fields));
  }

  // Initialize sortable (if jQuery UI is available)
  if ($.fn.sortable) {
    $("#jpm-form-fields-container").sortable({
      handle: ".jpm-field-handle",
      axis: "y",
      update: function () {
        updateFormFields();
      },
    });
  }

  // Initialize form fields on page load
  updateFormFields();
});
