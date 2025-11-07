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
      var $fieldEditor = $(this).closest(".jpm-field-editor");
      var $row = $fieldEditor.closest(".jpm-form-row");

      $fieldEditor.fadeOut(300, function () {
        $fieldEditor.remove();
        // Remove row if empty
        if ($row.find(".jpm-field-editor").length === 0) {
          $row.remove();
        }
        reorganizeRows();
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
          // Create new row for each field
          var rowIndex = $(".jpm-form-row").length;
          var $newRow = $(
            "<div class='jpm-form-row' data-row-index='" + rowIndex + "'></div>"
          );
          $newRow.html(response.data.html);
          $(".jpm-form-rows").append($newRow);

          fieldIndex++;
          reorganizeRows();
          updateFormFields();
          initializeSortable();
        }
      },
    });
  }

  // Reorganize rows and update column widths
  function reorganizeRows() {
    $(".jpm-form-row").each(function () {
      var $row = $(this);
      var $fieldEditor = $row.find(".jpm-field-editor");

      if ($fieldEditor.length > 0) {
        // Set column width to 12 (full width) for all fields
        $fieldEditor.find(".jpm-field-column-width").val(12);
        $fieldEditor.find(".jpm-field-column-badge").text("Full");
      }
    });
  }

  // Initialize sortable for drag-and-drop
  function initializeSortable() {
    if (!$.fn.draggable || !$.fn.droppable) return;

    // Destroy existing draggable/droppable instances safely
    $(".jpm-field-editor").each(function () {
      if ($(this).data("ui-draggable")) {
        $(this).draggable("destroy");
      }
    });

    $(".jpm-form-row").each(function () {
      if ($(this).data("ui-droppable")) {
        $(this).droppable("destroy");
      }
    });

    // Make each field individually draggable
    $(".jpm-field-editor").each(function () {
      var $field = $(this);

      $field.draggable({
        handle: ".jpm-field-header",
        cursor: "move",
        revert: "invalid",
        helper: "clone",
        opacity: 0.7,
        zIndex: 1000,
        scroll: false,
        appendTo: "body",
        start: function (event, ui) {
          // Store original position and make original semi-transparent
          $field.data("original-parent", $field.parent());
          $field.css("opacity", "0.3");
          // Mark helper as not dropped yet
          ui.helper.data("dropped", false);
        },
        stop: function (event, ui) {
          // Restore opacity if drop was invalid (reverted)
          if (!ui.helper || !ui.helper.data("dropped")) {
            $field.css("opacity", "1");
          }
        },
      });
    });

    // Make rows droppable - initialize each individually
    $(".jpm-form-row").each(function () {
      var $target = $(this);
      $target.droppable({
        accept: ".jpm-field-editor",
        hoverClass: "jpm-drop-hover",
        tolerance: "pointer",
        greedy: false,
        activeClass: "jpm-drop-active",
        over: function (event, ui) {
          // Visual feedback when hovering
          $(this).addClass("jpm-drop-hover");
        },
        out: function (event, ui) {
          // Remove hover class when leaving
          $(this).removeClass("jpm-drop-hover");
        },
        drop: function (event, ui) {
          var $draggedField = ui.draggable;
          var $target = $(this);
          var $targetRow = $target;
          var $sourceRow = $draggedField.closest(".jpm-form-row");

          // Prevent default behavior and stop propagation
          event.stopPropagation();
          event.preventDefault();

          // Mark helper as dropped to prevent revert
          if (ui.helper) {
            ui.helper.data("dropped", true);
          }

          // Store field index for later removal
          var fieldIndex = $draggedField.attr("data-index");

          // If dropped on the same row, do nothing
          if ($sourceRow.is($targetRow)) {
            $draggedField.css("opacity", "1");
            return;
          }

          // Find and remove the original field from source row
          if ($sourceRow.length > 0 && fieldIndex) {
            var $originalField = $sourceRow.find(
              ".jpm-field-editor[data-index='" + fieldIndex + "']"
            );
            if ($originalField.length > 0) {
              $originalField.remove();
            }
          }

          // If target row has a field, swap them
          var $existingField = $targetRow.find(".jpm-field-editor");
          if ($existingField.length > 0 && !$existingField.is($draggedField)) {
            // Move existing field to source row
            if ($sourceRow.length > 0) {
              $existingField.appendTo($sourceRow);
            }
          }

          // Move dragged field to target row
          $draggedField.css("opacity", "1");
          $draggedField.appendTo($targetRow);

          // Clean up empty source row
          if ($sourceRow.length > 0 && !$sourceRow.is($targetRow)) {
            var $remainingFields = $sourceRow.find(".jpm-field-editor");
            if ($remainingFields.length === 0) {
              $sourceRow.remove();
            }
          }

          reorganizeRows();
          updateFormFields();

          // Reinitialize after DOM changes with a small delay
          setTimeout(function () {
            initializeSortable();
          }, 100);
        },
      });
    });
  }

  // Update form fields JSON
  function updateFormFields() {
    var fields = [];
    $(".jpm-form-row").each(function () {
      var $row = $(this);
      $row.find(".jpm-field-editor").each(function () {
        var $field = $(this);
        var field = {
          type: $field.find(".jpm-field-type").val() || "text",
          label: $field.find(".jpm-field-label").val() || "",
          name: $field.find(".jpm-field-name").val() || "",
          required: $field.find(".jpm-field-required").is(":checked"),
          placeholder: $field.find(".jpm-field-placeholder").val() || "",
          options: $field.find(".jpm-field-options").val() || "",
          description: $field.find(".jpm-field-description").val() || "",
          column_width: "12", // Always full width
        };
        fields.push(field);
      });
    });
    $("#jpm-form-fields-json").val(JSON.stringify(fields));
  }

  // Initialize on page load
  reorganizeRows();
  initializeSortable();
  updateFormFields();

  // Reorganize after a short delay to ensure DOM is ready
  setTimeout(function () {
    reorganizeRows();
    initializeSortable();
  }, 100);
});
