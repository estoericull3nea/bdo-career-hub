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
      var $column = $fieldEditor.closest(".jpm-form-column");

      $fieldEditor.fadeOut(300, function () {
        $fieldEditor.remove();

        // Remove column wrapper if it exists
        if ($column.length > 0) {
          $column.remove();
        }

        // Check if row still has columns
        var $remainingColumns = $row.find(".jpm-form-column");
        if ($remainingColumns.length === 0) {
          $row.removeClass("jpm-row-has-columns");
        }

        // Remove row if empty
        if ($row.find(".jpm-field-editor").length === 0) {
          $row.remove();
        }

        reorganizeRows();
        updateFormFields();
        initializeSortable();
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
      var $columns = $row.find(".jpm-form-column");
      var $fields = $row.find(".jpm-field-editor");

      // If row has columns, calculate widths
      if ($columns.length > 0) {
        var fieldCount = $columns.length;
        var colWidth = calculateColumnWidth(fieldCount);

        $columns.each(function () {
          var $fieldEditor = $(this).find(".jpm-field-editor");
          if ($fieldEditor.length > 0) {
            $fieldEditor.find(".jpm-field-column-width").val(colWidth);
            var colText = colWidth == 12 ? "Full" : colWidth + " cols";
            $fieldEditor.find(".jpm-field-column-badge").text(colText);
          }
        });
      } else if ($fields.length > 0) {
        // Single field, no columns
        $fields.each(function () {
          var $fieldEditor = $(this);
          $fieldEditor.find(".jpm-field-column-width").val(12);
          $fieldEditor.find(".jpm-field-column-badge").text("Full");
        });
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

    $(".jpm-form-row, .jpm-form-column").each(function () {
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

    // Make rows and columns droppable - initialize each individually
    $(".jpm-form-row, .jpm-form-column").each(function () {
      var $target = $(this);
      $target.droppable({
        accept: ".jpm-field-editor",
        hoverClass: "jpm-drop-hover",
        tolerance: "pointer",
        greedy: false,
        activeClass: "jpm-drop-active",
        over: function (event, ui) {
          var $target = $(this);
          var $targetRow = $target.hasClass("jpm-form-row")
            ? $target
            : $target.closest(".jpm-form-row");
          var $targetColumn = $target.hasClass("jpm-form-column")
            ? $target
            : null;

          // Get mouse position relative to the target
          var targetOffset = $target.offset();
          var targetWidth = $target.width();
          var targetHeight = $target.height();
          var mouseX = event.pageX - targetOffset.left;
          var mouseY = event.pageY - targetOffset.top;

          // Calculate zones
          var topThreshold = targetHeight * 0.3;
          var bottomThreshold = targetHeight * 0.7;
          var leftThreshold = targetWidth * 0.3;
          var rightThreshold = targetWidth * 0.7;

          var isInTopZone = mouseY < topThreshold;
          var isInBottomZone = mouseY > bottomThreshold;
          var isInLeftZone = mouseX < leftThreshold;
          var isInRightZone = mouseX > rightThreshold;
          var isInMiddleZone = !isInTopZone && !isInBottomZone;

          // Remove all position classes first
          $target.removeClass(
            "jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
          );

          // Add visual feedback based on position
          if (isInTopZone) {
            $target.addClass("jpm-drop-hover jpm-drop-top");
          } else if (isInBottomZone) {
            $target.addClass("jpm-drop-hover jpm-drop-bottom");
          } else if (isInMiddleZone) {
            $target.addClass("jpm-drop-hover jpm-drop-middle");
            if (isInLeftZone) {
              $target.addClass("jpm-drop-left");
            } else if (isInRightZone) {
              $target.addClass("jpm-drop-right");
            }
          }
        },
        out: function (event, ui) {
          // Remove all hover and position classes when leaving
          $(this).removeClass(
            "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
          );
        },
        drop: function (event, ui) {
          var $draggedField = ui.draggable;
          var $target = $(this);
          var $targetRow = $target.hasClass("jpm-form-row")
            ? $target
            : $target.closest(".jpm-form-row");
          var $targetColumn = $target.hasClass("jpm-form-column")
            ? $target
            : null;
          var $sourceRow = $draggedField.closest(".jpm-form-row");
          var $sourceColumn = $draggedField.closest(".jpm-form-column");

          // Prevent default behavior and stop propagation
          event.stopPropagation();
          event.preventDefault();

          // Mark helper as dropped to prevent revert
          if (ui.helper) {
            ui.helper.data("dropped", true);
          }

          // Store field index for later removal
          var fieldIndex = $draggedField.attr("data-index");

          // If dropped on the same row, check if we should create columns or reorder
          if ($sourceRow.is($targetRow)) {
            var $existingField = $targetRow.find(".jpm-field-editor");
            if (
              $existingField.length > 0 &&
              !$existingField.is($draggedField)
            ) {
              // Get mouse position relative to the row
              var rowOffset = $targetRow.offset();
              var rowWidth = $targetRow.width();
              var rowHeight = $targetRow.height();
              var mouseX = event.pageX - rowOffset.left;
              var mouseY = event.pageY - rowOffset.top;

              // Determine if dropped on top or bottom half
              var isTop = mouseY < rowHeight / 2;
              // Determine if dropped on left or right half
              var isLeft = mouseX < rowWidth / 2;

              // Calculate vertical threshold (top 30% or bottom 30% = new row, middle 40% = columns)
              var topThreshold = rowHeight * 0.3;
              var bottomThreshold = rowHeight * 0.7;
              var isInTopZone = mouseY < topThreshold;
              var isInBottomZone = mouseY > bottomThreshold;

              // If dropped in top or bottom zone, create new row above/below
              if (isInTopZone || isInBottomZone) {
                // Create new row
                var rowIndex = $targetRow.index();
                var $newRow = $(
                  "<div class='jpm-form-row' data-row-index='" +
                    rowIndex +
                    "'></div>"
                );

                // Wrap dragged field if needed
                if ($draggedField.parent().hasClass("jpm-form-column")) {
                  $draggedField.unwrap();
                }

                $draggedField.appendTo($newRow);

                // Insert row based on position
                if (isInTopZone) {
                  $targetRow.before($newRow);
                } else {
                  $targetRow.after($newRow);
                }

                $draggedField.css("opacity", "1");
              } else {
                // Middle zone - create columns for left/right positioning
                // Create column wrapper if it doesn't exist
                if (!$targetRow.hasClass("jpm-row-has-columns")) {
                  $targetRow.addClass("jpm-row-has-columns");
                  $existingField.wrap("<div class='jpm-form-column'></div>");
                }

                // Wrap dragged field in column
                if (!$draggedField.parent().hasClass("jpm-form-column")) {
                  $draggedField.wrap("<div class='jpm-form-column'></div>");
                }

                // Insert based on position
                if (isLeft) {
                  $draggedField.parent().insertBefore($existingField.parent());
                } else {
                  $draggedField.parent().insertAfter($existingField.parent());
                }

                $draggedField.css("opacity", "1");
              }
            } else {
              $draggedField.css("opacity", "1");
            }
            // Remove all position classes and labels
            $(".jpm-form-row, .jpm-form-column").removeClass(
              "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
            );

            reorganizeRows();
            updateFormFields();
            setTimeout(function () {
              initializeSortable();
            }, 100);
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

          // If dropped on a column, add to that column's row
          if ($targetColumn && $targetColumn.length > 0) {
            // Get mouse position relative to the column
            var colOffset = $targetColumn.offset();
            var colWidth = $targetColumn.width();
            var colHeight = $targetColumn.height();
            var mouseX = event.pageX - colOffset.left;
            var mouseY = event.pageY - colOffset.top;

            // Determine if dropped on top or bottom half
            var isTop = mouseY < colHeight / 2;
            // Determine if dropped on left or right half
            var isLeft = mouseX < colWidth / 2;

            // Calculate vertical threshold (top 30% or bottom 30% = new row, middle 40% = columns)
            var topThreshold = colHeight * 0.3;
            var bottomThreshold = colHeight * 0.7;
            var isInTopZone = mouseY < topThreshold;
            var isInBottomZone = mouseY > bottomThreshold;

            // If dropped in top or bottom zone, create new row above/below
            if (isInTopZone || isInBottomZone) {
              // Create new row
              var rowIndex = $targetRow.index();
              var $newRow = $(
                "<div class='jpm-form-row' data-row-index='" +
                  rowIndex +
                  "'></div>"
              );

              // Wrap dragged field if needed
              if ($draggedField.parent().hasClass("jpm-form-column")) {
                $draggedField.unwrap();
              }

              $draggedField.appendTo($newRow);

              // Insert row based on position
              if (isInTopZone) {
                $targetRow.before($newRow);
              } else {
                $targetRow.after($newRow);
              }

              $draggedField.css("opacity", "1");
            } else {
              // Middle zone - create columns for left/right positioning
              // Wrap dragged field in column if not already
              if (!$draggedField.parent().hasClass("jpm-form-column")) {
                $draggedField.wrap("<div class='jpm-form-column'></div>");
              }

              // Insert based on position
              if (isLeft) {
                $draggedField.parent().insertBefore($targetColumn);
              } else {
                $draggedField.parent().insertAfter($targetColumn);
              }

              // Ensure row has columns class
              $targetRow.addClass("jpm-row-has-columns");
              $draggedField.css("opacity", "1");
            }
          } else {
            // If target row has a field, check position
            var $existingField = $targetRow.find(".jpm-field-editor");
            if (
              $existingField.length > 0 &&
              !$existingField.is($draggedField)
            ) {
              // Get mouse position relative to the row
              var rowOffset = $targetRow.offset();
              var rowWidth = $targetRow.width();
              var rowHeight = $targetRow.height();
              var mouseX = event.pageX - rowOffset.left;
              var mouseY = event.pageY - rowOffset.top;

              // Determine if dropped on top or bottom half
              var isTop = mouseY < rowHeight / 2;
              // Determine if dropped on left or right half
              var isLeft = mouseX < rowWidth / 2;

              // Calculate vertical threshold (top 30% or bottom 30% = new row, middle 40% = columns)
              var topThreshold = rowHeight * 0.3;
              var bottomThreshold = rowHeight * 0.7;
              var isInTopZone = mouseY < topThreshold;
              var isInBottomZone = mouseY > bottomThreshold;

              // If dropped in top or bottom zone, create new row above/below
              if (isInTopZone || isInBottomZone) {
                // Create new row
                var rowIndex = $targetRow.index();
                var $newRow = $(
                  "<div class='jpm-form-row' data-row-index='" +
                    rowIndex +
                    "'></div>"
                );

                // Wrap dragged field if needed
                if ($draggedField.parent().hasClass("jpm-form-column")) {
                  $draggedField.unwrap();
                }

                $draggedField.appendTo($newRow);

                // Insert row based on position
                if (isInTopZone) {
                  $targetRow.before($newRow);
                } else {
                  $targetRow.after($newRow);
                }

                $draggedField.css("opacity", "1");
              } else {
                // Middle zone - create columns for left/right positioning
                // Create column wrapper if it doesn't exist
                if (!$targetRow.hasClass("jpm-row-has-columns")) {
                  $targetRow.addClass("jpm-row-has-columns");
                  $existingField.wrap("<div class='jpm-form-column'></div>");
                }

                // Wrap dragged field in column
                $draggedField.wrap("<div class='jpm-form-column'></div>");

                // Insert based on position
                if (isLeft) {
                  $draggedField.parent().insertBefore($existingField.parent());
                } else {
                  $draggedField.parent().insertAfter($existingField.parent());
                }

                $draggedField.css("opacity", "1");
              }
            } else {
              // Target row is empty, just add the field
              $draggedField.css("opacity", "1");
              $draggedField.appendTo($targetRow);
            }
          }

          // Clean up empty source row
          if ($sourceRow.length > 0 && !$sourceRow.is($targetRow)) {
            var $remainingFields = $sourceRow.find(".jpm-field-editor");
            if ($remainingFields.length === 0) {
              $sourceRow.remove();
            }
          }

          // Remove all position classes and labels from all rows and columns
          $(".jpm-form-row, .jpm-form-column").removeClass(
            "jpm-drop-hover jpm-drop-top jpm-drop-bottom jpm-drop-left jpm-drop-right jpm-drop-middle"
          );

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
