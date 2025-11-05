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
      var $column = $fieldEditor.closest(".jpm-form-column");
      var $row = $column.closest(".jpm-form-row");

      $fieldEditor.fadeOut(300, function () {
        $column.remove();
        // Remove row if empty
        if ($row.find(".jpm-form-column").length === 0) {
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
          // Find the first row with space (less than 3 columns)
          var $firstRowWithSpace = $(".jpm-form-row").first();
          if ($firstRowWithSpace.length === 0) {
            // Create new row if none exists
            $firstRowWithSpace = $(
              "<div class='jpm-form-row' data-row-index='0'></div>"
            );
            $(".jpm-form-rows").append($firstRowWithSpace);
          }

          // Check if row has space (less than 3 columns)
          var fieldCount = $firstRowWithSpace.find(".jpm-form-column").length;
          if (fieldCount >= 3) {
            // Create new row
            var rowIndex = $(".jpm-form-row").length;
            $firstRowWithSpace = $(
              "<div class='jpm-form-row' data-row-index='" +
                rowIndex +
                "'></div>"
            );
            $(".jpm-form-rows").append($firstRowWithSpace);
          }

          // Create column and add field
          var $column = $(
            "<div class='jpm-form-column' data-col-index='0'></div>"
          );
          $column.html(response.data.html);
          $firstRowWithSpace.append($column);

          // Remove empty drop zones and ensure max 3 columns
          $firstRowWithSpace.find(".jpm-column-drop-zone").remove();
          var colCount = $firstRowWithSpace.find(".jpm-form-column").length;
          for (var i = colCount; i < 3; i++) {
            $firstRowWithSpace.append(
              $(
                "<div class='jpm-column-drop-zone' data-col-index='" +
                  i +
                  "'></div>"
              )
            );
          }

          fieldIndex++;
          reorganizeRows();
          updateFormFields();
          initializeSortable();
        }
      },
    });
  }

  // Calculate column width based on number of fields in row
  function calculateColumnWidth(fieldCount) {
    if (fieldCount === 1) return 12;
    if (fieldCount === 2) return 6;
    if (fieldCount === 3) return 4;
    return 12; // Default to full width
  }

  // Reorganize rows and update column widths
  function reorganizeRows() {
    $(".jpm-form-row").each(function () {
      var $row = $(this);
      var columns = $row.find(".jpm-form-column");
      var fieldCount = columns.length;

      if (fieldCount === 0) {
        // Empty row - add drop zones
        $row.find(".jpm-column-drop-zone").remove();
        for (var i = 0; i < 3; i++) {
          $row.append(
            $(
              "<div class='jpm-column-drop-zone' data-col-index='" +
                i +
                "'></div>"
            )
          );
        }
        return;
      }

      // Calculate and update column width
      var colWidth = calculateColumnWidth(fieldCount);
      columns.each(function (index) {
        var $fieldEditor = $(this).find(".jpm-field-editor");
        $fieldEditor.find(".jpm-field-column-width").val(colWidth);
        var colText = colWidth == 12 ? "Full" : colWidth + " cols";
        $fieldEditor.find(".jpm-field-column-badge").text(colText);
      });

      // Update column indices
      columns.each(function (index) {
        $(this).attr("data-col-index", index);
      });

      // Remove empty drop zones and ensure max 3 columns
      $row.find(".jpm-column-drop-zone").remove();
      for (var i = fieldCount; i < 3; i++) {
        $row.append(
          $(
            "<div class='jpm-column-drop-zone' data-col-index='" +
              i +
              "'></div>"
          )
        );
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

    $(".jpm-form-column, .jpm-column-drop-zone").each(function () {
      if ($(this).data("ui-droppable")) {
        $(this).droppable("destroy");
      }
    });

    // Make each field individually draggable
    $(".jpm-field-editor").each(function () {
      var $field = $(this);

      $field.draggable({
        handle: ".jpm-field-handle",
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

    // Make columns droppable - initialize each individually
    $(".jpm-form-column, .jpm-column-drop-zone").each(function () {
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
          var $targetRow = $target.closest(".jpm-form-row");
          var $sourceColumn = $draggedField.closest(".jpm-form-column");
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

          // If dropped on drop zone, create new column
          if ($target.hasClass("jpm-column-drop-zone")) {
            var colIndex = parseInt($target.attr("data-col-index")) || 0;
            var $column = $(
              "<div class='jpm-form-column' data-col-index='" +
                colIndex +
                "'></div>"
            );

            // Find and remove the original field from its source location
            if ($sourceColumn.length > 0 && fieldIndex) {
              var $originalField = $sourceColumn.find(
                ".jpm-field-editor[data-index='" + fieldIndex + "']"
              );
              if ($originalField.length > 0) {
                $originalField.remove();
              }
            }

            // Restore opacity and move the field to new location
            $draggedField.css("opacity", "1");
            $draggedField.appendTo($column);
            $target.replaceWith($column);

            // Clean up source column if empty
            if ($sourceColumn.length > 0) {
              var $remainingFields = $sourceColumn.find(".jpm-field-editor");
              if ($remainingFields.length === 0) {
                var $row = $sourceColumn.closest(".jpm-form-row");
                var sourceColIndex = $sourceColumn.attr("data-col-index");
                var $dropZone = $(
                  "<div class='jpm-column-drop-zone' data-col-index='" +
                    sourceColIndex +
                    "'></div>"
                );
                $sourceColumn.replaceWith($dropZone);
              }
            }
          } else if ($target.hasClass("jpm-form-column")) {
            // If target column has a field, swap them
            var $existingField = $target.find(".jpm-field-editor");
            if (
              $existingField.length > 0 &&
              !$existingField.is($draggedField)
            ) {
              // Move existing field to source position
              if ($sourceColumn.length > 0) {
                // Find and remove the original dragged field from source
                if (fieldIndex) {
                  var $originalField = $sourceColumn.find(
                    ".jpm-field-editor[data-index='" + fieldIndex + "']"
                  );
                  if ($originalField.length > 0) {
                    $originalField.remove();
                  }
                }

                // Move existing field to source
                $existingField.appendTo($sourceColumn);
                // Move dragged field to target
                $draggedField.css("opacity", "1");
                $draggedField.appendTo($target);
              } else {
                // Source was in a column that doesn't exist anymore, create drop zone
                if ($sourceRow.length > 0) {
                  // Find and remove the original dragged field from source
                  if (fieldIndex) {
                    var $originalField = $sourceRow.find(
                      ".jpm-field-editor[data-index='" + fieldIndex + "']"
                    );
                    if ($originalField.length > 0) {
                      $originalField.remove();
                    }
                  }

                  // Create new column for existing field
                  var $newColumn = $(
                    "<div class='jpm-form-column' data-col-index='0'></div>"
                  );
                  $existingField.appendTo($newColumn);
                  $sourceRow.prepend($newColumn);
                  // Move dragged field to target
                  $draggedField.css("opacity", "1");
                  $draggedField.appendTo($target);
                }
              }
            } else if ($sourceColumn.length > 0 && $sourceColumn.is($target)) {
              // Dropped on itself, do nothing
              $draggedField.css("opacity", "1");
              return;
            } else {
              // Target column is empty, just move the field
              // Find and remove the original field from source
              if ($sourceColumn.length > 0 && fieldIndex) {
                var $originalField = $sourceColumn.find(
                  ".jpm-field-editor[data-index='" + fieldIndex + "']"
                );
                if ($originalField.length > 0) {
                  $originalField.remove();
                }
              }

              $draggedField.css("opacity", "1");
              $draggedField.appendTo($target);
            }

            // Clean up source column if empty
            if ($sourceColumn.length > 0 && !$sourceColumn.is($target)) {
              var $remainingFields = $sourceColumn.find(".jpm-field-editor");
              if ($remainingFields.length === 0) {
                var $row = $sourceColumn.closest(".jpm-form-row");
                var sourceColIndex = $sourceColumn.attr("data-col-index");
                var $dropZone = $(
                  "<div class='jpm-column-drop-zone' data-col-index='" +
                    sourceColIndex +
                    "'></div>"
                );
                $sourceColumn.replaceWith($dropZone);
              }
            }
          }

          // Remove empty columns (but keep drop zones)
          $(".jpm-form-column").each(function () {
            if ($(this).find(".jpm-field-editor").length === 0) {
              var $row = $(this).closest(".jpm-form-row");
              var colIndex = $(this).attr("data-col-index");
              var $dropZone = $(
                "<div class='jpm-column-drop-zone' data-col-index='" +
                  colIndex +
                  "'></div>"
              );
              $(this).replaceWith($dropZone);
            }
          });

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
          column_width: $field.find(".jpm-field-column-width").val() || "12",
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
